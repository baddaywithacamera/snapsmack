"""SYBU producer bridge for the shared per-site offline library."""

import os
import sys
from urllib.parse import urlparse

_SHARED = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

import snap_library
import snap_enrichment_cache


def _tags(entry):
    return [tag.lstrip('#') for tag in (getattr(entry, 'tags', '') or '').split() if tag.strip()]


def _bundle(entry):
    return {
        key: getattr(entry, key, '') or ''
        for key in ('title', 'caption', 'alt', 'tags', 'category', 'album',
                    'colors', 'color_mode', 'orientation')
    }


def _dimensions(image_path):
    try:
        from PIL import Image
        with Image.open(image_path) as image:
            return image.size
    except Exception:
        return 0, 0


def _push_cache(session, base_url, record):
    if session is None:
        return {'pending': True}
    response = session.post(
        base_url.rstrip('/') + '/api.php?route=gyss/enrichment-cache',
        json={'record': record}, timeout=30,
    )
    data = response.json()
    if response.status_code >= 400 or not data.get('ok'):
        raise RuntimeError(data.get('error') or
                           f'enrichment propagation failed ({response.status_code})')
    snap_enrichment_cache.mark_synced(
        base_url, record['cache_key'], int(data['revision']))
    return data


def sync_pending(site: str, session) -> dict:
    """Retry durable desktop records whenever SYBU connects to their site."""
    synced = 0
    errors = []
    for record in snap_enrichment_cache.pending(site):
        try:
            _push_cache(session, site, record)
            synced += 1
        except Exception as exc:
            errors.append(str(exc))
    return {'synced': synced, 'pending': len(errors), 'errors': errors}


def record_enrichment(site: str, image_path: str, entry, *, session=None) -> dict:
    """Persist the complete enrichment locally and propagate it when online."""
    asset = snap_library.store_media(
        site, image_path,
        orig_name=getattr(entry, 'file', '') or os.path.basename(image_path),
    )
    asset['width'], asset['height'] = _dimensions(image_path)
    asset.update({
        'alt': getattr(entry, 'alt', '') or '',
        'title': getattr(entry, 'title', '') or '',
        'description': getattr(entry, 'caption', '') or '',
        'color_mode': getattr(entry, 'color_mode', '') or '',
        'status': 'staged',
        'source_ref': 'file:' + (getattr(entry, 'file', '') or os.path.basename(image_path)),
    })
    snap_library.upsert_asset(site, asset)

    prompt = getattr(entry, '_enrichment_prompt', '') or ''
    model = getattr(entry, '_enrichment_model', '') or 'sybu-manual'
    prompt_version = getattr(entry, '_enrichment_prompt_version', '') or '1'
    domain = (urlparse(site).hostname or site).lower().strip()
    image_hash = snap_enrichment_cache.image_sha256(image_path)
    saved = snap_enrichment_cache.put(
        site, image_hash, domain, model,
        snap_enrichment_cache.prompt_sha256(prompt), _bundle(entry),
        raw_response=getattr(entry, '_enrichment_raw_response', '') or '',
        accepted={key: True for key, value in _bundle(entry).items() if value != ''},
        prompt_version=prompt_version, source='desktop',
    )
    propagated = _push_cache(session, site, saved)
    return {'asset': asset, 'cache': saved, 'propagated': propagated}


def record_post_success(site: str, image_path: str, entry, post_id: int,
                        *, site_mode: str, post_type: str,
                        permalink: str = '', session=None) -> dict:
    """Attach a confirmed server post to the already-enriched shared asset."""
    saved = record_enrichment(site, image_path, entry, session=session)
    asset = saved['asset']
    asset['status'] = 'published'
    snap_library.upsert_asset(site, asset)
    return snap_library.record_post(site, {
        'post_id': int(post_id),
        'site_mode': site_mode,
        'post_type': post_type,
        'title': getattr(entry, 'title', '') or '',
        'body': getattr(entry, 'caption', '') or '',
        'permalink': permalink or '',
        'categories': [getattr(entry, 'category', '')] if getattr(entry, 'category', '') else [],
        'tags': _tags(entry),
        'source_tool': 'sybu',
        'source_ref': asset.get('source_ref', ''),
    }, [asset])


# ===== SNAPSMACK EOF =====
