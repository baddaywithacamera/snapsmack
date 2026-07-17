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
     * POST gyss/batch-update — push dirty records back
     * @param {Array} updates  Array of update objects (see gyss-api.php docs)
     */
    async batchUpdate(updates) {
        return this._call('POST', 'batch-update', null, { updates });
    }
}

// ===== SNAPSMACK EOF =====
