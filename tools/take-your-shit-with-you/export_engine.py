"""
TAKE YOUR SHIT WITH YOU — the export orchestrator.

Spec: _spec/take-your-shit-with-you-spec-v0_1.md sections 8, 9, 12.

Everything expensive happens here, on the owner's computer, because section 2
says it must: the server streams bounded chunks and single files, and this
module does the joining, hashing, writing, verifying and packaging.

The run is in two passes, and the split is not cosmetic.

  PASS 1 — COLLECT. Every record type is streamed and appended verbatim to
  `.tyswy/raw/<type>.ndjson`, one verified chunk at a time. Nothing is
  interpreted yet. This pass is the resumable one, and it is resumable precisely
  because it does nothing clever: a chunk either verified and was appended, or
  it did not and was not.

  PASS 2 — ASSEMBLE. The raw files are read back, joined, and written out as
  sidecars, indexes and a manifest. This pass is cheap to redo from scratch,
  so it does not need its own checkpointing — which means the complicated part
  of the program and the restartable part of the program are not the same part.

MODE MAPPING (spec 8) is honoured rather than normalised. A GRAMOFSMACK carousel
stays one post owning an ordered image array; a SMACKONEOUT image stays a primary
item in its own right; SMACKTALK bodies keep their inline references. Nothing
here converts one mode into another — that is explicitly not this product.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
import re
import shutil
import sys
import threading
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone

import portable_archive as pa
from export_state import ExportState, ResumeRefused
from tyswy_client import TyswyClient, TyswyError, VerificationError

# Shared path containment (tools/_shared/snap_paths.py). Same bootstrap as
# config.py: in dev it is one directory up in _shared/, and build.bat passes
# --paths ..\_shared --hidden-import snap_paths so the frozen exe can find it.
_SHARED_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
if os.path.isdir(_SHARED_DIR) and _SHARED_DIR not in sys.path:
    sys.path.insert(0, _SHARED_DIR)
from snap_paths import contained_local_path  # noqa: E402

APP_VERSION = '0.1.0'

# Streamed in this order: reference data first, so the assembly pass never has to
# guess at something it has not read yet, and so a run that dies early still has
# the cheap, small, high-value records on disk.
RECORD_TYPES = [
    'settings_public_placeholder',   # supplied by preflight, not streamed
    'categories', 'albums', 'tags', 'blogroll_categories', 'blogroll',
    'collections', 'collection_memberships', 'pages',
    'trigrams', 'mosaics', 'assets', 'stats_summary', 'follows', 'following',
    'posts', 'images', 'post_images',
    'image_category_memberships', 'image_album_memberships', 'image_tags',
    'post_category_memberships', 'post_album_memberships',
    'comments', 'image_comments', 'reactions', 'emoji_reactions',
]
STREAMED_TYPES = [t for t in RECORD_TYPES if not t.endswith('_placeholder')]

# Types whose absence on a site is ordinary rather than alarming.
OPTIONAL_TYPES = {
    'trigrams', 'mosaics', 'assets', 'stats_summary', 'follows', 'following',
    'collections', 'collection_memberships', 'emoji_reactions', 'image_comments',
    'comments', 'reactions', 'tags', 'image_tags', 'blogroll', 'blogroll_categories',
    'post_category_memberships', 'post_album_memberships',
}

WINDOWS_RESERVED = {
    'CON', 'PRN', 'AUX', 'NUL',
    *(f'COM{i}' for i in range(1, 10)),
    *(f'LPT{i}' for i in range(1, 10)),
}


class Cancelled(Exception):
    """The user pressed stop. Completed work is kept; nothing is deleted."""


class ExportOptions:

    def __init__(self, *, include_thumbnails=False, media_concurrency=2,
                 courtesy_wordpress=True, compress=False, chunk_size=500,
                 skip_media=False):
        self.include_thumbnails = bool(include_thumbnails)
        # Kind to cheap shared hosting, not a benchmark of it (spec 12).
        self.media_concurrency  = max(1, min(int(media_concurrency), 4))
        self.courtesy_wordpress = bool(courtesy_wordpress)
        self.compress           = bool(compress)
        self.chunk_size         = max(1, min(int(chunk_size), 2000))
        self.skip_media         = bool(skip_media)


# ---------------------------------------------------------------------------
# Filename safety (spec 13)
# ---------------------------------------------------------------------------

def safe_filename(name, fallback='file'):
    """
    Windows-safe, without losing the source title — the real title stays in the
    sidecar, this is only what the file is called on disk.
    """
    n = re.sub(r'[<>:"/\\|?*\x00-\x1f]', '-', str(name or ''))
    n = n.strip(' .')
    if not n:
        n = fallback
    stem = n.split('.')[0].upper()
    if stem in WINDOWS_RESERVED:
        n = '_' + n
    return n[:120]


def _ext_of(path, default='.jpg'):
    ext = os.path.splitext(str(path or ''))[1]
    if not ext or len(ext) > 12 or not re.match(r'^\.[A-Za-z0-9]+$', ext):
        return default
    return ext.lower()


def _iso_now():
    return datetime.now(timezone.utc).isoformat()


def _as_int(v, default=0):
    try:
        return int(v)
    except (TypeError, ValueError):
        return default


def _truthy(v):
    return str(v).strip().lower() not in ('', '0', 'false', 'none', 'null')


# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------

class ExportReport:

    def __init__(self):
        self.root          = ''
        self.export_uuid   = ''
        self.site          = {}
        self.counts        = {}
        self.expected      = {}
        self.mismatches    = {}
        self.media_files   = 0
        self.media_bytes   = 0
        self.media_missing = []
        self.warnings      = []
        self.exclusions    = []
        self.adapters      = {}
        self.complete      = False
        self.snapshot      = {}
        self.zip_path      = None

    @property
    def complete_with_warnings(self):
        return self.complete and bool(self.warnings)

    def to_dict(self):
        return {
            'root': self.root, 'export_uuid': self.export_uuid, 'site': self.site,
            'counts': self.counts, 'expected': self.expected,
            'mismatches': self.mismatches, 'media_files': self.media_files,
            'media_bytes': self.media_bytes, 'media_missing': self.media_missing,
            'warnings': self.warnings, 'exclusions': self.exclusions,
            'adapters': self.adapters, 'complete': self.complete,
            'snapshot': self.snapshot, 'zip_path': self.zip_path,
        }


# ---------------------------------------------------------------------------
# Engine
# ---------------------------------------------------------------------------

class ExportEngine:

    def __init__(self, client, destination, *, options=None, app_version=APP_VERSION,
                 on_progress=None, on_log=None, cancel_event=None,
                 client_factory=None):
        self.client       = client
        self.destination  = os.path.abspath(destination)
        self.options      = options or ExportOptions()
        self.app_version  = app_version
        self._on_progress = on_progress
        self._on_log      = on_log
        self.cancel       = cancel_event or threading.Event()
        # Media runs on more than one connection, and requests.Session is not
        # something to share across threads. Each worker gets its own client.
        self._client_factory = client_factory

        self.root     = None
        self.state    = None
        self.archive  = None
        self.report   = ExportReport()
        self._log_fh  = None
        self._lock    = threading.Lock()

    # -- plumbing -----------------------------------------------------------
    def log(self, message):
        line = f'{_iso_now()}  {message}'
        if self._log_fh:
            try:
                self._log_fh.write(line + '\n')
                self._log_fh.flush()
            except Exception:
                pass
        if self._on_log:
            self._on_log(message)

    def progress(self, stage, message, fraction=None):
        if self._on_progress:
            self._on_progress(stage, message, fraction)

    def _check_cancel(self):
        if self.cancel.is_set():
            raise Cancelled()

    def warn(self, message):
        with self._lock:
            if message not in self.report.warnings:
                self.report.warnings.append(message)
        if self.archive:
            self.archive.warn(message)
        self.log('WARNING: ' + message)

    # -- folder -------------------------------------------------------------
    @staticmethod
    def folder_name(site_name, when=None):
        day = (when or datetime.now(timezone.utc)).strftime('%Y-%m-%d')
        return safe_filename(f'Take Your Shit With You - {site_name or "SnapSmack site"} - {day}')

    # =======================================================================
    # RUN
    # =======================================================================
    def run(self):
        pre = self._stage_preflight()
        self._stage_open_archive(pre)
        try:
            self._stage_collect()
            self._stage_assemble(pre)
            self._stage_media()
            self._stage_indexes(pre)
            self._stage_reconcile()
            self._stage_adapters()
            self._stage_finish()
        except Cancelled:
            self.log('CANCELLED by the user. Completed work has been kept; '
                     'nothing was deleted. Point the tool at this folder again '
                     'to carry on.')
            if self.state:
                self.state.flush()
            raise
        finally:
            if self.state:
                self.state.close()
            if self._log_fh:
                try:
                    self._log_fh.close()
                except Exception:
                    pass
                self._log_fh = None
        return self.report

    # -- 1. preflight -------------------------------------------------------
    def _stage_preflight(self):
        self.progress('preflight', 'Asking the site what it has…', 0.0)
        pre = self.client.preflight()
        self.report.site = {
            'site_uuid':    pre.get('site_uuid'),
            'site_name':    pre.get('site_name'),
            'site_url':     pre.get('site_url') or self.client.site_url,
            'site_mode':    pre.get('site_mode'),
            'site_version': pre.get('site_version'),
        }
        types = pre.get('types') or {}
        self.report.expected = {t: _as_int(v.get('count'))
                                for t, v in types.items() if v.get('supported')}
        self.report.snapshot = {t: _as_int(v.get('max_id'))
                                for t, v in types.items() if v.get('supported')}
        self.report.exclusions = list(pre.get('excluded_classes') or [])
        return pre

    # -- 2. archive + state -------------------------------------------------
    def _stage_open_archive(self, pre):
        name = self.folder_name(pre.get('site_name'))
        self.root = self.destination
        # If the chosen destination is not already a TYSWY export folder, make
        # one inside it. Pointing the tool at an existing export resumes it.
        if not os.path.exists(os.path.join(self.root, '.tyswy', 'state.json')):
            self.root = os.path.join(self.destination, name)
        os.makedirs(self.root, exist_ok=True)

        existing = ExportState.load(self.root)
        if existing:
            existing.check_resumable(site_uuid=pre.get('site_uuid'),
                                     site_url=self.report.site['site_url'])
            self.state = existing
            self.log(f'Resuming export {self.state.export_uuid} in {self.root}')
        else:
            self.state = ExportState.start(
                self.root,
                site_uuid=pre.get('site_uuid'), site_url=self.report.site['site_url'],
                site_name=pre.get('site_name'), site_mode=pre.get('site_mode'),
                site_version=pre.get('site_version'), app_version=self.app_version,
                snapshot=self.report.snapshot)
            self.log(f'Started export {self.state.export_uuid} in {self.root}')

        self.archive = pa.PortableArchive(
            self.root, self.report.site, app_version=self.app_version,
            export_uuid=self.state.export_uuid, started_at=self.state.started_at).create()
        for klass in self.report.exclusions:
            self.archive.exclude(klass)

        os.makedirs(os.path.join(self.root, 'logs'), exist_ok=True)
        self._log_fh = open(os.path.join(self.root, 'logs', 'export.log'),
                            'a', encoding='utf-8', newline='\n')
        self.report.root        = self.root
        self.report.export_uuid = self.state.export_uuid

        notes = pre.get('media_variant_notes') or {}
        if notes.get('original'):
            self.log('Media note — original: ' + notes['original'])

    # -- 3. collect ---------------------------------------------------------
    def _stage_collect(self):
        self.state.set_stage('collect')
        total = len(STREAMED_TYPES)
        for i, rtype in enumerate(STREAMED_TYPES):
            self._check_cancel()
            ts = self.state.type_state(rtype)
            if ts.get('done'):
                self.progress('collect', f'{rtype}: already collected', (i + 1) / total)
                continue
            self._collect_type(rtype, i, total)
        self.state.set_stage('assemble')

    def _collect_type(self, rtype, index, total):
        ts       = self.state.type_state(rtype)
        cursor   = _as_int(ts.get('cursor'))
        snapshot = _as_int(ts.get('snapshot')) or _as_int(self.report.snapshot.get(rtype))
        fh       = self.state.open_raw_for_append(rtype)
        rows_all = _as_int(ts.get('rows'))
        try:
            while True:
                self._check_cancel()
                chunk = self.client.stream_chunk(
                    rtype, after_id=cursor, limit=self.options.chunk_size,
                    snapshot=snapshot or None)

                if not chunk.supported:
                    if rtype not in OPTIONAL_TYPES:
                        self.warn(f'This site has no {rtype} table, so none were '
                                  'exported. That is unusual — check the export '
                                  'against what you expect to see.')
                    self.state.commit_chunk(rtype, cursor=cursor, rows_added=0,
                                            byte_length=fh.tell(), done=True,
                                            supported=False, source_count=0)
                    self.log(f'{rtype}: not present on this site')
                    return

                # Written as BYTES on purpose. The resume checkpoint stores a
                # byte offset and truncates back to it, and `tell()` on a text
                # stream returns an opaque cookie rather than a byte position.
                for row in chunk.records:
                    fh.write((json.dumps(row, ensure_ascii=False,
                                         separators=(',', ':')) + '\n').encode('utf-8'))
                fh.flush()
                os.fsync(fh.fileno())

                rows_all += chunk.rows
                snapshot = snapshot or chunk.snapshot
                done     = not chunk.has_more or chunk.rows == 0 or chunk.last_id <= cursor
                self.state.commit_chunk(
                    rtype, cursor=chunk.last_id, rows_added=chunk.rows,
                    byte_length=fh.tell(),
                    chunk_hash=chunk.footer.get('stream_sha256'),
                    source_count=chunk.source_count, snapshot=snapshot,
                    done=done)

                frac = (index + min(1.0, rows_all / max(1, chunk.source_count))) / total
                self.progress('collect',
                              f'{rtype}: {rows_all:,} of {chunk.source_count:,}',
                              frac)
                if done:
                    self.log(f'{rtype}: collected {rows_all:,} rows '
                             f'(source reports {chunk.source_count:,})')
                    return
                cursor = chunk.last_id
        finally:
            fh.close()

    # -- 4. assemble --------------------------------------------------------
    def _stage_assemble(self, pre):
        self.state.set_stage('assemble')
        self.progress('assemble', 'Reading what arrived…', 0.0)

        site = self.report.site
        rec  = self._records                      # shorthand

        # Small reference tables: held in memory, they are tiny next to media.
        categories  = {r['id']: r['record'] for r in rec('categories')}
        albums      = {r['id']: r['record'] for r in rec('albums')}
        tags        = {r['id']: r['record'] for r in rec('tags')}
        collections = {r['id']: r['record'] for r in rec('collections')}
        assets      = {r['id']: r['record'] for r in rec('assets')}
        trigrams    = [r['record'] for r in rec('trigrams')]
        mosaics     = [r['record'] for r in rec('mosaics')]
        blogroll_cats = {r['id']: r['record'] for r in rec('blogroll_categories')}

        # Membership maps.
        img_cats   = defaultdict(list)
        img_albums = defaultdict(list)
        img_tags   = defaultdict(list)
        post_cats  = defaultdict(list)
        post_albums = defaultdict(list)
        for r in rec('image_category_memberships'):
            img_cats[_as_int(r['record'].get('image_id'))].append(_as_int(r['record'].get('cat_id')))
        for r in rec('image_album_memberships'):
            img_albums[_as_int(r['record'].get('image_id'))].append(_as_int(r['record'].get('album_id')))
        for r in rec('image_tags'):
            img_tags[_as_int(r['record'].get('image_id'))].append(_as_int(r['record'].get('tag_id')))
        for r in rec('post_category_memberships'):
            post_cats[_as_int(r['record'].get('post_id'))].append(_as_int(r['record'].get('cat_id')))
        for r in rec('post_album_memberships'):
            post_albums[_as_int(r['record'].get('post_id'))].append(_as_int(r['record'].get('album_id')))

        # Collection membership, both shapes the table supports.
        coll_of_image = defaultdict(list)
        coll_of_post  = defaultdict(list)
        coll_items    = defaultdict(list)
        for r in rec('collection_memberships'):
            row  = r['record']
            cid  = _as_int(row.get('collection_id'))
            kind = (row.get('item_type') or '').strip().lower()
            iid  = _as_int(row.get('item_id')) or _as_int(row.get('image_id'))
            entry = {
                'collection_id': cid,
                'title':   (collections.get(cid) or {}).get('title'),
                'position': _as_int(row.get('sort_order') or row.get('position')),
                'caption':  row.get('caption') or None,
            }
            coll_items[cid].append({'type': kind or 'image', 'id': iid, **entry})
            if kind == 'post':
                coll_of_post[iid].append(entry)
            else:
                coll_of_image[iid].append(entry)

        # Carousel membership. Ordered by sort_position — the order is read from
        # the source, never re-derived (spec 8: carousel order is sacred).
        post_images = defaultdict(list)
        image_to_post = {}
        for r in rec('post_images'):
            row = r['record']
            pid = _as_int(row.get('post_id'))
            post_images[pid].append(row)
            image_to_post[_as_int(row.get('image_id'))] = pid
        for pid in post_images:
            post_images[pid].sort(key=lambda x: (_as_int(x.get('sort_position')),
                                                 _as_int(x.get('id'))))

        # Comments, from BOTH tables.
        comments_by_image = defaultdict(list)
        comments_by_post  = defaultdict(list)
        for r in rec('image_comments'):
            row = r['record']
            c   = self._portable_image_comment(row)
            if _as_int(row.get('img_id')):
                comments_by_image[_as_int(row.get('img_id'))].append(c)
            elif _as_int(row.get('post_id')):
                comments_by_post[_as_int(row.get('post_id'))].append(c)
        for r in rec('comments'):
            row = r['record']
            comments_by_post[_as_int(row.get('post_id'))].append(
                self._portable_community_comment(row))
        for bucket in (comments_by_image, comments_by_post):
            for k in bucket:
                bucket[k].sort(key=lambda c: (c.get('date') or '', c.get('source', {}).get('id', 0)))

        # Reactions become counts. The portable fact is how many, not who.
        likes_by_post = defaultdict(int)
        for r in rec('reactions'):
            likes_by_post[_as_int(r['record'].get('post_id'))] += 1
        emoji_by_post = defaultdict(lambda: defaultdict(int))
        for r in rec('emoji_reactions'):
            row = r['record']
            emoji_by_post[_as_int(row.get('post_id'))][row.get('reaction_code') or '?'] += 1

        def cat_names(ids):
            return [categories[i]['cat_name'] for i in dict.fromkeys(ids)
                    if i in categories and categories[i].get('cat_name')]

        def album_names(ids):
            return [albums[i]['album_name'] for i in dict.fromkeys(ids)
                    if i in albums and albums[i].get('album_name')]

        def tag_names(ids):
            return [tags[i]['tag'] for i in dict.fromkeys(ids)
                    if i in tags and tags[i].get('tag')]

        # -- images ---------------------------------------------------------
        self.progress('assemble', 'Writing image files…', 0.2)
        self.media_plan   = []      # what the media stage will fetch
        self.image_index  = {}      # id -> {sidecar, media, title, slug, date}
        n_images = 0
        for r in self._records('images'):
            self._check_cancel()
            row  = r['record']
            iid  = _as_int(row.get('id'))
            slug = row.get('img_slug') or row.get('img_title') or f'image-{iid}'
            ext  = _ext_of(row.get('img_file'))
            media_rel = f'media/originals/{iid:06d}-{pa._slug(slug, "image")}{ext}'
            side_rel  = f'content/images/{pa.sidecar_name(iid, slug, "image")}'

            media_refs = [{
                'variant': 'original',
                'path':    media_rel,
                'source_filename': row.get('img_source_file') or None,
                'width':   _as_int(row.get('img_width')) or None,
                'height':  _as_int(row.get('img_height')) or None,
            }]
            if self.options.include_thumbnails and row.get('img_thumb_aspect'):
                thumb_rel = f'media/optional/thumbs/{iid:06d}-thumb{_ext_of(row.get("img_thumb_aspect"))}'
                media_refs.append({'variant': 'thumb', 'path': thumb_rel})
                self.media_plan.append(('image', iid, 'thumb', thumb_rel, slug))

            self.media_plan.append(('image', iid, 'original', media_rel, slug))

            sidecar = pa.build_image_sidecar(
                row, site=site, media_paths=media_refs,
                tags=tag_names(img_tags.get(iid, [])),
                categories=cat_names(img_cats.get(iid, [])),
                albums=album_names(img_albums.get(iid, [])),
                collections=coll_of_image.get(iid, []),
                comments=comments_by_image.get(iid, []))
            sidecar['source']['url'] = self._public_url_for_image(row)
            if image_to_post.get(iid):
                sidecar['snapsmack']['carousel_post_id'] = image_to_post[iid]
            self.archive.write_json(side_rel, sidecar)

            self.image_index[iid] = {
                'sidecar': side_rel, 'media': media_rel,
                'title': row.get('img_title') or '', 'slug': slug,
                'date': sidecar['dates']['created'],
                'status': row.get('img_status') or 'published',
            }
            n_images += 1
            if n_images % 200 == 0:
                self.progress('assemble', f'Wrote {n_images:,} image files…', 0.2)
        self.archive.counts['images'] = n_images

        # -- longform assets ------------------------------------------------
        self.asset_index = {}
        for aid, row in assets.items():
            ext = _ext_of(row.get('asset_path'))
            rel = f'media/originals/assets/{_as_int(aid):06d}-{pa._slug(row.get("asset_name"), "asset")}{ext}'
            self.asset_index[_as_int(aid)] = rel
            self.media_plan.append(('asset', _as_int(aid), 'original', rel,
                                    row.get('asset_name') or f'asset-{aid}'))
        self.archive.counts['assets'] = len(assets)

        # -- posts ----------------------------------------------------------
        self.progress('assemble', 'Writing post files…', 0.4)
        self.post_index = {}
        n_posts = 0
        trigram_by_post = {}
        for tg in trigrams:
            for slot in (1, 2, 3):
                pid = _as_int(tg.get(f'post_id_{slot}'))
                if pid:
                    trigram_by_post[pid] = {'trigram_id': _as_int(tg.get('id')),
                                            'slot': slot,
                                            'orientation': tg.get('orientation'),
                                            'cut_a': tg.get('cut_a'),
                                            'cut_b': tg.get('cut_b'),
                                            'type': tg.get('trigram_type')}
        for r in self._records('posts'):
            self._check_cancel()
            row  = r['record']
            pid  = _as_int(row.get('id'))
            slug = row.get('slug') or row.get('title') or f'post-{pid}'
            side_rel = f'content/posts/{pa.sidecar_name(pid, slug, "post")}'

            refs = []
            for pi in post_images.get(pid, []):
                iid  = _as_int(pi.get('image_id'))
                info = self.image_index.get(iid) or {}
                refs.append(pa.build_image_reference(
                    pi, image_sidecar_path=info.get('sidecar'),
                    media_path=info.get('media')))

            sidecar = pa.build_post_sidecar(
                row, site=site, images=refs,
                categories=cat_names(post_cats.get(pid, [])),
                collections=coll_of_post.get(pid, []),
                comments=comments_by_post.get(pid, []))
            sidecar['albums']    = album_names(post_albums.get(pid, []))
            sidecar['reactions'] = {
                'likes': likes_by_post.get(pid, 0),
                'emoji': dict(emoji_by_post.get(pid, {})),
            }
            if trigram_by_post.get(pid):
                sidecar['snapsmack']['trigram'] = trigram_by_post[pid]
            inline = self._inline_references(row.get('content'))
            if inline:
                sidecar['inline_media'] = inline
            self.archive.write_json(side_rel, sidecar)
            self.post_index[pid] = {
                'sidecar': side_rel, 'title': row.get('title') or '',
                'slug': slug, 'date': sidecar['dates']['created'],
                'status': row.get('status') or 'published',
                'images': [_as_int(x.get('image_id')) for x in post_images.get(pid, [])],
            }
            n_posts += 1
        self.archive.counts['posts'] = n_posts

        # -- pages ----------------------------------------------------------
        n_pages = 0
        self.page_index = {}
        for r in self._records('pages'):
            row  = r['record']
            gid  = _as_int(row.get('id'))
            slug = row.get('slug') or row.get('title') or f'page-{gid}'
            rel  = f'content/pages/{pa.sidecar_name(gid, slug, "page")}'
            self.archive.write_json(rel, {
                'schema_version': pa.SCHEMA_VERSION,
                'source': {'site_uuid': site.get('site_uuid'),
                           'site_mode': site.get('site_mode'),
                           'type': 'page', 'id': gid,
                           'url': self._public_url(f'page/{row.get("slug") or ""}')},
                'title':   row.get('title') or None,
                'slug':    row.get('slug') or None,
                'body':    row.get('content') or None,
                'status':  'published' if _truthy(row.get('is_active')) else 'hidden',
                'menu_order': _as_int(row.get('menu_order')),
                'dates':   {'created': pa._iso(row.get('created_at')), 'modified': None},
                'inline_media': self._inline_references(row.get('content')),
                'warnings': [],
                'snapsmack': {k: v for k, v in row.items()
                              if k not in ('id', 'slug', 'title', 'content',
                                           'is_active', 'menu_order', 'created_at')},
            })
            self.page_index[gid] = {'sidecar': rel, 'title': row.get('title') or '',
                                    'slug': row.get('slug') or ''}
            n_pages += 1
        self.archive.counts['pages'] = n_pages

        # -- comment threads ------------------------------------------------
        n_comments = 0
        for iid, thread in comments_by_image.items():
            if not thread:
                continue
            self.archive.write_json(
                f'content/comments/image-{iid:06d}.json',
                {'schema_version': pa.SCHEMA_VERSION, 'parent': {'type': 'image', 'id': iid},
                 'sidecar': (self.image_index.get(iid) or {}).get('sidecar'),
                 'comments': thread})
            n_comments += len(thread)
        for pid, thread in comments_by_post.items():
            if not thread:
                continue
            self.archive.write_json(
                f'content/comments/post-{pid:06d}.json',
                {'schema_version': pa.SCHEMA_VERSION, 'parent': {'type': 'post', 'id': pid},
                 'sidecar': (self.post_index.get(pid) or {}).get('sidecar'),
                 'comments': thread})
            n_comments += len(thread)
        self.archive.counts['comments'] = n_comments

        # -- relationships (raw, ordered, machine-readable) -----------------
        self.archive.write_json('content/relationships/post-images.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'note': 'Ordered by sort_position. This IS the carousel order.',
            'posts': {str(pid): rows for pid, rows in sorted(post_images.items())},
        })
        for rel_name, mapping in (
                ('image-categories', img_cats), ('image-albums', img_albums),
                ('image-tags', img_tags), ('post-categories', post_cats),
                ('post-albums', post_albums)):
            self.archive.write_json(
                f'content/relationships/{rel_name}.json',
                {'schema_version': pa.SCHEMA_VERSION,
                 'map': {str(k): v for k, v in sorted(mapping.items())}})
        if mosaics:
            self.archive.write_json('content/relationships/mosaics.json', {
                'schema_version': pa.SCHEMA_VERSION,
                'note': 'SMACKTALK layout. asset_ids is the display order; '
                        'focus_positions is per-image cropping. Referenced from '
                        'post bodies as [mosaic:ID].',
                'mosaics': mosaics,
            })
        if trigrams:
            self.archive.write_json('content/relationships/trigrams.json', {
                'schema_version': pa.SCHEMA_VERSION,
                'note': 'GRAMOFSMACK: three posts that are one sliced image.',
                'trigrams': trigrams,
            })

        self._reference = {
            'categories': categories, 'albums': albums, 'tags': tags,
            'collections': collections, 'collection_items': coll_items,
            'assets': assets, 'blogroll_cats': blogroll_cats,
            'image_categories': img_cats, 'image_albums': img_albums,
        }
        self.state.set_stage('media')

    # -- 5. media -----------------------------------------------------------
    def _stage_media(self):
        if self.options.skip_media:
            self.warn('Media download was skipped at your request. This archive '
                      'contains your data but NOT your photographs.')
            return
        self.state.set_stage('media')
        done  = self.state.media_done()
        plan  = [p for p in self.media_plan
                 if ExportState.media_key(p[0], p[1], p[2]) not in done]
        already = len(self.media_plan) - len(plan)
        if already:
            self.log(f'{already:,} media files were already downloaded and verified.')

        total = len(self.media_plan)
        counter = {'n': already, 'bytes': 0}

        # Re-register the files a previous run downloaded, so the manifest lists
        # everything and not just what today added.
        #
        # The path comes out of the resume ledger, which is a file on disk that a
        # person can open in a text editor. It is contained rather than trusted —
        # spec 13, treat JSON values as untrusted data, including our own.
        for key, entry in done.items():
            try:
                abs_path = contained_local_path(self.root, entry.get('path') or '')
            except ValueError:
                self.warn(f'The resume ledger names a file outside the archive '
                          f'({entry.get("path")!r}). Ignored.')
                continue
            if os.path.exists(abs_path):
                self.archive.register_media(abs_path, entry.get('sha256'), entry.get('bytes'))
                counter['bytes'] += _as_int(entry.get('bytes'))

        if not plan:
            self.report.media_files = len(done)
            self.report.media_bytes = counter['bytes']
            return

        workers = self.options.media_concurrency
        clients = [self.client] + [self._spawn_client() for _ in range(workers - 1)]

        def fetch(job, i):
            source, sid, variant, rel, label = job
            if self.cancel.is_set():
                raise Cancelled()
            abs_path = os.path.join(self.root, *rel.split('/'))
            # One client per worker: requests.Session is not for sharing.
            res = clients[i % len(clients)].download_media(
                sid, abs_path, variant=variant, source=source)
            with self._lock:
                self.state.record_media(source, sid, variant, rel, res.sha256, res.bytes_written)
                self.archive.register_media(abs_path, res.sha256, res.bytes_written)
                counter['n'] += 1
                counter['bytes'] += res.bytes_written
                n = counter['n']
            if n % 10 == 0 or n == total:
                self.state.flush()
            self.progress('media', f'{n:,} of {total:,} files — {label}',
                          n / max(1, total))
            return res

        errors = []
        if workers == 1:
            for i, job in enumerate(plan):
                self._check_cancel()
                try:
                    fetch(job, i)
                except Cancelled:
                    raise
                except (TyswyError, OSError) as e:
                    errors.append((job, e))
        else:
            with ThreadPoolExecutor(max_workers=workers) as ex:
                futures = {ex.submit(fetch, job, i): job for i, job in enumerate(plan)}
                for fut, job in futures.items():
                    try:
                        fut.result()
                    except Cancelled:
                        self.cancel.set()
                    except (TyswyError, OSError) as e:
                        errors.append((job, e))
            self._check_cancel()

        for job, e in errors:
            source, sid, variant, rel, label = job
            self.report.media_missing.append(
                {'source': source, 'id': sid, 'variant': variant,
                 'path': rel, 'reason': str(e)})
            self.warn(f'Could not download {source} {sid} ({variant}): {e}')

        for c in clients[1:]:
            c.close()

        self.state.data['media_files'] = counter['n']
        self.state.flush()
        self.report.media_files = counter['n']
        self.report.media_bytes = counter['bytes']

    def _spawn_client(self):
        if self._client_factory:
            return self._client_factory()
        return TyswyClient(self.client.site_url, self.client.api_key,
                           app_version=self.app_version, allow_http=True)

    # -- 6. indexes + site + schema ----------------------------------------
    def _stage_indexes(self, pre):
        self.state.set_stage('indexes')
        self.progress('indexes', 'Building the indexes…', 0.9)
        ref = self._reference

        # Invert the membership maps the assembly pass already built.
        images_in_cat   = defaultdict(list)
        images_in_album = defaultdict(list)
        for iid, cats in ref['image_categories'].items():
            for c in cats:
                images_in_cat[c].append(iid)
        for iid, albs in ref['image_albums'].items():
            for a in albs:
                images_in_album[a].append(iid)

        self.archive.write_json('indexes/categories.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'categories': [
                {'id': cid, 'name': row.get('cat_name'), 'slug': row.get('cat_slug'),
                 'description': row.get('cat_description') or None,
                 'cover_image_id': _as_int(row.get('cover_image_id')) or None,
                 'image_ids': sorted(images_in_cat.get(cid, []))}
                for cid, row in sorted(ref['categories'].items())],
        })
        self.archive.write_json('indexes/albums.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'albums': [
                {'id': aid, 'name': row.get('album_name'),
                 'description': row.get('album_description') or None,
                 'cover_image_id': _as_int(row.get('cover_image_id')) or None,
                 'image_ids': sorted(images_in_album.get(aid, []))}
                for aid, row in sorted(ref['albums'].items())],
        })
        self.archive.write_json('indexes/tags.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'tags': [{'id': tid, 'tag': row.get('tag'), 'slug': row.get('slug'),
                      'use_count': _as_int(row.get('use_count'))}
                     for tid, row in sorted(ref['tags'].items())],
        })
        self.archive.write_json('indexes/collections.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'collections': [
                {'id': cid, 'title': row.get('title'), 'slug': row.get('slug'),
                 'description': row.get('description') or None,
                 'published': _truthy(row.get('published')),
                 'items': sorted(ref['collection_items'].get(cid, []),
                                 key=lambda x: (_as_int(x.get('position')), _as_int(x.get('id'))))}
                for cid, row in sorted(ref['collections'].items())],
        })
        self.archive.write_json('indexes/blogroll.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'categories': [{'id': k, 'name': v.get('cat_name')}
                           for k, v in sorted(ref['blogroll_cats'].items())],
            'links': [r['record'] for r in self._records('blogroll')],
        })

        stats = [r['record'] for r in self._records('stats_summary')]
        if stats:
            self.archive.write_json('indexes/stats.json', {
                'schema_version': pa.SCHEMA_VERSION,
                'note': 'Daily aggregates only. Per-visit records (addresses, '
                        'browsers, referrer URLs) are visitor telemetry, not your '
                        'content, and were never exported.',
                'daily': stats,
            })
        follows = [r['record'] for r in self._records('follows')]
        following = [r['record'] for r in self._records('following')]
        if follows or following:
            self.archive.write_json('indexes/fediverse.json', {
                'schema_version': pa.SCHEMA_VERSION,
                'note': 'REFERENCES, not identity. These are the accounts that '
                        'followed you and that you followed. Nothing here moves a '
                        'Fediverse account to another server — no private signing '
                        'key was exported, and none could be.',
                'followers': follows,
                'following': following,
            })

        content_map = []
        for pid, info in sorted(self.post_index.items()):
            content_map.append({'type': 'post', 'id': pid, 'title': info['title'],
                                'date': info['date'], 'status': info['status'],
                                'sidecar': info['sidecar'],
                                'image_ids': info['images']})
        for iid, info in sorted(self.image_index.items()):
            content_map.append({'type': 'image', 'id': iid, 'title': info['title'],
                                'date': info['date'], 'status': info['status'],
                                'sidecar': info['sidecar'], 'media': info['media']})
        for gid, info in sorted(self.page_index.items()):
            content_map.append({'type': 'page', 'id': gid, 'title': info['title'],
                                'sidecar': info['sidecar']})
        self.archive.write_json('indexes/content-map.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'site_mode': self.report.site.get('site_mode'),
            'items': content_map,
        })

        self.archive.write_site(pre.get('settings_public') or {})
        self.archive.write_readme()
        self.archive.write_schema()

        for note in (pre.get('included_sensitive_classes') or []):
            self.archive.warn('Included, and you should know it is here: ' + note)

    # -- 7. reconcile -------------------------------------------------------
    def _stage_reconcile(self):
        """
        Spec 6.5 / 12: prove the local ledger against the source, then deal with
        anything that changed while we were reading.
        """
        self.state.set_stage('verify')
        self.progress('verify', 'Checking nothing went missing…', 0.95)

        snapshot_for = {t: self.state.type_state(t).get('snapshot') or 0
                        for t in STREAMED_TYPES}
        counts_local = {t: _as_int(self.state.type_state(t).get('rows'))
                        for t in STREAMED_TYPES
                        if self.state.type_state(t).get('supported', True)}

        expected = {}
        try:
            # `verify` answers for every type at ONE snapshot value, but each type
            # has its own watermark. Group the types by the snapshot they were
            # read at so this costs a handful of calls rather than one per type.
            by_snapshot = defaultdict(list)
            for rtype in STREAMED_TYPES:
                by_snapshot[snapshot_for.get(rtype) or 0].append(rtype)
            for snap, group in by_snapshot.items():
                answer = self.client.verify(snapshot=snap).get('types', {}) or {}
                for rtype in group:
                    info = answer.get(rtype) or {}
                    if not info.get('supported'):
                        continue
                    at = info.get('count_at_snapshot')
                    expected[rtype] = _as_int(at if at is not None else info.get('count'))
        except TyswyError as e:
            self.warn(f'The site could not confirm its own totals ({e}). The '
                      'archive is written and hashed, but this run could not '
                      'prove it is complete.')
            expected = dict(self.report.expected)

        self.report.expected = expected
        mismatches = {t: {'expected': n, 'actual': counts_local.get(t, 0)}
                      for t, n in expected.items() if counts_local.get(t, 0) != n}

        # A row added AFTER the snapshot is not missing — it is out of scope, and
        # saying so is the difference between an honest report and a scary one.
        for t, m in list(mismatches.items()):
            if m['actual'] > m['expected']:
                continue
            snap = snapshot_for.get(t) or 0
            if snap and m['expected'] > m['actual']:
                self.warn(
                    f'{t}: the site reports {m["expected"]:,} rows at the export '
                    f'snapshot but {m["actual"]:,} arrived. That is a real gap, '
                    'not a timing artefact — re-run the export against this same '
                    'folder to fetch the rest.')

        # Rows modified during the run: refetch and rewrite.
        changed_total = 0
        started = self.state.started_at
        if started:
            try:
                ch = self.client.changes(since=started).get('types', {}) or {}
            except TyswyError:
                ch = {}
            for rtype, info in ch.items():
                ids = info.get('changed_ids')
                if not ids:
                    continue
                snap = snapshot_for.get(rtype) or 0
                ids = [i for i in ids if not snap or i <= snap]
                if not ids:
                    continue
                changed_total += len(ids)
                self.warn(
                    f'{len(ids)} {rtype} changed on the site while the export was '
                    'running. The archive holds the version as of the snapshot '
                    f'({snap}); the changed ids are listed in verification.json.')
                mismatches.setdefault('_changed_during_export', {})[rtype] = ids

        self.report.mismatches = mismatches
        self.report.counts     = dict(self.archive.counts)
        self.archive.counts.update({f'records:{t}': n for t, n in counts_local.items()})

        ledger = {t: {'rows': counts_local.get(t, 0),
                      'ledger_sha256': self.state.ledger_digest(t),
                      'snapshot': snapshot_for.get(t) or 0}
                  for t in STREAMED_TYPES
                  if self.state.type_state(t).get('supported', True)}
        self.archive.write_json('verification.json', {
            'schema_version':  pa.SCHEMA_VERSION,
            'export_uuid':     self.state.export_uuid,
            'generated_at':    _iso_now(),
            'expected_counts': expected,
            'actual_counts':   counts_local,
            'mismatches':      {k: v for k, v in mismatches.items()
                                if k != '_changed_during_export'},
            'changed_during_export': mismatches.get('_changed_during_export', {}),
            'stream_ledger':   ledger,
            'media': {
                'files':   self.report.media_files,
                'bytes':   self.report.media_bytes,
                'missing': self.report.media_missing,
            },
            'complete': (not [k for k in mismatches if k != '_changed_during_export']
                         and not self.report.media_missing),
        })

    # -- 8. adapters --------------------------------------------------------
    def _stage_adapters(self):
        self.state.set_stage('adapters')
        adapters = {}
        if self.options.courtesy_wordpress:
            try:
                import wordpress_adapter
                self.progress('adapters', 'Writing the WordPress courtesy package…', 0.97)
                result = wordpress_adapter.generate(
                    self.root, on_log=self.log, cancel=self.cancel)
                adapters['wordpress'] = result
                for loss in result.get('losses', [])[:50]:
                    self.log('WordPress cannot represent: ' + loss)
            except Cancelled:
                raise
            except Exception as e:                      # an adapter must never
                self.warn(f'The WordPress courtesy package could not be built '  # break the canonical
                          f'({e}). Your canonical archive is unaffected — the '   # archive
                          'courtesy files can be regenerated from it at any time.')
        self.archive.write_json('courtesy/adapters.json', {
            'schema_version': pa.SCHEMA_VERSION,
            'note': 'Courtesy output is derived from the canonical archive and '
                    'can always be regenerated from it. Nothing in courtesy/ is '
                    'authoritative.',
            'adapters': adapters,
        })
        self.report.adapters = adapters

    # -- 9. finish ----------------------------------------------------------
    def _stage_finish(self):
        self.state.set_stage('manifest')
        complete = (not [k for k in self.report.mismatches if k != '_changed_during_export']
                    and not self.report.media_missing)
        self.archive.write_manifest(
            snapshot={'types': {t: self.state.type_state(t).get('snapshot') or 0
                                for t in STREAMED_TYPES},
                      'taken_at': self.state.started_at},
            completed=complete,
            adapters={k: {kk: vv for kk, vv in (v or {}).items()
                          if kk in ('version', 'format', 'files', 'losses_count')}
                      for k, v in (self.report.adapters or {}).items()},
            generated_at=_iso_now())

        self.report.complete   = complete
        self.report.counts     = dict(self.archive.counts)
        self.report.warnings   = list(dict.fromkeys(self.report.warnings + self.archive.warnings))
        self.report.exclusions = list(self.archive.exclusions)

        if self.options.compress:
            self.progress('finish', 'Compressing (local, optional)…', 0.99)
            base = self.root.rstrip('\\/')
            self.report.zip_path = shutil.make_archive(base, 'zip', self.root)
            self.log(f'Compressed copy written to {self.report.zip_path}')

        if complete:
            self.state.mark_complete()
            self.progress('finish', 'Your shit is packed.', 1.0)
            self.log('EXPORT COMPLETE'
                     + (' (with warnings)' if self.report.warnings else ''))
        else:
            # NOT complete, so the state stays resumable. Marking it finished
            # here would lock the owner out of the very thing the report tells
            # them to do — run it again against this folder to fetch the rest.
            self.state.set_stage('incomplete')
            self.progress('finish', 'Finished, but not complete.', 1.0)
            self.log('EXPORT INCOMPLETE — run again against this folder to fetch '
                     'what is missing. Nothing here has been deleted.')

    # =======================================================================
    # helpers
    # =======================================================================
    def _records(self, rtype):
        return self.state.iter_raw(rtype)

    def _public_url(self, path):
        base = (self.report.site.get('site_url') or '').rstrip('/')
        return f'{base}/{path.lstrip("/")}' if base else None

    def _public_url_for_image(self, row):
        slug = row.get('img_slug')
        return self._public_url(f'image/{slug}') if slug else None

    @staticmethod
    def _inline_references(body):
        """
        Find [img:ID] / [mosaic:ID] in a longform body.

        SMACKTALK bodies point at media by shortcode. Listing the references
        explicitly means a reader (or an adapter) does not have to know
        SnapSmack's shortcode syntax to find out which files a piece of writing
        depends on (spec 8: inline image order and placement stay explicit).
        """
        if not body:
            return []
        out = []
        for m in re.finditer(r'\[(img|mosaic)\s*:\s*(\d+)', str(body)):
            out.append({'kind': m.group(1), 'id': int(m.group(2)),
                        'offset': m.start()})
        return out

    @staticmethod
    def _portable_image_comment(row):
        return {
            'source': {'type': 'image_comment', 'id': _as_int(row.get('id'))},
            'author':       row.get('comment_author') or None,
            'author_url':   row.get('comment_url') or None,
            'author_email': row.get('comment_email') or None,
            'body':         row.get('comment_text') or '',
            'date':         pa._iso(row.get('comment_date')),
            'moderation':   'approved' if _truthy(row.get('is_approved')) else 'pending',
            'federated':    (row.get('ap_source') or 'local') == 'fediverse',
            'actor_url':    row.get('ap_actor_url') or None,
            'in_reply_to':  row.get('ap_in_reply_to') or None,
        }

    @staticmethod
    def _portable_community_comment(row):
        status = (row.get('status') or 'visible').lower()
        return {
            'source': {'type': 'comment', 'id': _as_int(row.get('id'))},
            'author':       row.get('guest_name') or (
                f'user {row["user_id"]}' if row.get('user_id') else None),
            'author_url':   row.get('guest_url') or None,
            'author_email': row.get('guest_email') or None,
            'body':         row.get('comment_text') or '',
            'date':         pa._iso(row.get('created_at')),
            'edited':       pa._iso(row.get('edited_at')),
            'moderation':   {'visible': 'approved', 'hidden': 'hidden',
                             'deleted': 'deleted'}.get(status, status),
            'federated':    False,
        }
# ===== SNAPSMACK EOF =====
