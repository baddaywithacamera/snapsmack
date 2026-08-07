"""
TAKE YOUR SHIT WITH YOU — WordPress courtesy adapter.

Spec: _spec/take-your-shit-with-you-spec-v0_1.md sections 10 and 11.

The canonical archive is complete WITHOUT this. What comes out of here is a
courtesy: a best-effort WXR package that gives someone leaving a running start
in WordPress. It is derived output. It can be deleted and regenerated. It is
never authoritative, and it never edits a canonical file.

THREE RULES, all of them from section 11.

  1. WRITE ONLY UNDER courtesy/wordpress/. The adapter reads the canonical
     archive and writes nowhere else. Running it twice must leave the canonical
     files byte-identical — that is a test, not an aspiration.
  2. REPORT EVERY LOSS. WordPress cannot hold a MOSAIC layout, a trigram, a
     per-image focal point, a Fediverse reference or a SnapSmack collection.
     Silence about that would be the actual dishonesty; an owner who is told
     "your carousel became a gallery and the crop settings did not come" can
     make a decision. One who is told nothing finds out in a year.
  3. NEVER EXECUTE ANYTHING. Titles, captions, bodies and filenames from the
     archive are untrusted text. They are escaped on the way into XML and on the
     way into the HTML report, and nothing here evaluates a template.

ON MEDIA. Spec 10 wants the media files in the package, because the standard
WordPress importer expects to fetch media by URL and a dead site serves nothing.
Copying a 40 GB library to sit beside itself is a poor thanks, so files are
HARD-LINKED into the courtesy folder when the filesystem allows it and copied
only when it does not. Either way the package is self-contained.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import html
import json
import os
import re
import shutil
import sys
from datetime import datetime, timezone

# Shared path containment (tools/_shared/snap_paths.py).
_SHARED_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
if os.path.isdir(_SHARED_DIR) and _SHARED_DIR not in sys.path:
    sys.path.insert(0, _SHARED_DIR)
from snap_paths import contained_local_path  # noqa: E402

ADAPTER_NAME    = 'wordpress'
ADAPTER_VERSION = '1.0.0'
TARGET_FORMAT   = 'WXR 1.2'
CANONICAL_RANGE = '>=1,<2'          # canonical schema versions this reads

OUT_DIR   = 'courtesy/wordpress'
XML_NAME  = 'snapsmack-wordpress.xml'

# WordPress post ids are invented here. Starting high keeps them clear of the
# small ids a fresh WordPress install hands out, so a partial import is easier
# to unpick.
FIRST_POST_ID       = 10000
FIRST_ATTACHMENT_ID = 500000
FIRST_COMMENT_ID    = 900000


class AdapterCancelled(Exception):
    pass


# ---------------------------------------------------------------------------
# Escaping
# ---------------------------------------------------------------------------

# XML 1.0 cannot carry some characters at all. A stray 0x0B in a caption
# produces a file no parser will open, and the importer's error message will
# not say which character or which post - so they are dropped here.
#
# Written with code points rather than escape sequences on purpose: this is a
# statement about exactly which characters are legal, and it should not be
# possible to misread it or to mangle it by editing the file.
_XML_OK_SINGLES = frozenset((0x09, 0x0A, 0x0D))


def _xml_clean(value):
    """Drop every code point XML 1.0 has no representation for."""
    out = []
    for ch in ('' if value is None else str(value)):
        cp = ord(ch)
        if (cp in _XML_OK_SINGLES
                or 0x20 <= cp <= 0xD7FF
                or 0xE000 <= cp <= 0xFFFD
                or 0x10000 <= cp <= 0x10FFFF):
            out.append(ch)
    return ''.join(out)


def _xml_text(value):
    """Strip characters XML 1.0 cannot represent at all, then escape.

    A stray 0x0B in a caption produces a file no XML parser will open, and the
    importer's error message will not mention which character or which post."""
    s = _xml_clean(value)
    return html.escape(s, quote=False)


def _cdata(value):
    s = _xml_clean(value)
    # The only sequence that can close a CDATA section early.
    s = s.replace(']]>', ']]&gt;')
    return f'<![CDATA[{s}]]>'


def _wp_date(iso):
    """ISO 8601 -> 'YYYY-MM-DD HH:MM:SS', which is what WXR carries."""
    if not iso:
        return '0000-00-00 00:00:00'
    try:
        dt = datetime.fromisoformat(str(iso).replace('Z', '+00:00'))
        if dt.tzinfo:
            dt = dt.astimezone(timezone.utc)
        return dt.strftime('%Y-%m-%d %H:%M:%S')
    except ValueError:
        return '0000-00-00 00:00:00'


