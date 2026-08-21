// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — Offline library (per-blog local store + sync)
//
// GYSS is an OFFLINE sorter. This module is the on-disk per-blog library it sorts
// against — image records AND downloaded thumbnail files — so the app works with
// no network. The blog is touched only for two bounded sync events:
//   PULL  — gyss/library (full, or ?since=synced_at for a delta): upsert changed
//           images, refresh membership from the WHOLE maps, prune hard-deletes via
//           the current-id list, download missing/updated thumbs.
//   PUSH  — gyss/batch-update (unchanged; still handled by session.js).
//
// Storage (reuses the existing UTF-8 read_file/write_file + the binary
// download_to / delete_file Rust commands, plus catalog_sync for the shared
// SQLite vocab). GYSS is on the shared SnapSmack foundation now, so the per-site
// store lives under the shared root beside COLD SNAP / SYBU:
//   <root>\shared_library\<site_key>\
//       index.json         { images: { <id>: record } }
//       meta.json          { hostname, site_url, synced_at, site_mode, categories, albums, counts }
//       thumbs\<id>.<ext>  downloaded thumbnail files (shared across tools)
//       db\catalog.sqlite  the shared vocab catalog (categories/albums/tags/titles)
//
// <site_key> comes from siteKey() — the SAME algorithm as snap_home.site_key().
// Paths are built under sharedHome() so they land inside the Rust fs jail
// (resolve_in_root) that guards every file command.

import { invoke, convertFileSrc } from '@tauri-apps/api/core';
import { join } from '@tauri-apps/api/path';
import { sharedHome, siteKey } from './paths.js';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

// ── Paths ────────────────────────────────────────────────────────────────────

/**
 * Shared-library folder key for a site URL. Kept named hostnameFor for its
 * callers, but it now returns snap_home.site_key() so GYSS shares one folder per
 * blog with the Python tools, e.g. https://foreverphotograph.ing/ →
 * foreverphotograph.ing.
 */
