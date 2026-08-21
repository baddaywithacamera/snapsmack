/** SNAPSMACK_EOF_HEADER — OS credential-vault bridge. */
const OhSnapVault = (() => {
    const available = () => Boolean(window.__TAURI__?.core?.invoke);
    async function set(account, secret) { if (!available()) return false; await window.__TAURI__.core.invoke('vault_set', { account, secret }); return true; }
    async function get(account) { if (!available()) return null; return window.__TAURI__.core.invoke('vault_get', { account }); }
    async function remove(account) { if (!available()) return; await window.__TAURI__.core.invoke('vault_delete', { account }); }
    return { available, set, get, remove };
})();
// ===== SNAPSMACK EOF =====
