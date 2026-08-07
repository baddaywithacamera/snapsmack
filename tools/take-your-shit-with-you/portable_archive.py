"""
TAKE YOUR SHIT WITH YOU — the canonical portable archive.

Spec: _spec/take-your-shit-with-you-spec-v0_1.md section 7.

This module owns the OUTPUT FORMAT. The archive is the authoritative artifact;
WordPress and any other destination files are derived courtesy output that can
always be regenerated from it. So this format is the thing to get right, and the
thing not to churn: a v1 archive written today should still open in five years.

Two rules carry most of the weight.

1. NOTHING IS SILENTLY DISCARDED (spec 7.2). A sidecar maps the fields it
   understands into stable, portable names, and every remaining field from the
   source record lands in a namespaced `snapsmack` object. The server side keeps
   the export honest with per-type column allowlists; this is the same principle
   pointing the other way — the server decides what may leave, and once it has
   left, nothing gets dropped on the floor here for being unrecognised.

2. THE ARCHIVE STAYS A FOLDER. It is usable with a file manager and a text
   editor, no tooling required. Compression is optional, local, and only after
   verification.

Nothing in here talks to the network. It takes records that the API already
returned and writes files. That separation is deliberate: it makes the format
testable without a server, and it keeps download/resume logic out of the format.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import json
import os
import re
from datetime import datetime, timezone

FORMAT           = 'snapsmack-portable'
FORMAT_VERSION   = 1
SCHEMA_VERSION   = 1

# Directory layout (spec 7). Kept as data so the writer and the tests agree.
DIRS = [
    'content/posts', 'content/images', 'content/pages', 'content/comments',
    'content/relationships',
    'media/originals', 'media/web', 'media/optional',
    'indexes', 'courtesy', 'logs', 'schema',
]

# The JSON Schema ships INSIDE the archive as well as with the application
# (spec 16), so an archive found on a drive in ten years can still be validated
# by someone who has never heard of SnapSmack.
SCHEMA_FILE = 'snapsmack-portable-v1.schema.json'


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _iso(value):
    """
    Normalise a timestamp to ISO 8601 UTC, or None.

    Returns None rather than guessing when the value is unparseable — an explicit
    null is honest, a fabricated date is not, and a wrong date silently reorders
    somebody's archive.
    """
    if value in (None, '', '0000-00-00 00:00:00'):
        return None
    if isinstance(value, datetime):
        dt = value if value.tzinfo else value.replace(tzinfo=timezone.utc)
        return dt.astimezone(timezone.utc).isoformat()
    s = str(value).strip()
    for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%dT%H:%M:%S', '%Y-%m-%d'):
        try:
            return datetime.strptime(s, fmt).replace(tzinfo=timezone.utc).isoformat()
        except ValueError:
            continue
    return None


def _slug(text, fallback='item'):
    """Filesystem-safe slug for a sidecar filename. ASCII-only on purpose: these
    names have to survive being copied between Windows, macOS and Linux."""
    s = re.sub(r'[^a-zA-Z0-9]+', '-', str(text or '')).strip('-').lower()
    return (s or fallback)[:60]


def sidecar_name(source_id, slug_source, fallback='item'):
    """`000123-image-slug.json` — zero-padded so a directory listing sorts in
    source order, which is the order a human expects to read them in."""
    return f"{int(source_id):06d}-{_slug(slug_source, fallback)}.json"


def sha256_file(path, chunk=1 << 20):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for block in iter(lambda: f.read(chunk), b''):
            h.update(block)
    return h.hexdigest()


def sha256_text(text):
    return hashlib.sha256(text.encode('utf-8')).hexdigest()


def _remainder(record, consumed):
    """Everything the mapper did not use, so nothing is lost (spec 7.2)."""
    return {k: v for k, v in record.items() if k not in consumed}


# ---------------------------------------------------------------------------
# Sidecar builders
#
# One per logical post and per independent image record (spec 7.2). The mode
# mapping in spec 8 is honoured rather than flattened: a carousel is not turned
# into unrelated posts, and a photoblog image record stays the primary item.
# ---------------------------------------------------------------------------

def build_image_sidecar(record, *, site, media_paths=None, tags=None,
                        categories=None, albums=None, collections=None,
                        comments=None, warnings=None):
    """
    SMACKONEOUT: one image record is one primary published item (spec 8), so an
    image sidecar is self-contained — title, description, dates, EXIF, memberships
    and media all hang off it. In GRAMOFSMACK the same sidecar is still written
    for every child image; it is the post sidecar that owns the order.
    """
    consumed = {
        'id', 'img_title', 'img_slug', 'img_description', 'img_date', 'img_file',
        'img_status', 'img_width', 'img_height', 'img_orientation', 'img_exif',
        'img_license', 'img_source_url', 'modified_at', 'is_sensitive',
        'content_warning', 'img_thumb_square', 'img_thumb_aspect',
    }
    exif = record.get('img_exif')
    if isinstance(exif, str) and exif.strip():
        try:
            exif = json.loads(exif)
        except ValueError:
            exif = {'_unparsed': exif}
    elif not isinstance(exif, dict):
        exif = None

    return {
        'schema_version': SCHEMA_VERSION,
        'source': {
            'site_uuid': site.get('site_uuid'),
            'site_mode': site.get('site_mode'),
            'type': 'image',
            'id': int(record.get('id') or 0),
            'url': record.get('img_source_url') or None,
        },
        'title':       record.get('img_title') or None,
        'slug':        record.get('img_slug') or None,
        'description': record.get('img_description') or None,
        'status':      record.get('img_status') or None,
        'sensitive':   bool(record.get('is_sensitive')),
        'content_warning': record.get('content_warning') or None,
        'dates': {
            'created':  _iso(record.get('img_date')),
            'modified': _iso(record.get('modified_at')),
        },
        'media': media_paths or [],
        'dimensions': {
            'width':  int(record.get('img_width') or 0) or None,
            'height': int(record.get('img_height') or 0) or None,
            'orientation': record.get('img_orientation'),
        },
        # EXIF is preserved deliberately, GPS included — the tool does not decide
        # what metadata an owner's archive keeps (SECAUDIT 040 section 6).
        'exif': exif,
        'license': record.get('img_license') or None,
        'tags': list(tags or []),
        'categories':  list(categories or []),
        'albums':      list(albums or []),
        'collections': list(collections or []),
        'comments':    list(comments or []),
        'warnings':    list(warnings or []),
        'snapsmack':   _remainder(record, consumed),
    }


def build_post_sidecar(record, *, site, images=None, tags=None, categories=None,
                       collections=None, comments=None, warnings=None):
    """
    GRAMOFSMACK: the post owns an ORDERED image array and carousel order is sacred
    (spec 8). `images` must already be sorted by sort_position, and each entry
    carries its position and framing so the arrangement can be rebuilt, not
    guessed at. SMACKTALK keeps its body and inline placement references intact.
    """
    consumed = {
        'id', 'title', 'slug', 'description', 'content', 'post_type', 'status',
        'created_at', 'updated_at', 'is_sensitive', 'content_warning',
        'featured_image_id', 'trigram_id',
    }
    return {
        'schema_version': SCHEMA_VERSION,
        'source': {
            'site_uuid': site.get('site_uuid'),
            'site_mode': site.get('site_mode'),
            'type': 'post',
            'id': int(record.get('id') or 0),
            'url': None,
        },
        'title':       record.get('title') or None,
        'slug':        record.get('slug') or None,
        'description': record.get('description') or None,
        'body':        record.get('content') or None,
        'post_type':   record.get('post_type') or None,
        'status':      record.get('status') or None,
        'sensitive':   bool(record.get('is_sensitive')),
        'content_warning': record.get('content_warning') or None,
        'dates': {
            'created':  _iso(record.get('created_at')),
            'modified': _iso(record.get('updated_at')),
        },
        'cover_image_id': record.get('featured_image_id') or None,
        'trigram_id':     record.get('trigram_id') or None,
        'images':      list(images or []),      # ORDERED — do not re-sort
        'tags':        list(tags or []),
        'categories':  list(categories or []),
        'collections': list(collections or []),
        'comments':    list(comments or []),
        'warnings':    list(warnings or []),
        'snapsmack':   _remainder(record, consumed),
    }


def build_image_reference(post_image_row, *, image_sidecar_path, media_path=None):
    """
    One entry in a post's ordered image array. Carries the positioning and framing
    from snap_post_images so a carousel can be reproduced rather than approximated.
    """
    consumed = {'id', 'post_id', 'image_id', 'sort_position', 'is_cover'}
    return {
        'image_id':  int(post_image_row.get('image_id') or 0),
        'position':  int(post_image_row.get('sort_position') or 0),
        'is_cover':  bool(post_image_row.get('is_cover')),
        'sidecar':   image_sidecar_path,
        'media':     media_path,
        'presentation': _remainder(post_image_row, consumed),
    }


# ---------------------------------------------------------------------------
# Archive writer
# ---------------------------------------------------------------------------

class PortableArchive:
    """
    Writes the canonical archive and keeps a running inventory with a SHA-256 for
    every file it produces. The inventory is what `verification.json` is built
    from, and what proves a finished archive is complete rather than merely
    finished.
    """

    def __init__(self, root, site, *, app_version='0.1', export_uuid=None,
                 started_at=None):
        self.root  = os.path.abspath(root)
        self.site  = dict(site or {})
        self.app_version = app_version
        self.export_uuid = export_uuid or hashlib.sha256(
            (self.root + str(started_at)).encode('utf-8')).hexdigest()[:32]
        self.started_at = started_at
        self.inventory  = {}     # relative path -> {sha256, bytes}
        self.warnings   = []
        self.exclusions = []
        self.counts     = {}

    # -- filesystem ---------------------------------------------------------
    def create(self):
        for d in DIRS:
            os.makedirs(os.path.join(self.root, d), exist_ok=True)
        return self

    def _rel(self, path):
        return os.path.relpath(path, self.root).replace('\\', '/')

    def _record(self, abs_path):
        rel = self._rel(abs_path)
        self.inventory[rel] = {
            'sha256': sha256_file(abs_path),
            'bytes':  os.path.getsize(abs_path),
        }
        return rel

    def write_json(self, rel_path, data):
        """Pretty-printed UTF-8, trailing newline, stable key order — the archive
        is meant to be read by a human and diffed by a machine."""
        abs_path = os.path.join(self.root, rel_path)
        os.makedirs(os.path.dirname(abs_path), exist_ok=True)
        text = json.dumps(data, indent=2, ensure_ascii=False, sort_keys=True) + '\n'
        with open(abs_path, 'w', encoding='utf-8', newline='\n') as f:
            f.write(text)
        return self._record(abs_path)

    def write_text(self, rel_path, text):
        abs_path = os.path.join(self.root, rel_path)
        os.makedirs(os.path.dirname(abs_path), exist_ok=True)
        with open(abs_path, 'w', encoding='utf-8', newline='\n') as f:
            f.write(text)
        return self._record(abs_path)

    def register_media(self, abs_path, sha256=None, bytes_=None):
        """
        Record a media file that was downloaded into the archive.

        The downloader already hashed the bytes as they arrived, so it passes the
        digest in rather than making us read a 40 GB library a second time. When
        no digest is supplied the file is hashed here.
        """
        if sha256 is None:
            return self._record(abs_path)
        rel = self._rel(abs_path)
        self.inventory[rel] = {
            'sha256': sha256,
            'bytes':  int(bytes_ if bytes_ is not None else os.path.getsize(abs_path)),
        }
        return rel

    def write_schema(self, schema_text=None):
        """
        Put the portable JSON Schema in the archive (spec 16).

        Reads the copy that ships beside this module unless one is passed in.
        A missing schema is a warning, not a failure — the archive is still
        readable, it just cannot be machine-validated without fetching the file.
        """
        if schema_text is None:
            src = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                               'schema', SCHEMA_FILE)
            try:
                with open(src, encoding='utf-8') as f:
                    schema_text = f.read()
            except OSError:
                self.warn('The portable JSON Schema could not be copied into the '
                          'archive. The archive is complete and readable; it just '
                          'has no machine-checkable schema beside it.')
                return None
        return self.write_text(f'schema/{SCHEMA_FILE}', schema_text)

    def warn(self, message):
        self.warnings.append(message)

    def exclude(self, klass):
        if klass not in self.exclusions:
            self.exclusions.append(klass)

    # -- top-level documents ------------------------------------------------
    def write_site(self, settings_public):
        return self.write_json('site.json', {
            'schema_version': SCHEMA_VERSION,
            'site_uuid': self.site.get('site_uuid'),
            'name':      self.site.get('site_name'),
            'url':       self.site.get('site_url'),
            'mode':      self.site.get('site_mode'),
            'snapsmack_version': self.site.get('site_version'),
            'settings_public': dict(settings_public or {}),
        })

    def write_manifest(self, *, snapshot=None, completed=True, adapters=None,
                       generated_at=None):
        media = {k: v for k, v in self.inventory.items() if k.startswith('media/')}
        manifest = {
            'format':         FORMAT,
            'format_version': FORMAT_VERSION,
            'app_version':    self.app_version,
            'export_uuid':    self.export_uuid,
            'site': {
                'uuid': self.site.get('site_uuid'),
                'name': self.site.get('site_name'),
                'url':  self.site.get('site_url'),
                'mode': self.site.get('site_mode'),
                'snapsmack_version': self.site.get('site_version'),
            },
            'snapshot':     snapshot or {},
            'generated_at': generated_at,
            'counts':       dict(self.counts),
            'media': {
                'files': len(media),
                'bytes': sum(m['bytes'] for m in media.values()),
            },
            'files':      dict(sorted(self.inventory.items())),
            'completed':  bool(completed),
            'warnings':   list(self.warnings),
            'exclusions': list(self.exclusions),
            'adapters':   dict(adapters or {}),
        }
        # The manifest never contains authentication keys or credentials (spec 7.1).
        # Asserted rather than assumed: this object is assembled from several
        # places and is the file most likely to be shared with a third party.
        _assert_no_secrets(manifest)
        return self.write_json('manifest.json', manifest)

    def write_verification(self, *, expected_counts, source_watermarks=None):
        """
        The completed local ledger against the declared source snapshot. Kept
        separate from the manifest so verification can be re-run and rewritten
        without touching the archive's identity.
        """
        actual = dict(self.counts)
        mismatches = {
            t: {'expected': int(n), 'actual': int(actual.get(t, 0))}
            for t, n in (expected_counts or {}).items()
            if int(actual.get(t, 0)) != int(n)
        }
        return self.write_json('verification.json', {
            'schema_version': SCHEMA_VERSION,
            'export_uuid':    self.export_uuid,
            'expected_counts': dict(expected_counts or {}),
            'actual_counts':   actual,
            'mismatches':      mismatches,
            'complete':        not mismatches,
            'source_watermarks': dict(source_watermarks or {}),
            'file_count':  len(self.inventory),
            'total_bytes': sum(v['bytes'] for v in self.inventory.values()),
        })

    def write_readme(self):
        name = self.site.get('site_name') or self.site.get('site_url') or 'your site'
        return self.write_text('README.txt', f"""\
