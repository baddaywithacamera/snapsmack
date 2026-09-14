"""
TAKE YOUR SHIT WITH YOU - Ghost courtesy adapter.

Builds courtesy/ghost/ from the canonical archive: a Ghost import package
(ghost-import.json + content/images/, zipped as ghost-import.zip) that Ghost's
Labs -> Import accepts. Derived output, like the WordPress adapter: nothing in
courtesy/ is authoritative and it can always be regenerated from the canonical
archive.

Ghost's model is narrower than SnapSmack's, and an item Ghost cannot hold is a
recorded LOSS, not an error. In particular:

  * one featured image per post; a carousel becomes an HTML gallery in the body;
  * albums and categories both become Ghost TAGS (Ghost has only tags);
  * collections are not imported (no equivalent survives the trip);
  * COMMENTS ARE NOT IMPORTED - Ghost's importer has no comment input at all.
    They stay complete in the canonical archive and are listed in the report.

Format notes (Ghost 5.x importer):
  * JSON shape: {"db":[{"meta":{...},"data":{posts,tags,posts_tags,users,posts_authors}}]}
  * ids are 24-hex strings; dates are "YYYY-MM-DD HH:MM:SS" (UTC);
  * image URLs inside html / feature_image use the __GHOST_URL__/content/images/...
    prefix and are rewritten to the destination site on import;
  * a zip whose root holds the JSON plus content/images/ imports the files too.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""
import hashlib
import html
import json
import os
import re
import shutil
import sys
import zipfile
from datetime import datetime, timezone

_SHARED = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared'))
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)
from snap_paths import contained_local_path  # noqa: E402


def _canonical_range():
    try:
        import wordpress_adapter
        return wordpress_adapter.CANONICAL_RANGE
    except Exception:
        return None

OUT_DIR      = 'courtesy/ghost'
IMAGES_DIR   = 'content/images/snapsmack'
GHOST_PREFIX = '__GHOST_URL__/' + IMAGES_DIR
ADAPTER_VERSION = '1.0'
GHOST_VERSION   = '5.0.0'
ADAPTER_NAME    = 'ghost'
TARGET_FORMAT   = 'Ghost import JSON (5.x) + content/images zip'


class AdapterCancelled(Exception):
    pass


# ---------------------------------------------------------------------------
# small helpers
# ---------------------------------------------------------------------------

def _gid(kind, key):
    """A stable 24-hex id from a stable key, so re-running the adapter yields the
    same ids and a re-import updates rather than duplicates."""
    return hashlib.sha1(f'snapsmack:{kind}:{key}'.encode('utf-8')).hexdigest()[:24]


def _ghost_date(iso):
    if not iso:
        return datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    s = str(iso).replace('T', ' ')
    s = re.sub(r'(\.\d+)?(Z|[+-]\d{2}:?\d{2})$', '', s)
    return s[:19] if len(s) >= 19 else s


def _slugify(text, fallback='item'):
    s = re.sub(r'[^a-z0-9]+', '-', str(text or '').lower()).strip('-')
    return (s or fallback)[:180]


def _paragraphs(text):
    if not text:
        return ''
    blocks = [b.strip() for b in re.split(r'\n\s*\n', str(text)) if b.strip()]
    return '\n'.join(f'<p>{html.escape(b).replace(chr(10), "<br>")}</p>' for b in blocks)


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
        if name.endswith('.json'):
            data = _read_json(root, f'{rel}/{name}')
            if isinstance(data, dict):
                out.append(data)
    return out


def _place(src, dst):
    """Hard-link when possible (no extra disk), copy otherwise."""
    try:
        os.link(src, dst)
        return 'link', True
    except OSError:
        try:
            shutil.copy2(src, dst)
            return 'copy', True
        except OSError:
            return 'copy', False


def _img_tag(entry):
    alt = html.escape(entry.get('alt') or '')
    return (f'<figure class="kg-card kg-image-card"><img src="{entry["ghost_url"]}" '
            f'class="kg-image" alt="{alt}" loading="lazy"></figure>')


def _gallery_html(entries):
    rows = []
    for i in range(0, len(entries), 3):
        cells = ''.join(
            f'<div class="kg-gallery-image"><img src="{e["ghost_url"]}" alt="{html.escape(e.get("alt") or "")}" loading="lazy"></div>'
            for e in entries[i:i + 3])
        rows.append(f'<div class="kg-gallery-row">{cells}</div>')
    return ('<figure class="kg-card kg-gallery-card kg-width-wide"><div class="kg-gallery-container">'
            + ''.join(rows) + '</div></figure>')


def _longform_html(body):
    """SMACKTALK body -> conservative HTML. Shortcodes become visible comments,
    never silent deletions (same rule as the WordPress adapter)."""
    if not body:
        return ''
    text = str(body)
    text = re.sub(r'\[mosaic\s*:\s*(\d+)[^\]]*\]',
                  lambda m: f'\n\n<!-- SnapSmack MOSAIC {m.group(1)} was here. Ghost has no '
                            'equivalent layout; see the canonical sidecar. -->\n\n', text)
    text = re.sub(r'\[img\s*:\s*(\d+)[^\]]*\]',
                  lambda m: f'\n\n<!-- SnapSmack inline image {m.group(1)}; the file is in '
                            'media/originals/assets/. -->\n\n', text)
    return _paragraphs(text)


# ---------------------------------------------------------------------------
# Generation
# ---------------------------------------------------------------------------

def generate(archive_root, *, on_log=None, cancel=None, include_media=True):
    """
    Build courtesy/ghost/ from the canonical archive at `archive_root`.
    Returns a result dict for courtesy/adapters.json.
    """
    def log(msg):
        if on_log:
            on_log(msg)

    def check():
        if cancel is not None and cancel.is_set():
            raise AdapterCancelled()

    root      = os.path.abspath(archive_root)
    out_abs   = os.path.join(root, *OUT_DIR.split('/'))
    img_abs   = os.path.join(out_abs, *IMAGES_DIR.split('/'))
    os.makedirs(img_abs, exist_ok=True)

    site        = _read_json(root, 'site.json', {}) or {}
    content_map = _read_json(root, 'indexes/content-map.json', {}) or {}
    cats_index  = (_read_json(root, 'indexes/categories.json', {}) or {}).get('categories', [])
    albums_ix   = (_read_json(root, 'indexes/albums.json', {}) or {}).get('albums', [])
    tags_index  = (_read_json(root, 'indexes/tags.json', {}) or {}).get('tags', [])
    colls_ix    = (_read_json(root, 'indexes/collections.json', {}) or {}).get('collections', [])

    site_name = site.get('name') or 'SnapSmack site'
    site_url  = (site.get('url') or '').rstrip('/')
    site_mode = site.get('mode') or content_map.get('site_mode') or 'unknown'

    posts  = _read_dir(root, 'content/posts')
    images = _read_dir(root, 'content/images')
    pages  = _read_dir(root, 'content/pages')

    losses = []

    def loss(msg):
        if msg not in losses:
            losses.append(msg)

    in_a_post = set()
    for p in posts:
        for ref in (p.get('images') or []):
            in_a_post.add(int(ref.get('image_id') or 0))

    # -- media placement ----------------------------------------------------
    media_for = {}
    linked = copied = failed = 0
    for img in images:
        check()
        iid  = int((img.get('source') or {}).get('id') or 0)
        refs = [m for m in (img.get('media') or []) if m.get('variant') == 'original']
        if not refs:
            loss(f'Image {iid} has no exported original, so Ghost gets no file for it.')
            continue
        rel = refs[0].get('path')
        try:
            src = contained_local_path(root, str(rel or ''))
        except ValueError:
            loss(f'Image {iid}: the sidecar names a media path outside the archive ({rel!r}). Skipped.')
            continue
        if not os.path.exists(src):
            loss(f'Image {iid}: {rel} was not downloaded, so the Ghost package references a file that is not in the box.')
            continue
        base = os.path.basename(src)
        dst  = os.path.join(img_abs, base)
        if include_media and not os.path.exists(dst):
            placed, ok = _place(src, dst)
            if not ok:
                failed += 1
                loss(f'Image {iid}: could not be placed in the Ghost package ({base}). Upload it by hand from media/originals/.')
                continue
            linked += placed == 'link'
            copied += placed == 'copy'
        media_for[iid] = {
            'image_id':  iid,
            'filename':  base,
            'ghost_url': f'{GHOST_PREFIX}/{base}',
            'title':     img.get('title') or base,
            'alt':       img.get('description') or img.get('title') or '',
            'date':      (img.get('dates') or {}).get('created'),
        }
    if linked:
        log(f'Ghost package: {linked:,} media files hard-linked (no extra disk used).')
    if copied:
        log(f'Ghost package: {copied:,} media files copied.')

    # -- tags (Ghost has only tags) ---------------------------------------
    tags, tag_id_for = [], {}

    def add_tag(key, name, description=''):
        if not name:
            return None
        tid = _gid('tag', key)
        if tid not in tag_id_for.values():
            tags.append({'id': tid, 'name': str(name)[:191], 'slug': _slugify(name, key),
                         'description': (description or '')[:500] or None, 'visibility': 'public'})
        tag_id_for[key] = tid
        return tid

    for c in cats_index:
        add_tag(f"cat-{c.get('id')}", c.get('name'), c.get('description'))
    for t in tags_index:
        add_tag(f"tag-{t.get('id')}", t.get('tag'))
    album_names = [a.get('name') for a in albums_ix if a.get('name')]
    for a in albums_ix:
        add_tag(f"album-{a.get('id')}", a.get('name'), a.get('description'))
    if album_names:
        loss('Albums became Ghost TAGS. Ghost has no album; the distinction between "album" and "tag" is lost. '
             'The canonical archive keeps them separate.')
    if cats_index:
        loss('Categories became Ghost TAGS. Ghost has no category.')
    if colls_ix:
        loss(f'{len(colls_ix)} collection(s) were NOT imported. Ghost has no equivalent that would survive the trip; '
             'they remain complete in indexes/collections.json.')

    # -- author -------------------------------------------------------------
    author_id = _gid('user', site_url or site_name)
    users = [{'id': author_id, 'name': site.get('owner') or site_name, 'slug': _slugify(site_name, 'snapsmack'),
              'email': site.get('owner_email') or 'owner@example.invalid', 'status': 'active'}]

    # -- posts --------------------------------------------------------------
    ghost_posts, posts_tags, posts_authors = [], [], []
    n_posts = n_pages = n_comments_lost = 0
    n_carousels = 0

    def tag_links(pid, keys):
        for order, k in enumerate(keys):
            tid = tag_id_for.get(k)
            if tid:
                posts_tags.append({'post_id': pid, 'tag_id': tid, 'sort_order': order})

    def link_keys(rec):
        keys = []
        for c in (rec.get('categories') or []):
            keys.append(f"cat-{c.get('id') if isinstance(c, dict) else c}")
        for t in (rec.get('tags') or []):
            keys.append(f"tag-{t.get('id') if isinstance(t, dict) else t}")
        for a in (rec.get('albums') or []):
            keys.append(f"album-{a.get('id') if isinstance(a, dict) else a}")
        return keys

    def status_of(rec):
        return 'published' if (rec.get('status') or 'published') in ('published', 'publish') else 'draft'

    def count_comments(rec):
        return len([c for c in (rec.get('comments') or []) if (c.get('status') or 'approved') != 'deleted'])

    for p in posts:
        check()
        src   = p.get('source') or {}
        pid   = _gid('post', src.get('id'))
        title = p.get('title') or f'Post {src.get("id")}'
        parts = []
        if p.get('body'):
            parts.append(_longform_html(p.get('body')))
        refs    = p.get('images') or []
        entries = [media_for[int(r.get('image_id') or 0)] for r in refs if int(r.get('image_id') or 0) in media_for]
        missing = len(refs) - len(entries)
        if missing:
            loss(f'Post {src.get("id")}: {missing} image(s) had no exported file, so the gallery in Ghost is short.')
        feature = entries[0] if entries else None
        if len(entries) > 1:
            n_carousels += 1
            parts.append(_gallery_html(entries))
        elif entries and not p.get('body'):
            pass   # single image is the feature image; nothing to repeat in the body
        elif entries:
            parts.append(_img_tag(entries[0]))
        body_html = '\n'.join(x for x in parts if x)
        dates = p.get('dates') or {}
        n_comments_lost += count_comments(p)
        ghost_posts.append({
            'id': pid, 'title': str(title)[:255], 'slug': _slugify(p.get('slug') or title, f'post-{src.get("id")}'),
            'html': body_html or '<p></p>', 'feature_image': feature['ghost_url'] if feature else None,
            'feature_image_alt': (feature or {}).get('alt') or None,
            'feature_image_caption': None,
            'type': 'post', 'status': status_of(p), 'visibility': 'public',
            'custom_excerpt': (p.get('excerpt') or None),
            'published_at': _ghost_date(dates.get('published') or dates.get('created')),
            'created_at':   _ghost_date(dates.get('created')),
            'updated_at':   _ghost_date(dates.get('updated') or dates.get('created')),
        })
        posts_authors.append({'post_id': pid, 'author_id': author_id})
        tag_links(pid, link_keys(p))
        n_posts += 1

    for img in images:
        check()
        iid = int((img.get('source') or {}).get('id') or 0)
        if iid in in_a_post or iid not in media_for:
            continue
        entry = media_for[iid]
        pid   = _gid('image', iid)
        body  = _paragraphs(img.get('description')) if img.get('description') else ''
        dates = img.get('dates') or {}
        n_comments_lost += count_comments(img)
        ghost_posts.append({
            'id': pid, 'title': (img.get('title') or f'Image {iid}')[:255],
            'slug': _slugify(img.get('slug') or img.get('title'), f'image-{iid}'),
            'html': body or '<p></p>', 'feature_image': entry['ghost_url'], 'feature_image_alt': entry['alt'] or None,
            'feature_image_caption': None, 'type': 'post', 'status': status_of(img), 'visibility': 'public',
            'custom_excerpt': None,
            'published_at': _ghost_date(dates.get('published') or dates.get('created')),
            'created_at':   _ghost_date(dates.get('created')),
            'updated_at':   _ghost_date(dates.get('updated') or dates.get('created')),
        })
        posts_authors.append({'post_id': pid, 'author_id': author_id})
        tag_links(pid, link_keys(img))
        n_posts += 1

    for pg in pages:
        check()
        src = pg.get('source') or {}
        pid = _gid('page', src.get('id'))
        dates = pg.get('dates') or {}
        ghost_posts.append({
            'id': pid, 'title': (pg.get('title') or f'Page {src.get("id")}')[:255],
            'slug': _slugify(pg.get('slug') or pg.get('title'), f'page-{src.get("id")}'),
            'html': _longform_html(pg.get('body')) or '<p></p>', 'feature_image': None, 'feature_image_alt': None,
            'feature_image_caption': None, 'type': 'page', 'status': status_of(pg), 'visibility': 'public',
            'custom_excerpt': None,
            'published_at': _ghost_date(dates.get('published') or dates.get('created')),
            'created_at':   _ghost_date(dates.get('created')),
            'updated_at':   _ghost_date(dates.get('updated') or dates.get('created')),
        })
        posts_authors.append({'post_id': pid, 'author_id': author_id})
        n_pages += 1

    if n_comments_lost:
        loss(f'{n_comments_lost} comment(s) were NOT imported. Ghost\'s importer has no comment input. '
             'They remain complete in the canonical post and image sidecars.')
    if n_carousels:
        loss(f'{n_carousels} carousel(s) became an HTML gallery in the post body with the first image as the '
             'featured image. Ghost has no carousel; order is preserved.')

    # -- write --------------------------------------------------------------
    doc = {'db': [{
        'meta': {'exported_on': int(datetime.now(timezone.utc).timestamp() * 1000), 'version': GHOST_VERSION,
                 'generator': f'SnapSmack TAKE YOUR SHIT WITH YOU ghost_adapter {ADAPTER_VERSION}'},
        'data': {'posts': ghost_posts, 'tags': tags, 'posts_tags': posts_tags,
                 'users': users, 'posts_authors': posts_authors},
    }]}
    json_path = os.path.join(out_abs, 'ghost-import.json')
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(doc, f, ensure_ascii=False, indent=1)

    zip_path = os.path.join(out_abs, 'ghost-import.zip')
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_STORED) as z:
        z.write(json_path, 'ghost-import.json')
        if include_media:
            for name in sorted(os.listdir(img_abs)):
                z.write(os.path.join(img_abs, name), f'{IMAGES_DIR}/{name}')

    counts = {'posts': n_posts, 'pages': n_pages, 'tags': len(tags), 'media': len(media_for),
              'comments_not_imported': n_comments_lost, 'media_failed': failed}
    CANONICAL_RANGE = _canonical_range()
    with open(os.path.join(out_abs, 'CONVERSION-REPORT.html'), 'w', encoding='utf-8') as f:
        f.write(_report_html(site_name, site_mode, losses, counts))
    with open(os.path.join(out_abs, 'README.txt'), 'w', encoding='utf-8') as f:
        f.write(_readme(site_name, n_posts, n_pages, len(media_for)))

    log(f'Ghost package: {n_posts:,} posts, {n_pages:,} pages, {len(tags):,} tags, {len(media_for):,} images; '
        f'{len(losses)} thing(s) Ghost cannot represent (see CONVERSION-REPORT.html).')
    return {
        'name':    ADAPTER_NAME,
        'version': ADAPTER_VERSION,
        'format':  TARGET_FORMAT,
        'canonical_schema_range': CANONICAL_RANGE,
        'generated_at': datetime.now(timezone.utc).isoformat(),
        'files': [f'{OUT_DIR}/ghost-import.json', f'{OUT_DIR}/ghost-import.zip',
                  f'{OUT_DIR}/CONVERSION-REPORT.html', f'{OUT_DIR}/README.txt'],
        'items': {'posts': n_posts, 'pages': n_pages, 'tags': len(tags), 'images': len(media_for),
                  'comments_not_imported': n_comments_lost, 'media_failed': failed},
        'losses': losses,
        'losses_count': len(losses),
        # Flipped to True by hand, in this file, only after someone has imported a
        # package into a real Ghost and looked. Until then the JSON is shape-checked, not watched.
        'verified_against_real_ghost': False,
    }


def _report_html(site_name, site_mode, losses, counts):
    e = html.escape
    loss_items = '\n'.join(f'    <li>{e(l)}</li>' for l in losses) or '    <li>Nothing was lost in this conversion.</li>'
    return f"""<!doctype html><meta charset="utf-8"><title>Ghost conversion report - {e(site_name)}</title>
