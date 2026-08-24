/* SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical JS marker. */
(() => {
  "use strict";
  const operations = [];
  const redo = [];
  let opened = false;
  let showingBefore = false;
  const byId = (id) => document.getElementById(id);
  const status = (text) => { byId("status").textContent = text; };
  const refreshHistory = () => { byId("undo").disabled = !operations.length; byId("redo").disabled = !redo.length; };
  const drawHistogram = (histogram) => {
    if (!histogram) return;
    const canvas = byId("histogram"), context = canvas.getContext("2d");
    context.clearRect(0, 0, canvas.width, canvas.height);
    const maximum = Math.max(1, ...histogram.red, ...histogram.green, ...histogram.blue);
    [[histogram.red, "#ff456d"], [histogram.green, "#4ddd85"], [histogram.blue, "#55a7ff"]].forEach(([values, color]) => {
      context.beginPath(); context.strokeStyle = color; context.globalAlpha = 0.75;
      values.forEach((value, index) => { const y = canvas.height - (value / maximum) * canvas.height; index ? context.lineTo(index, y) : context.moveTo(index, y); });
      context.stroke();
    });
    context.globalAlpha = 1;
  };
  const showResult = (result) => {
    if (!result) return;
    const image = byId("preview"); image.src = result.preview; image.hidden = false;
    byId("stage").querySelector("p").hidden = true;
    byId("width").value = result.width; byId("height").value = result.height;
    drawHistogram(result.histogram);
  };
  const render = async () => {
    if (!opened) return;
    status("Rendering preview…");
    const result = await window.SnapSmackEditorHost.call("render_preview", { operations: showingBefore ? [] : operations });
    showResult(result); status(showingBefore ? "Showing original" : `${operations.length} edit${operations.length === 1 ? "" : "s"}`);
  };
  const add = async (operation) => { if (!opened) return status("Open an image first."); operations.push(operation); redo.length = 0; showingBefore = false; refreshHistory(); try { await render(); } catch (error) { operations.pop(); refreshHistory(); status(error.message); } };
  document.querySelectorAll("[data-op]").forEach((button) => button.addEventListener("click", () => void add({ type: button.dataset.op })));
  byId("resize").addEventListener("click", () => {
    const width = Number(byId("width").value), height = Number(byId("height").value);
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1) return status("Enter a valid width and height.");
    void add({ type: "resize", width, height });
  });
  byId("undo").addEventListener("click", () => { if (operations.length) { redo.push(operations.pop()); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("redo").addEventListener("click", () => { if (redo.length) { operations.push(redo.pop()); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("reset").addEventListener("click", () => { redo.push(...operations.splice(0)); refreshHistory(); void render().catch((error) => status(error.message)); });
  byId("before").addEventListener("click", () => { if (opened) { showingBefore = !showingBefore; void render().catch((error) => status(error.message)); } });
  byId("open").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("choose_image", {}); if (!result) return status("Open cancelled"); operations.length = 0; redo.length = 0; opened = true; showingBefore = false; refreshHistory(); showResult(result); status(`Opened ${result.name}`); } catch (error) { status(error.message); } });
  byId("save").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("export_copy", { operations }); status(result ? `Saved ${result.output_path}` : "Save cancelled"); } catch (error) { status(error.message); } });
  document.addEventListener("keydown", (event) => { if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "z") return; event.preventDefault(); byId(event.shiftKey ? "redo" : "undo").click(); });
})();
// ===== SNAPSMACK EOF =====
