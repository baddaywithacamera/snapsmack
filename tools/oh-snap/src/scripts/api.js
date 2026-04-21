/**
 * OH SNAP! — SnapSmack API client
 * v0.1.0
 *
 * Thin wrapper around fetch() for all calls to the SnapSmack ohsnap-api.php
 * endpoints. All methods return plain objects or throw on failure.
 */

class SnapSmackAPI {

    /** @param {string} baseUrl  Trailing-slash site URL.
     *  @param {string} apiKey   Raw API key from the key management page. */
    constructor(baseUrl, apiKey) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
        this.apiKey  = apiKey;
    }

    // --- INTERNAL ---

    _endpoint(route) {
        return `${this.baseUrl}/api.php?route=${route}`;
    }

    async _get(route) {
        const res = await fetch(this._endpoint(route), {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                'Accept': 'application/json',
            },
        });
        const body = await res.json();
        if (!res.ok || !body.ok) {
            throw new Error(body.error || `HTTP ${res.status}`);
        }
        return body;
    }

    async _post(route, formData) {
        const res = await fetch(this._endpoint(route), {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                // No Content-Type — browser sets it with boundary for FormData
            },
            body: formData,
        });
        const body = await res.json();
        if (!res.ok || !body.ok) {
            throw new Error(body.error || `HTTP ${res.status}`);
        }
        return body;
    }

    // --- ENDPOINTS ---

    /** Verify the connection. Returns { site_name, tagline, active_skin, version, base_url }. */
    async ping() {
        return this._get('ohsnap/ping');
    }

    /** Full site config. Returns { site_name, tagline, base_url, active_skin, skin_version, version }. */
    async config() {
        return this._get('ohsnap/config');
    }

    /** Recent posts. Returns { posts: [...], count }. */
    async posts() {
        return this._get('ohsnap/posts');
    }

    /** Recent images. Returns { images: [...], count }. */
    async media() {
        return this._get('ohsnap/media');
    }

    /** Active skin files. Returns { skin_slug, manifest, style_css, css_variables, oh_snap_ready }. */
    async skin() {
        return this._get('ohsnap/skin');
    }

    /** Push a skin zip to the site.
     *  @param {Blob|File} zipBlob  The skin zip.
     *  @param {boolean}   activate Whether to activate immediately.
     *  Returns { skin_slug, skin_name, version, activated }. */
    async pushSkin(zipBlob, activate = false) {
        const fd = new FormData();
        fd.append('skin_zip', zipBlob, 'skin.zip');
        fd.append('activate', activate ? '1' : '0');
        return this._post('ohsnap/skin/push', fd);
    }

    /** Push CSS variable overrides to the active skin on the site.
     *  Changes are stored in snap_settings and injected at render time.
     *  @param {Object} vars  { '--css-var-name': 'value', ... }
     *  Returns { skin_slug, vars_count, stored_key }. */
    async pushVars(vars) {
        const res = await fetch(this._endpoint('ohsnap/skin/vars'), {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                'Content-Type':  'application/json',
                'Accept':        'application/json',
            },
            body: JSON.stringify({ vars }),
        });
        const body = await res.json();
        if (!res.ok || !body.ok) {
            throw new Error(body.error || `HTTP ${res.status}`);
        }
        return body;
    }
}

// Expose globally — no bundler yet.
window.SnapSmackAPI = SnapSmackAPI;