def _rfc2822(iso):
    if not iso:
        return datetime.now(timezone.utc).strftime('%a, %d %b %Y %H:%M:%S +0000')
    try:
        dt = datetime.fromisoformat(str(iso).replace('Z', '+00:00'))
        if dt.tzinfo:
            dt = dt.astimezone(timezone.utc)
        return dt.strftime('%a, %d %b %Y %H:%M:%S +0000')
    except ValueError:
        return datetime.now(timezone.utc).strftime('%a, %d %b %Y %H:%M:%S +0000')


def _slugify(text, fallback='item'):
    s = re.sub(r'[^a-zA-Z0-9]+', '-', str(text or '')).strip('-').lower()
    return (s or fallback)[:80]


# ---------------------------------------------------------------------------
# Reading the canonical archive
# ---------------------------------------------------------------------------

def _read_json(root, rel, default=None):
    path = os.path.join(root, *rel.split('/'))
    try:
        with open(path, encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def _read_dir(root, rel):
    d = os.path.join(root, *rel.split('/'))
    if not os.path.isdir(d):
        return []
    out = []
    for name in sorted(os.listdir(d)):
        if not name.endswith('.json'):
            continue
        data = _read_json(root, f'{rel}/{name}')
        if isinstance(data, dict):
            out.append(data)
    return out


# ---------------------------------------------------------------------------
# Generation
# ---------------------------------------------------------------------------

def generate(archive_root, *, on_log=None, cancel=None, include_media=True):
    """
    Build courtesy/wordpress/ from the canonical archive at `archive_root`.

    Returns a result dict for courtesy/adapters.json. Raises only for genuine
    failures — an item this adapter cannot represent is a recorded LOSS, not an
    error, because "WordPress cannot hold this" is information, not a fault.
    """
    def log(msg):
        if on_log:
            on_log(msg)

    def check():
        if cancel is not None and cancel.is_set():
            raise AdapterCancelled()

    root    = os.path.abspath(archive_root)
    out_abs = os.path.join(root, *OUT_DIR.split('/'))
    media_abs = os.path.join(out_abs, 'media')
    os.makedirs(media_abs, exist_ok=True)

    site       = _read_json(root, 'site.json', {}) or {}
    content_map = (_read_json(root, 'indexes/content-map.json', {}) or {})
    cats_index = (_read_json(root, 'indexes/categories.json', {}) or {}).get('categories', [])
    albums_ix  = (_read_json(root, 'indexes/albums.json', {}) or {}).get('albums', [])
    tags_index = (_read_json(root, 'indexes/tags.json', {}) or {}).get('tags', [])
    colls_ix   = (_read_json(root, 'indexes/collections.json', {}) or {}).get('collections', [])

    site_name = site.get('name') or 'SnapSmack site'
    site_url  = (site.get('url') or '').rstrip('/')
    site_mode = site.get('mode') or content_map.get('site_mode') or 'unknown'

    posts  = _read_dir(root, 'content/posts')
    images = _read_dir(root, 'content/images')
    pages  = _read_dir(root, 'content/pages')

    losses    = []
    def loss(msg):
        if msg not in losses:
            losses.append(msg)

    # Which images already belong to a post? Those become gallery members rather
    # than posts of their own, so nothing is published twice.
    in_a_post = set()
    for p in posts:
        for ref in (p.get('images') or []):
            in_a_post.add(int(ref.get('image_id') or 0))

    # -- media placement ----------------------------------------------------
    media_map   = []
    attach_id   = FIRST_ATTACHMENT_ID
    attach_for  = {}          # image_id -> attachment record
    linked = copied = failed = 0

    for img in images:
        check()
        iid = int((img.get('source') or {}).get('id') or 0)
        refs = [m for m in (img.get('media') or []) if m.get('variant') == 'original']
        if not refs:
            loss(f'Image {iid} has no exported original, so WordPress gets no file for it.')
            continue
        rel = refs[0].get('path')
        # The path comes out of a sidecar, i.e. a JSON file on disk. Contained
        # rather than trusted, even though this tool wrote it — an archive is
        # data, and data stays untrusted (spec 13).
        try:
            src = contained_local_path(root, str(rel or ''))
        except ValueError:
            loss(f'Image {iid}: the sidecar names a media path outside the '
                 f'archive ({rel!r}). Skipped.')
            continue
        if not os.path.exists(src):
            loss(f'Image {iid}: {rel} was not downloaded, so the WordPress package '
                 'references a file that is not in the box.')
            continue

        base = os.path.basename(src)
        dst  = os.path.join(media_abs, base)
        placed = 'existing'
        if include_media and not os.path.exists(dst):
            placed, ok = _place(src, dst)
            if not ok:
                failed += 1
                loss(f'Image {iid}: could not be placed in the WordPress package '
                     f'({base}). Upload it by hand from media/originals/.')
                continue
            if placed == 'link':
                linked += 1
            else:
                copied += 1

        attach_id += 1
        entry = {
            'attachment_id': attach_id,
            'image_id':      iid,
            'archive_path':  rel,
            'wordpress_file': f'media/{base}',
            'filename':      base,
            'title':         img.get('title') or base,
            'alt':           img.get('description') or img.get('title') or '',
            'original_url':  (img.get('source') or {}).get('url'),
            'date':          (img.get('dates') or {}).get('created'),
        }
        media_map.append(entry)
        attach_for[iid] = entry

    if linked:
        log(f'WordPress package: {linked:,} media files hard-linked (no extra disk used).')
    if copied:
        log(f'WordPress package: {copied:,} media files copied.')

    # -- terms --------------------------------------------------------------
    terms = []
    for c in cats_index:
        terms.append(('category', _slugify(c.get('slug') or c.get('name'), f"cat-{c.get('id')}"),
                      c.get('name') or '', c.get('description') or ''))
    for t in tags_index:
        terms.append(('post_tag', _slugify(t.get('slug') or t.get('tag'), f"tag-{t.get('id')}"),
                      t.get('tag') or '', ''))
    album_names = set()
    for a in albums_ix:
        name = a.get('name') or ''
        if not name:
            continue
        album_names.add(name)
        terms.append(('post_tag', _slugify(f'album-{name}', f"album-{a.get('id')}"),
                      name, a.get('description') or ''))
    if album_names:
        loss('Albums became WordPress TAGS. WordPress has no album: a tag is the '
             'closest thing it has, and the distinction between "album" and "tag" '
             'is lost. The canonical archive keeps them separate.')
    if colls_ix:
        loss(f'{len(colls_ix)} collection(s) were NOT imported. A SnapSmack '
             'collection is an ordered, curated set with its own display mode; '
             'WordPress has no equivalent that would survive the trip. They are '
             'listed in the conversion report and remain complete in '
             'indexes/collections.json.')

    # -- items --------------------------------------------------------------
    items       = []
    post_id     = FIRST_POST_ID
    comment_id  = FIRST_COMMENT_ID
    n_posts = n_pages = n_comments = 0

    # attachments first, so a gallery can reference them
    for entry in media_map:
        items.append(_attachment_item(entry, site_url))

    for p in posts:
        check()
        post_id += 1
        item, used_comments, notes = _post_item(
            p, post_id, comment_id, attach_for, site_url, site_mode)
        comment_id += used_comments
        n_comments += used_comments
        n_posts += 1
        for n in notes:
            loss(n)
        items.append(item)

    for img in images:
        check()
        iid = int((img.get('source') or {}).get('id') or 0)
        if iid in in_a_post:
            continue                     # already inside its carousel post
        post_id += 1
        item, used_comments, notes = _image_post_item(
            img, post_id, comment_id, attach_for, site_url)
        comment_id += used_comments
        n_comments += used_comments
        n_posts += 1
        for n in notes:
            loss(n)
        items.append(item)

    for pg in pages:
        check()
        post_id += 1
        items.append(_page_item(pg, post_id, site_url))
        n_pages += 1

    # -- global losses ------------------------------------------------------
    if any(p.get('inline_media') for p in posts + pages):
        loss('SMACKTALK inline media ([img:ID] / [mosaic:ID]) was rendered to plain '
             'HTML <img> tags. The placement is approximate and any MOSAIC layout '
             'is flattened. The exact structure remains in the canonical sidecars.')
    if any((p.get('snapsmack') or {}).get('trigram') for p in posts):
        loss('Trigrams (one photograph sliced across three posts) became three '
             'ordinary WordPress posts. The slice geometry is in the canonical '
             'archive at content/relationships/trigrams.json.')
    if any(p.get('reactions', {}).get('likes') for p in posts):
        loss('Reaction and like counts were not imported. WXR has nowhere to put '
             'them; they are in the post sidecars.')
    if any(img.get('exif') for img in images):
        loss('EXIF (including any GPS) is NOT written into the WordPress import. '
             'WordPress reads EXIF from the image file itself on upload, so it '
             'depends on your media settings. The full EXIF is in every image '
             'sidecar regardless.')
    if any(img.get('sensitive') or img.get('content_warning') for img in images):
        loss('Content warnings and sensitive flags have no WordPress equivalent '
             'and were not imported.')
    if any(ref.get('presentation') for p in posts for ref in (p.get('images') or [])):
        loss('Per-image carousel presentation (crop mode, focal point, zoom, '
             'border, background, shadow) is not representable in WordPress. The '
             'order survives; the framing does not.')

    # -- write --------------------------------------------------------------
    check()
    xml = _wxr_document(site_name, site_url, terms, items)
    xml_path = os.path.join(out_abs, XML_NAME)
    with open(xml_path, 'w', encoding='utf-8', newline='\n') as f:
        f.write(xml)

    with open(os.path.join(out_abs, 'media-map.json'), 'w',
              encoding='utf-8', newline='\n') as f:
        json.dump({
            'adapter': ADAPTER_NAME, 'adapter_version': ADAPTER_VERSION,
            'note': 'Maps each canonical media file to the copy in this package '
                    'and to the attachment id used in the WXR file.',
            'media': media_map,
        }, f, indent=2, ensure_ascii=False, sort_keys=True)
        f.write('\n')

    report_path = os.path.join(out_abs, 'conversion-report.html')
    with open(report_path, 'w', encoding='utf-8', newline='\n') as f:
        f.write(_report_html(site_name, site_mode, losses, {
            'posts': n_posts, 'pages': n_pages,
            'attachments': len(media_map), 'comments': n_comments,
            'collections_skipped': len(colls_ix),
            'media_link_failures': failed,
        }, colls_ix))

    with open(os.path.join(out_abs, 'README.txt'), 'w',
              encoding='utf-8', newline='\n') as f:
        f.write(_readme(site_name, n_posts, n_pages, len(media_map)))

    log(f'WordPress courtesy package: {n_posts:,} posts, {n_pages:,} pages, '
        f'{len(media_map):,} attachments, {n_comments:,} comments, '
        f'{len(losses)} documented losses.')

    return {
        'name':    ADAPTER_NAME,
        'version': ADAPTER_VERSION,
        'format':  TARGET_FORMAT,
        'canonical_schema_range': CANONICAL_RANGE,
        'generated_at': datetime.now(timezone.utc).isoformat(),
        'files': [f'{OUT_DIR}/{XML_NAME}', f'{OUT_DIR}/media-map.json',
                  f'{OUT_DIR}/conversion-report.html', f'{OUT_DIR}/README.txt'],
        'items': {'posts': n_posts, 'pages': n_pages,
                  'attachments': len(media_map), 'comments': n_comments},
        'losses': losses,
        'losses_count': len(losses),
    }


def _place(src, dst):
    """Hard-link if we can, copy if we cannot. Returns (how, ok)."""
    try:
        os.link(src, dst)
        return 'link', True
    except (OSError, AttributeError, NotImplementedError):
        pass
    try:
        shutil.copy2(src, dst)
        return 'copy', True
    except OSError:
        return 'none', False


# ---------------------------------------------------------------------------
# WXR items
# ---------------------------------------------------------------------------

def _attachment_item(entry, site_url):
    url = entry.get('original_url') or f'{site_url}/{entry["wordpress_file"]}'
    date = _wp_date(entry.get('date'))
    return f"""  <item>
    <title>{_xml_text(entry.get('title'))}</title>
    <link>{_xml_text(url)}</link>
    <pubDate>{_rfc2822(entry.get('date'))}</pubDate>
    <dc:creator>{_cdata('admin')}</dc:creator>
    <guid isPermaLink="false">{_xml_text(url)}</guid>
    <description></description>
    <content:encoded>{_cdata(entry.get('alt') or '')}</content:encoded>
    <excerpt:encoded>{_cdata('')}</excerpt:encoded>
    <wp:post_id>{entry['attachment_id']}</wp:post_id>
    <wp:post_date>{_cdata(date)}</wp:post_date>
    <wp:post_date_gmt>{_cdata(date)}</wp:post_date_gmt>
    <wp:comment_status>{_cdata('closed')}</wp:comment_status>
    <wp:ping_status>{_cdata('closed')}</wp:ping_status>
    <wp:post_name>{_cdata(_slugify(entry.get('filename')))}</wp:post_name>
    <wp:status>{_cdata('inherit')}</wp:status>
    <wp:post_parent>0</wp:post_parent>
    <wp:menu_order>0</wp:menu_order>
    <wp:post_type>{_cdata('attachment')}</wp:post_type>
    <wp:post_password>{_cdata('')}</wp:post_password>
    <wp:is_sticky>0</wp:is_sticky>
    <wp:attachment_url>{_cdata(url)}</wp:attachment_url>
    <wp:postmeta>
      <wp:meta_key>{_cdata('_wp_attached_file')}</wp:meta_key>
      <wp:meta_value>{_cdata(entry['filename'])}</wp:meta_value>
    </wp:postmeta>
    <wp:postmeta>
      <wp:meta_key>{_cdata('_wp_attachment_image_alt')}</wp:meta_key>
      <wp:meta_value>{_cdata(entry.get('alt') or '')}</wp:meta_value>
    </wp:postmeta>
    <wp:postmeta>
      <wp:meta_key>{_cdata('_snapsmack_image_id')}</wp:meta_key>
      <wp:meta_value>{_cdata(str(entry.get('image_id')))}</wp:meta_value>
    </wp:postmeta>
  </item>
"""


def _comment_block(comments, first_id):
    """Only comments a WordPress import will keep. Deleted ones are dropped and
    the count is what the caller reports."""
    out, n = [], 0
    for c in comments or []:
        moderation = (c.get('moderation') or 'approved').lower()
        if moderation == 'deleted':
            continue
        approved = '1' if moderation == 'approved' else '0'
        cid = first_id + n
        n += 1
        out.append(f"""    <wp:comment>
      <wp:comment_id>{cid}</wp:comment_id>
      <wp:comment_author>{_cdata(c.get('author') or 'Anonymous')}</wp:comment_author>
      <wp:comment_author_email>{_cdata(c.get('author_email') or '')}</wp:comment_author_email>
      <wp:comment_author_url>{_cdata(c.get('author_url') or '')}</wp:comment_author_url>
      <wp:comment_author_IP>{_cdata('')}</wp:comment_author_IP>
      <wp:comment_date>{_cdata(_wp_date(c.get('date')))}</wp:comment_date>
      <wp:comment_date_gmt>{_cdata(_wp_date(c.get('date')))}</wp:comment_date_gmt>
      <wp:comment_content>{_cdata(c.get('body') or '')}</wp:comment_content>
      <wp:comment_approved>{_cdata(approved)}</wp:comment_approved>
      <wp:comment_type>{_cdata('')}</wp:comment_type>
      <wp:comment_parent>0</wp:comment_parent>
      <wp:comment_user_id>0</wp:comment_user_id>
    </wp:comment>
""")
    return ''.join(out), n


def _term_lines(categories, tags):
    lines = []
    for c in categories or []:
        lines.append(f'    <category domain="category" nicename="{_xml_text(_slugify(c))}">'
                     f'{_cdata(c)}</category>')
    for t in tags or []:
        lines.append(f'    <category domain="post_tag" nicename="{_xml_text(_slugify(t))}">'
                     f'{_cdata(t)}</category>')
    return ('\n'.join(lines) + '\n') if lines else ''


def _post_item(p, wp_id, first_comment_id, attach_for, site_url, site_mode):
    """
    A SnapSmack post. GRAMOFSMACK carousels become an ordered WordPress gallery;
    SMACKTALK bodies become conservative HTML. Order is taken from the sidecar,
    never re-derived — the sidecar already sorted it and that IS the order.
    """
    notes = []
    src   = p.get('source') or {}
    title = p.get('title') or f'Post {src.get("id")}'
    body_parts = []

    if p.get('body'):
        body_parts.append(_longform_html(p.get('body'), p.get('inline_media')))

    refs = p.get('images') or []
    ids  = [int(r.get('image_id') or 0) for r in refs]
    att  = [attach_for[i]['attachment_id'] for i in ids if i in attach_for]
    missing = [i for i in ids if i not in attach_for]
    if missing:
        notes.append(f'Post {src.get("id")}: {len(missing)} image(s) had no exported '
                     'file, so the gallery in WordPress is short. They are named in '
                     'the canonical post sidecar.')
    if att:
        if len(att) > 1:
            body_parts.append(
                f'<!-- wp:gallery -->\n[gallery ids="{",".join(str(a) for a in att)}" '
                'columns="3" link="file"]\n<!-- /wp:gallery -->')
        else:
            entry = next(e for e in attach_for.values() if e['attachment_id'] == att[0])
            body_parts.append(_img_html(entry, site_url))

    body = '\n\n'.join(x for x in body_parts if x) or ''
    dates = p.get('dates') or {}
    date  = _wp_date(dates.get('created'))
    status = 'publish' if (p.get('status') or 'published') in ('published', 'publish') else 'draft'
    comments_xml, n_comments = _comment_block(p.get('comments'), first_comment_id)
    cover = None
    for r in refs:
        if r.get('is_cover') and int(r.get('image_id') or 0) in attach_for:
            cover = attach_for[int(r['image_id'])]['attachment_id']
            break
    if cover is None and att:
        cover = att[0]

    thumb = ''
    if cover:
        thumb = f"""    <wp:postmeta>
      <wp:meta_key>{_cdata('_thumbnail_id')}</wp:meta_key>
      <wp:meta_value>{_cdata(str(cover))}</wp:meta_value>
    </wp:postmeta>
"""

    item = f"""  <item>
    <title>{_xml_text(title)}</title>
    <link>{_xml_text(src.get('url') or '')}</link>
    <pubDate>{_rfc2822(dates.get('created'))}</pubDate>
    <dc:creator>{_cdata('admin')}</dc:creator>
    <guid isPermaLink="false">snapsmack-post-{src.get('id')}</guid>
    <description></description>
    <content:encoded>{_cdata(body)}</content:encoded>
    <excerpt:encoded>{_cdata(p.get('description') or '')}</excerpt:encoded>
    <wp:post_id>{wp_id}</wp:post_id>
    <wp:post_date>{_cdata(date)}</wp:post_date>
    <wp:post_date_gmt>{_cdata(date)}</wp:post_date_gmt>
    <wp:comment_status>{_cdata('open')}</wp:comment_status>
    <wp:ping_status>{_cdata('closed')}</wp:ping_status>
    <wp:post_name>{_cdata(_slugify(p.get('slug') or title, f'post-{src.get("id")}'))}</wp:post_name>
    <wp:status>{_cdata(status)}</wp:status>
    <wp:post_parent>0</wp:post_parent>
    <wp:menu_order>0</wp:menu_order>
    <wp:post_type>{_cdata('post')}</wp:post_type>
    <wp:post_password>{_cdata('')}</wp:post_password>
    <wp:is_sticky>0</wp:is_sticky>
{_term_lines(p.get('categories'), (p.get('tags') or []) + (p.get('albums') or []))}\
{thumb}\
    <wp:postmeta>
      <wp:meta_key>{_cdata('_snapsmack_post_id')}</wp:meta_key>
      <wp:meta_value>{_cdata(str(src.get('id')))}</wp:meta_value>
    </wp:postmeta>
{comments_xml}  </item>
"""
    return item, n_comments, notes


def _image_post_item(img, wp_id, first_comment_id, attach_for, site_url):
    """
    SMACKONEOUT: one image record is one primary published item (spec 8), so it
    becomes one WordPress post carrying that image — not an orphan attachment.
    """
    notes = []
    src   = img.get('source') or {}
    iid   = int(src.get('id') or 0)
    title = img.get('title') or f'Image {iid}'
    entry = attach_for.get(iid)

    body = ''
    if entry:
        body = _img_html(entry, site_url)
    else:
        notes.append(f'Image {iid} has no file in the package, so its WordPress '
                     'post has no photograph in it.')
    if img.get('description'):
        body = (body + '\n\n' if body else '') + _paragraphs(img['description'])

    dates  = img.get('dates') or {}
    date   = _wp_date(dates.get('created'))
    status = 'publish' if (img.get('status') or 'published') in ('published', 'publish') else 'draft'
    comments_xml, n_comments = _comment_block(img.get('comments'), first_comment_id)

    thumb = ''
    if entry:
        thumb = f"""    <wp:postmeta>
      <wp:meta_key>{_cdata('_thumbnail_id')}</wp:meta_key>
      <wp:meta_value>{_cdata(str(entry['attachment_id']))}</wp:meta_value>
    </wp:postmeta>
"""

    item = f"""  <item>
    <title>{_xml_text(title)}</title>
    <link>{_xml_text(src.get('url') or '')}</link>
    <pubDate>{_rfc2822(dates.get('created'))}</pubDate>
    <dc:creator>{_cdata('admin')}</dc:creator>
    <guid isPermaLink="false">snapsmack-image-{iid}</guid>
    <description></description>
    <content:encoded>{_cdata(body)}</content:encoded>
    <excerpt:encoded>{_cdata('')}</excerpt:encoded>
    <wp:post_id>{wp_id}</wp:post_id>
    <wp:post_date>{_cdata(date)}</wp:post_date>
    <wp:post_date_gmt>{_cdata(date)}</wp:post_date_gmt>
    <wp:comment_status>{_cdata('open')}</wp:comment_status>
    <wp:ping_status>{_cdata('closed')}</wp:ping_status>
    <wp:post_name>{_cdata(_slugify(img.get('slug') or title, f'image-{iid}'))}</wp:post_name>
    <wp:status>{_cdata(status)}</wp:status>
    <wp:post_parent>0</wp:post_parent>
    <wp:menu_order>0</wp:menu_order>
    <wp:post_type>{_cdata('post')}</wp:post_type>
    <wp:post_password>{_cdata('')}</wp:post_password>
    <wp:is_sticky>0</wp:is_sticky>
{_term_lines(img.get('categories'), (img.get('tags') or []) + (img.get('albums') or []))}\
{thumb}\
    <wp:postmeta>
      <wp:meta_key>{_cdata('_snapsmack_image_id')}</wp:meta_key>
      <wp:meta_value>{_cdata(str(iid))}</wp:meta_value>
    </wp:postmeta>
{comments_xml}  </item>
"""
    return item, n_comments, notes


def _page_item(pg, wp_id, site_url):
    src   = pg.get('source') or {}
    title = pg.get('title') or f'Page {src.get("id")}'
    dates = pg.get('dates') or {}
    date  = _wp_date(dates.get('created'))
    status = 'publish' if (pg.get('status') or 'published') == 'published' else 'draft'
    return f"""  <item>
    <title>{_xml_text(title)}</title>
    <link>{_xml_text(src.get('url') or '')}</link>
    <pubDate>{_rfc2822(dates.get('created'))}</pubDate>
    <dc:creator>{_cdata('admin')}</dc:creator>
    <guid isPermaLink="false">snapsmack-page-{src.get('id')}</guid>
    <description></description>
    <content:encoded>{_cdata(_longform_html(pg.get('body'), pg.get('inline_media')))}</content:encoded>
    <excerpt:encoded>{_cdata('')}</excerpt:encoded>
    <wp:post_id>{wp_id}</wp:post_id>
    <wp:post_date>{_cdata(date)}</wp:post_date>
    <wp:post_date_gmt>{_cdata(date)}</wp:post_date_gmt>
    <wp:comment_status>{_cdata('closed')}</wp:comment_status>
    <wp:ping_status>{_cdata('closed')}</wp:ping_status>
    <wp:post_name>{_cdata(_slugify(pg.get('slug') or title, f'page-{src.get("id")}'))}</wp:post_name>
    <wp:status>{_cdata(status)}</wp:status>
    <wp:post_parent>0</wp:post_parent>
    <wp:menu_order>{int(pg.get('menu_order') or 0)}</wp:menu_order>
    <wp:post_type>{_cdata('page')}</wp:post_type>
    <wp:post_password>{_cdata('')}</wp:post_password>
    <wp:is_sticky>0</wp:is_sticky>
  </item>
"""


def _img_html(entry, site_url):
    url = entry.get('original_url') or f'{site_url}/{entry["wordpress_file"]}'
    return (f'<figure class="wp-block-image"><img src="{html.escape(str(url), quote=True)}" '
            f'alt="{html.escape(str(entry.get("alt") or ""), quote=True)}" /></figure>')


def _paragraphs(text):
    """Plain text to conservative HTML. No markdown, no shortcodes, no cleverness
    — the canonical body is right there if a human wants to redo it properly."""
    blocks = [b.strip() for b in re.split(r'\n\s*\n', str(text or '')) if b.strip()]
    return '\n\n'.join(f'<p>{html.escape(b).replace(chr(10), "<br />")}</p>' for b in blocks)


def _longform_html(body, inline_media):
    """
    SMACKTALK body -> conservative HTML.

    Shortcodes are replaced with a visible placeholder comment rather than being
    silently deleted: a reader editing the imported post can see exactly where a
    picture used to be, and the canonical sidecar says which one.
    """
    if not body:
        return ''
    text = str(body)
    text = re.sub(r'\[mosaic\s*:\s*(\d+)[^\]]*\]',
                  lambda m: f'\n\n<!-- SnapSmack MOSAIC {m.group(1)} was here. '
                            'WordPress has no equivalent layout; see the canonical '
                            'sidecar and content/relationships/mosaics.json. -->\n\n',
                  text)
    text = re.sub(r'\[img\s*:\s*(\d+)[^\]]*\]',
                  lambda m: f'\n\n<!-- SnapSmack inline image {m.group(1)}; the file '
                            'is in media/originals/assets/. -->\n\n',
                  text)
    # Anything else in brackets that looks like a shortcode is left as literal
    # text — inventing a translation would be worse than showing the source.
    return _paragraphs(text)


def _wxr_document(site_name, site_url, terms, items):
    now = datetime.now(timezone.utc).strftime('%a, %d %b %Y %H:%M:%S +0000')
    term_xml = []
    seen = set()
    for kind, slug, name, desc in terms:
        key = (kind, slug)
        if key in seen or not name:
            continue
        seen.add(key)
        if kind == 'category':
            term_xml.append(f"""  <wp:category>
    <wp:term_id>0</wp:term_id>
    <wp:category_nicename>{_cdata(slug)}</wp:category_nicename>
    <wp:category_parent>{_cdata('')}</wp:category_parent>
    <wp:cat_name>{_cdata(name)}</wp:cat_name>
    <wp:category_description>{_cdata(desc)}</wp:category_description>
  </wp:category>
""")
        else:
            term_xml.append(f"""  <wp:tag>
    <wp:term_id>0</wp:term_id>
    <wp:tag_slug>{_cdata(slug)}</wp:tag_slug>
    <wp:tag_name>{_cdata(name)}</wp:tag_name>
  </wp:tag>
""")
    return f"""<?xml version="1.0" encoding="UTF-8" ?>
<!--
  Generated by TAKE YOUR SHIT WITH YOU from a SnapSmack portable archive.
  This is COURTESY output. The canonical archive beside it is the authoritative
  copy; this file can be deleted and regenerated at any time.
  Read conversion-report.html before importing: it lists what WordPress cannot
  represent.
-->
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wfw="http://wellformedweb.org/CommentAPI/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
  <title>{_xml_text(site_name)}</title>
  <link>{_xml_text(site_url)}</link>
  <description>{_xml_text('Portable export from SnapSmack')}</description>
  <pubDate>{now}</pubDate>
  <language>en-US</language>
  <wp:wxr_version>1.2</wp:wxr_version>
  <wp:base_site_url>{_xml_text(site_url)}</wp:base_site_url>
  <wp:base_blog_url>{_xml_text(site_url)}</wp:base_blog_url>
  <wp:author>
    <wp:author_id>1</wp:author_id>
    <wp:author_login>{_cdata('admin')}</wp:author_login>
    <wp:author_email>{_cdata('')}</wp:author_email>
    <wp:author_display_name>{_cdata(site_name)}</wp:author_display_name>
  </wp:author>
{''.join(term_xml)}{''.join(items)}</channel>
</rss>
"""


# ---------------------------------------------------------------------------
# Reports
# ---------------------------------------------------------------------------

def _report_html(site_name, site_mode, losses, counts, collections):
    e = lambda v: html.escape(str(v))     # noqa: E731 — every value is untrusted
    loss_items = '\n'.join(f'    <li>{e(l)}</li>' for l in losses) or \
                 '    <li>Nothing was lost that this adapter knows how to detect.</li>'
    coll_items = '\n'.join(
        f'    <li>{e(c.get("title") or c.get("id"))} '
        f'&mdash; {len(c.get("items") or [])} item(s)</li>'
        for c in collections) or '    <li>None.</li>'
    return f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>WordPress conversion report &mdash; {e(site_name)}</title>
<style>
 body{{font:16px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;max-width:52rem;
      margin:2rem auto;padding:0 1rem;background:#14140f;color:#e8e8e0}}
 h1,h2{{font-weight:700;letter-spacing:.01em}} h1{{font-size:1.6rem}}
 h2{{font-size:1.1rem;margin-top:2rem;border-bottom:1px solid #333;padding-bottom:.3rem}}
 code{{background:#222;padding:.1rem .3rem;border-radius:3px}}
 li{{margin:.4rem 0}} .n{{font-variant-numeric:tabular-nums;font-weight:700}}
 .box{{background:#1c1c16;border-left:3px solid #b6ff1a;padding:.8rem 1rem;margin:1rem 0}}
</style></head><body>
<h1>WordPress conversion report</h1>
<p>{e(site_name)} &mdash; site mode <code>{e(site_mode)}</code></p>

<div class="box">
<p>This is a <strong>courtesy</strong> package. The canonical archive in the folder
above is the complete, authoritative copy of your work. Nothing listed below as a
loss is missing from that archive &mdash; it is missing from <em>WordPress</em>,
because WordPress has nowhere to put it.</p>
</div>

<h2>What is in the package</h2>
<ul>
  <li><span class="n">{counts['posts']}</span> posts</li>
  <li><span class="n">{counts['pages']}</span> pages</li>
  <li><span class="n">{counts['attachments']}</span> media attachments</li>
  <li><span class="n">{counts['comments']}</span> comments</li>
</ul>

<h2>What WordPress cannot represent</h2>
<ul>
{loss_items}
</ul>

<h2>Collections (not imported)</h2>
<ul>
{coll_items}
</ul>

<h2>How to import</h2>
<ol>
  <li>In WordPress: <strong>Tools &rarr; Import &rarr; WordPress</strong>, install the
      importer if prompted.</li>
  <li>Upload <code>snapsmack-wordpress.xml</code>.</li>
  <li>Assign posts to an existing user, and tick <em>Download and import file
      attachments</em>.</li>
  <li>The importer fetches media <strong>by URL</strong>. If your SnapSmack site is
      still online, that works. If it is not, WordPress will report failed
      attachments &mdash; upload the files from the <code>media/</code> folder in
      this package through <strong>Media &rarr; Add New</strong>, then relink them
      using <code>media-map.json</code>.</li>
</ol>
<p>This adapter does not claim one-click compatibility with every WordPress
setup, and no honest one could.</p>
</body></html>
"""


def _readme(site_name, n_posts, n_pages, n_media):
    return f"""WORDPRESS COURTESY PACKAGE
==========================

For: {site_name}

  snapsmack-wordpress.xml   the import file (WXR 1.2)
  media/                    your photographs, ready to upload
  media-map.json            which file became which attachment
  conversion-report.html    READ THIS FIRST - what WordPress cannot hold

This package is DERIVED. The folder above it is your real archive: complete,
readable, and not dependent on WordPress or on us. If this package is wrong,
delete it. It can be built again from the archive without downloading your site
a second time.

The standard WordPress importer wants to fetch images over the internet. If your
old site is still up, that will work. If it is not, import the XML first and then
upload the files in media/ by hand - the conversion report explains it.

{n_posts} posts, {n_pages} pages, {n_media} images.
"""
# ===== SNAPSMACK EOF =====
