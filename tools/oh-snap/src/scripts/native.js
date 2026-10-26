/** SNAPSMACK_EOF_HEADER: explicit native bridge; preserve EOF marker. */
const OhSnapNative = (() => {
    const available = () => Boolean(window.__TAURI__?.core?.invoke);
    const invoke = (command, args) => window.__TAURI__.core.invoke(command, args);
    const save = options => invoke('plugin:dialog|save', { options });
    const open = options => invoke('plugin:dialog|open', { options });
    async function confirm(message) {
        return available() ? (await invoke('confirm_action', { message })) === true : window.confirm(message) === true;
    }
    return { available, invoke, save, open, confirm };
})();
// ===== SNAPSMACK EOF =====
