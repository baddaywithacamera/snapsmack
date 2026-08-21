/** SNAPSMACK_EOF_HEADER — credential-vault bridge (Linux Blink port).
 *  Was window.__TAURI__ OS keyring; now blink.call() to the Python side.
 *  Command names are unchanged (vault_set / vault_get / vault_delete). */
const OhSnapVault = (() => {
    const available = () => Boolean(window.blink?.call);
    async function set(account, secret) { if (!available()) return false; await window.blink.call('vault_set', account, secret); return true; }
    async function get(account) { if (!available()) return null; return window.blink.call('vault_get', account); }
    async function remove(account) { if (!available()) return; await window.blink.call('vault_delete', account); }
    return { available, set, get, remove };
})();
// ===== SNAPSMACK EOF =====