<style>body{{font:15px/1.5 Georgia,serif;max-width:52em;margin:2em auto;padding:0 1em}}h1,h2{{font-family:Arial,sans-serif}}</style>
<h1>Ghost conversion report</h1>
<p><strong>{e(site_name)}</strong> ({e(site_mode)}) - {counts['posts']:,} posts, {counts['pages']:,} pages,
{counts['tags']:,} tags, {counts['media']:,} images.</p>
<h2>How to import</h2>
<ol><li>Ghost admin &rarr; Settings &rarr; Labs &rarr; Import content.</li>
<li>Upload <code>ghost-import.zip</code> (the JSON plus the images).</li>
<li>Check a few posts. Images are rewritten to your Ghost site on import.</li></ol>
<h2>What Ghost cannot hold</h2>
<ul>
{loss_items}
</ul>
<p>Everything listed above remains complete in the canonical archive beside this folder. This package is
derived from it and can be regenerated at any time.</p>
"""


def _readme(site_name, n_posts, n_pages, n_media):
    return (f"GHOST IMPORT PACKAGE - {site_name}\n\n"
            f"{n_posts} posts, {n_pages} pages, {n_media} images.\n\n"
            "Import: Ghost admin -> Settings -> Labs -> Import content -> upload ghost-import.zip.\n"
            "Read CONVERSION-REPORT.html first: it lists everything Ghost cannot represent\n"
            "(comments, collections, the album/tag distinction). The canonical archive beside this\n"
            "folder keeps all of it.\n")

# ===== SNAPSMACK EOF =====