export function hostnameFor(siteUrl) {
    try { return siteKey(siteUrl); }
    catch { return String(siteUrl || '').replace(/^https?:\/\//, '').replace(/\/.*$/, '').toLowerCase(); }
}

async function libraryDir(hostname) {
    return join(await sharedHome(), 'shared_library', hostname);
}
async function thumbsDir(hostname) { return join(await libraryDir(hostname), 'thumbs'); }
async function indexPath(hostname) { return join(await libraryDir(hostname), 'index.json'); }
async function metaPath(hostname)  { return join(await libraryDir(hostname), 'meta.json'); }

// ── Load / save ──────────────────────────────────────────────────────────────

async function readJson(path, fallback) {
    try { return JSON.parse(await invoke('read_file', { path })); }
    catch { return fallback; }
}

/** Load the full library for a host. Returns { index, meta } with safe defaults. */
export async function loadLibrary(hostname) {
    const index = await readJson(await indexPath(hostname), { images: {} });
    const meta  = await readJson(await metaPath(hostname),  {
        hostname, site_url: '', synced_at: null, site_mode: 'photoblog',
        categories: [], albums: [], counts: { images: 0 },
    });
    if (!index.images) index.images = {};
    return { index, meta };
}

async function saveLibrary(hostname, index, meta) {
    await invoke('write_file', { path: await indexPath(hostname), content: JSON.stringify(index) });
    await invoke('write_file', { path: await metaPath(hostname),  content: JSON.stringify(meta, null, 2) });
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/** [[image_id, x], …] → { image_id: [x, …] } */
function groupMap(pairs) {
    const out = {};
    for (const [imageId, x] of (pairs || [])) {
        (out[imageId] || (out[imageId] = [])).push(x);
    }
    return out;
}

/** Extension for a thumb's local filename, from its remote URL (default jpg). */
function extFromUrl(url) {
    const m = /\.([a-z0-9]{1,5})(?:[?#]|$)/i.exec(url || '');
    return m ? m[1].toLowerCase() : 'jpg';
}

/** Relative (to libraryDir) local thumb path for an image, or null. */
function thumbRel(image) {
    if (!image || !image.thumb_url) return null;
    return `thumbs/${image.id}.${extFromUrl(image.thumb_url)}`;
}

/**
 * Resolve an image to a renderable src: the local file if we have it, else the
 * remote thumb_url (graceful fallback — a still-downloading or failed thumb never
 * shows a broken image). Async because it needs the absolute path for the asset
 * protocol.
 */
export async function thumbSrc(hostname, image) {
    if (image && image.thumb_file) {
        try {
            const abs = await join(await libraryDir(hostname), image.thumb_file);
            // Blink port: convertFileSrc is async here (Python hands back a data:
            // URL). Await it so a read failure falls through to the remote thumb_url,
            // preserving the original graceful fallback.
            return await convertFileSrc(abs);
        } catch { /* fall through to remote */ }
    }
    return (image && image.thumb_url) || '';
}

// ── Sync ─────────────────────────────────────────────────────────────────────

/**
 * PULL: bring the local library up to date with the blog. Runs a full seed when
 * the library is empty (no synced_at), otherwise an incremental delta.
 *
 * @param {SnapSmackGYSSAPI} api      configured for this profile
 * @param {Object}           profile  { name, site_url }
 * @param {Object}           opts     { onProgress(phase, done, total) }
 * @returns {Object} summary { full, upserted, pruned, thumbsDownloaded, thumbsFailed, total }
 */
export async function syncLibrary(api, profile, opts = {}) {
    const onProgress = opts.onProgress || (() => {});
    const hostname   = hostnameFor(profile.site_url);
    const { index, meta } = await loadLibrary(hostname);
    const since = meta.synced_at || null;

    onProgress('pull', 0, 1);
    const resp = await api.library(since);   // throws on API/network error

    // 1. Upsert changed image records.
    let upserted = 0;
    for (const img of (resp.images || [])) {
        const prev = index.images[img.id] || {};
        const rel  = thumbRel(img);
        // A thumb needs (re)downloading when it's new, or the record changed and
        // we can't prove the existing file is still current — cheapest correct
        // rule is: re-fetch any image that arrived in this (changed) set.
        index.images[img.id] = {
            ...prev, ...img,
            category_ids: prev.category_ids || [],
            album_ids:    prev.album_ids || [],
            thumb_file:   prev.thumb_file || null,
            _thumb_rel:   rel,
            _needs_thumb: true,
        };
        upserted++;
    }

    // 2. Refresh membership for EVERY local image from the whole maps. This is why
    //    the server ships the maps whole: a re-tag mutates the map, not the image
    //    row, so it would never appear in a since-filtered image list.
    const catByImage = groupMap(resp.cat_map);
    const albByImage = groupMap(resp.album_map);
    for (const id of Object.keys(index.images)) {
        index.images[id].category_ids = catByImage[id] || [];
        index.images[id].album_ids    = albByImage[id] || [];
    }

    // 3. Prune hard-deletes: any local image not in the authoritative current-id
    //    list is gone from the blog. Drop the record and delete its thumb file.
    let pruned = 0;
    const current = new Set((resp.current_ids || []).map(Number));
    for (const id of Object.keys(index.images)) {
        if (!current.has(Number(id))) {
            const tf = index.images[id].thumb_file;
            if (tf) {
                try { await invoke('delete_file', { path: await join(await libraryDir(hostname), tf) }); }
                catch { /* orphaned file is harmless; leave it */ }
            }
            delete index.images[id];
            pruned++;
        }
    }

    // 4. Download thumbs for the changed set (native, CORS-free).
    const toFetch = Object.values(index.images).filter(im => im._needs_thumb && im.thumb_url && im._thumb_rel);
    let done = 0, thumbsDownloaded = 0, thumbsFailed = 0;
    const tdir = await thumbsDir(hostname);
    for (const im of toFetch) {
        const abs = await join(tdir, `${im.id}.${extFromUrl(im.thumb_url)}`);
        try {
            await invoke('download_to', { url: im.thumb_url, path: abs });
            im.thumb_file = im._thumb_rel;
            thumbsDownloaded++;
        } catch {
            thumbsFailed++;   // leave thumb_file as-is; thumbSrc falls back to remote
        }
        delete im._needs_thumb;
        delete im._thumb_rel;
        onProgress('thumbs', ++done, toFetch.length);
    }

    // 5. Persist meta (store the SERVER clock as the next since — never our own).
    meta.hostname   = hostname;
    meta.site_url   = profile.site_url;
    meta.synced_at  = resp.synced_at || meta.synced_at;
    meta.site_mode  = resp.site_mode || meta.site_mode;
    meta.categories = resp.categories || meta.categories || [];
    meta.albums     = resp.albums || meta.albums || [];
    meta.counts     = { images: Object.keys(index.images).length };

    await saveLibrary(hostname, index, meta);

    // 6. Mirror the vocab into the SHARED SQLite catalog — the same
    //    …/shared_library/<site_key>/db/catalog.sqlite that COLD SNAP / SYBU read
    //    (snap_library.py). Best-effort: a catalog hiccup never fails a good pull.
    //    (gyss/library ships categories/albums/site_mode; it does not ship a tags
    //    or titles vocabulary, so those land empty.)
    try {
        const dbPath = await join(await libraryDir(hostname), 'db', 'catalog.sqlite');
        await invoke('catalog_sync', {
            path: dbPath,
            payload: {
                site_url:   profile.site_url,
                site_mode:  resp.site_mode || meta.site_mode || '',
                categories: resp.categories || [],
                albums:     resp.albums || [],
                tags:       resp.tags || [],
                titles:     resp.titles || [],
            },
        });
    } catch { /* catalog mirror is best-effort */ }

    onProgress('done', 1, 1);

    return {
        full: !!resp.full,
        upserted, pruned, thumbsDownloaded, thumbsFailed,
        total: Object.keys(index.images).length,
    };
}

// ── Read side (for the render layer) ─────────────────────────────────────────

/**
 * All library images as a sorted array (sort_order asc, then id desc — same order
 * as gyss/photos), each with a resolved `src` ready for an <img>.
 */
export async function libraryImages(hostname, { categoryId = null, albumId = null } = {}) {
    const { index } = await loadLibrary(hostname);
    let rows = Object.values(index.images);
    if (categoryId !== null) rows = rows.filter(r => (r.category_ids || []).includes(categoryId));
    if (albumId !== null)    rows = rows.filter(r => (r.album_ids || []).includes(albumId));
    rows.sort((a, b) => (a.sort_order - b.sort_order) || (b.id - a.id));
    for (const r of rows) r.src = await thumbSrc(hostname, r);
    return rows;
}

/** Cheap status for the UI: does a library exist, how big, when last synced. */
export async function libraryStatus(hostname) {
    const { index, meta } = await loadLibrary(hostname);
    return {
        exists:    !!meta.synced_at,
        count:     Object.keys(index.images).length,
        synced_at: meta.synced_at,
        categories: meta.categories || [],
        albums:     meta.albums || [],
    };
}

// ===== SNAPSMACK EOF =====
