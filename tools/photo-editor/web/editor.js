/* SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical JS marker. */
(() => {
  "use strict";
  const operations = [];
  const redo = [];
  const byId = (id) => document.getElementById(id);
  const status = (text) => { byId("status").textContent = text; };
  const refreshHistory = () => { byId("undo").disabled = !operations.length; byId("redo").disabled = !redo.length; };
  const add = (operation) => { operations.push(operation); redo.length = 0; refreshHistory(); status(`${operations.length} edit${operations.length === 1 ? "" : "s"}`); };
  document.querySelectorAll("[data-op]").forEach((button) => button.addEventListener("click", () => add({ type: button.dataset.op })));
  byId("resize").addEventListener("click", () => {
    const width = Number(byId("width").value), height = Number(byId("height").value);
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1) return status("Enter a valid width and height.");
    add({ type: "resize", width, height });
  });
  byId("undo").addEventListener("click", () => { if (operations.length) redo.push(operations.pop()); refreshHistory(); status("Edit undone"); });
  byId("redo").addEventListener("click", () => { if (redo.length) operations.push(redo.pop()); refreshHistory(); status("Edit restored"); });
  byId("reset").addEventListener("click", () => { redo.push(...operations.splice(0)); refreshHistory(); status("Edits reset"); });
  byId("open").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("choose_image", {}); status(result ? "Image opened" : "Open cancelled"); } catch (error) { status(error.message); } });
  byId("save").addEventListener("click", async () => { try { await window.SnapSmackEditorHost.call("export_copy", { operations }); status("Copy saved"); } catch (error) { status(error.message); } });
  document.addEventListener("keydown", (event) => { if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "z") return; event.preventDefault(); byId(event.shiftKey ? "redo" : "undo").click(); });
})();
// ===== SNAPSMACK EOF =====
