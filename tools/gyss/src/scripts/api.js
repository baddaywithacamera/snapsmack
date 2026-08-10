// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — SnapSmack API client
// Adapted from tools/oh-snap/src/scripts/api.js
// Adds GYSS-specific methods: ping, photos, meta, batchUpdate.
// HTTP calls go directly from the webview to the blog — no Rust proxy.
// gyss-api.php emits CORS headers for tauri:// origins.

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

export class SnapSmackGYSSAPI {
    constructor(siteUrl, apiKey) {
        this.baseUrl = siteUrl.replace(/\/$/, '');
        this.apiKey  = apiKey;
    }

    /** Low-level fetch wrapper. Returns parsed JSON or throws with .message */
    async _call(method, endpoint, params = null, body = null) {
        let url = `${this.baseUrl}/api.php?route=gyss/${endpoint}`;
        if (params) {
            const qs = new URLSearchParams(params);
            url += '&' + qs.toString();
        }

        const opts = {
            method,
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                'Content-Type':  'application/json',
            },
        };
        if (body !== null) {
            opts.body = JSON.stringify(body);
        }

        let res;
        try {
            res = await fetch(url, opts);
        } catch (err) {
            throw new Error(`Network error: ${err.message}`);
        }

        let data;
        try {
            data = await res.json();
        } catch {
            throw new Error(`Server returned non-JSON response (HTTP ${res.status})`);
        }

        if (!data.ok) {
            throw new Error(data.error || `API error (HTTP ${res.status})`);
        }
        return data;
    }

    /** GET gyss/ping — connection test */
    async ping() {
        return this._call('GET', 'ping');
    }

    /**
     * GET gyss/photos — filtered photo export
     * @param {Object} filters  { date_from, date_to, category_id, album_id, limit, offset }
     */
    async photos(filters = {}) {
        const params = {};
        if (filters.date_from)   params.date_from   = filters.date_from;
        if (filters.date_to)     params.date_to     = filters.date_to;
        if (filters.category_id) params.category_id = filters.category_id;
        if (filters.album_id)    params.album_id    = filters.album_id;
        if (filters.limit)       params.limit       = filters.limit;
        if (filters.offset)      params.offset      = filters.offset;
        return this._call('GET', 'photos', params);
    }

    /** GET gyss/meta — categories and albums for dropdowns */
    async meta() {
        return this._call('GET', 'meta');
    }

    /**
     * GET gyss/library — offline-sorter bulk export: image records (all, or only
     * those changed since a timestamp), the WHOLE category/album lists + membership
     * maps, and the full current-id list for pruning. This is what the on-disk
     * library is built and synced from.
     * @param {string|null} since  the server `synced_at` from the last pull, or
     *                             null/omitted for a full pull.
     */
    async library(since = null) {
        const params = {};
        if (since) params.since = since;
        return this._call('GET', 'library', params);
    }

    /** Read-only scan for published photos with missing enrichment fields. */
    async enrichmentAudit(limit = 1000) {
        return this._call('GET', 'enrichment-audit', { limit });
    }

    /** Enrich exactly one photo. Queueing always remains in the desktop app. */
    async enrichOne(id, prompt, fields, overwrite = false) {
        return this._call('POST', 'enrich-one', null, {
            id, prompt, fields, overwrite
        });
    }

    /**
     * POST gyss/batch-update — push dirty records back
     * @param {Array} updates  Array of update objects (see gyss-api.php docs)
     */
    async batchUpdate(updates) {
        return this._call('POST', 'batch-update', null, { updates });
    }

    // ── GRAMOFSMACK (carousel-mode) ──────────────────────────────────────────

    /** GET gyss/gram-posts — the grid feed as posts (cover thumb + count). */
    async gramPosts(limit = 500) {
        return this._call('GET', 'gram-posts', { limit });
    }

    /**
     * POST gyss/gram-reorder — write the feed order.
     * @param {number[]} ids  post ids in the new visible order
     */
    async gramReorder(ids) {
        return this._call('POST', 'gram-reorder', null, { ids });
    }

    /**
     * POST gyss/gram-carousel — combine selected single posts into one carousel.
     * @param {number[]} ids           post ids, in carousel order
     * @param {number}   coverPostId   which selected post is the cover
     */
    async gramCarousel(ids, coverPostId) {
        return this._call('POST', 'gram-carousel', null, { ids, cover_post_id: coverPostId });
    }
}

// ===== SNAPSMACK EOF =====