TAKE YOUR SHIT WITH YOU
=======================

This is a complete portable copy of the content from {name}.

It is a plain folder. You do not need any SnapSmack software, any database, or
any of our tools to read it. Open it with a file manager and a text editor.

  content/    one JSON file per post and per image, in readable form
  media/      your actual photographs, at the best size the site had
  indexes/    albums, categories, collections and the blogroll
  courtesy/   optional files for importing into other systems
  schema/     the machine-readable description of the JSON files
  logs/       what happened during the export

manifest.json lists every file with a SHA-256 checksum.
verification.json records what was expected against what actually arrived.

Comments appear TWICE on purpose: inside the post or image they belong to, so
one file tells you everything about one photograph, and again grouped by thread
in content/comments/, so you can read the conversation without opening every
photograph. They are the same comments.

WHAT IS HERE: your photographs and their originals where available, titles,
captions, descriptions, tags, dates, EXIF (including location where your camera
or the site recorded it), albums, categories, collections, pages, comments and
their moderation state, reactions, and your blogroll.

WHAT IS NOT HERE, deliberately: passwords, two-factor secrets, API keys, signing
keys, sessions, and visitors' IP addresses. None of that is yours to carry and
none of it means anything outside the site it came from.

No hard feelings. Here's your hat--what's your hurry?
""")


def _assert_no_secrets(obj, _path='manifest'):
    """
    Walk a structure and refuse anything that looks like a credential.

    Cheap, and it fails loudly at write time rather than quietly shipping a key
    inside an archive somebody then emails to a migration consultant.
    """
    bad = re.compile(r'(password|passwd|api[_-]?key|secret|token|totp|private[_-]?key'
                     r'|signing|credential|bearer)', re.I)
    if isinstance(obj, dict):
        for k, v in obj.items():
            if bad.search(str(k)):
                raise ValueError(f'refusing to write a credential-shaped field '
                                 f'into {_path}: {k}')
            _assert_no_secrets(v, f'{_path}.{k}')
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            _assert_no_secrets(v, f'{_path}[{i}]')
# ===== SNAPSMACK EOF =====
