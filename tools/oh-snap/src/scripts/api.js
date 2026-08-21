/**
 * OH SNAP! — SnapSmack API client
 * v0.1.0
 *
 * Thin wrapper around fetch() for all calls to the SnapSmack ohsnap-api.php
 * endpoints. All methods return plain objects or throw on failure.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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

    /** Installed skin picker metadata. */
    async skins() { return this._get('ohsnap/skins'); }

    /** Active skin files. Returns { skin_slug, manifest, style_css, css_variables, oh_snap_ready }. */
    async skin(slug = '') {
        return this._get(`ohsnap/skin${slug ? `&slug=${encodeURIComponent(slug)}` : ''}`);
    }

    /**
     * Shared resources library (the asset inventory the whole tool suite reads).
     * This is the ONE fetch for the library — it does NOT use _get()/throw,
     * because the four outcomes must stay distinguishable so the caller can act
     * oppositely on each (the manifest schema is a cross-tool contract).
     *
     * Returns one of:
     *   { state: 'present',     schemaVersion, library, counts }
     *   { state: 'incomplete',  message, problems }   // partial manifest — fail closed
     *   { state: 'unsupported', message, schemaVersion } // schema major we don't know
     *   { state: 'failed',      message, httpStatus }  // missing / unreachable / bad JSON
     */
    async library() {
        // The client understands this manifest MAJOR. A newer major means the
        // site shipped a breaking schema change this build predates — refuse it
        // rather than mis-read it. A newer MINOR is forward-compatible (we just
        // ignore fields we don't know).
        const KNOWN_SCHEMA_MAJOR = 1;

        let res, body;
        try {
            res  = await fetch(this._endpoint('ohsnap/library'), {
                method: 'GET',
                headers: { 'Authorization': `Bearer ${this.apiKey}`, 'Accept': 'application/json' },
            });
            body = await res.json();
        } catch (err) {
            return { state: 'failed', message: err.message || 'Could not reach the shared library.', httpStatus: 0 };
        }

        // Incomplete manifest: the server validated it and refused (409), or said so.
        if (res.status === 409 || body?.incomplete) {
            return {
                state: 'incomplete',
                message: body?.error || 'The site served an incomplete shared library.',
                problems: Array.isArray(body?.problems) ? body.problems : [],
            };
        }

        // Anything else that isn't a clean success is a failure (404 missing,
        // 500 bad JSON, 401 auth, older site with no library route, …).
        if (!res.ok || !body?.ok) {
            return { state: 'failed', message: body?.error || `HTTP ${res.status}`, httpStatus: res.status };
        }

        const schemaVersion = String(body.schema_version || '');
        const major = parseInt(schemaVersion.split('.')[0], 10);
        if (!Number.isFinite(major) || major !== KNOWN_SCHEMA_MAJOR) {
            return {
                state: 'unsupported',
                message: `This site's shared library is schema ${schemaVersion || '(unknown)'}, which this version of Oh Snap! doesn't understand. Update Oh Snap!.`,
                schemaVersion,
            };
        }

        return {
            state: 'present',
            schemaVersion,
            engines: Array.isArray(body.engines) ? body.engines : [], // curated skin-facing list, with handles
            library: body.library || {},                              // full asset inventory (incl. CSS)
            counts: body.counts || {},
        };
    }

    // pushSkin()/ohsnap/skin/push removed 2026-08-09 (security). A skin ZIP is
    // never uploaded to the server: receiving and unzipping an untrusted archive
    // server-side is remote-code-execution surface. A finished skin is saved to
    // disk / emailed for the owner to examine locally, then it enters via git.
    // (This method was dead wiring — nothing here builds or sends a skin zip.)

    /** Push CSS variable overrides to the active skin on the site.
     *  Changes are stored in snap_settings and injected at render time.
     *  @param {Object} vars  { '--css-var-name': 'value', ... }
     *  Returns { skin_slug, vars_count, stored_key }. */
    async pushVars(skinSlug, vars) {
        const res = await fetch(this._endpoint('ohsnap/skin/vars'), {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,
                'Content-Type':  'application/json',
                'Accept':        'application/json',
            },
            body: JSON.stringify({ skin_slug: skinSlug, vars }),
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
// ===== SNAPSMACK EOF =====
