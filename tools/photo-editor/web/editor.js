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
    byId("crop-width").value = result.width; byId("crop-height").value = result.height;
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
  byId("ratio").addEventListener("change", () => {
    const ratio = byId("ratio").value;
    if (ratio === "free") return;
    const [wide, high] = ratio.split(":").map(Number);
    const width = Number(byId("width").value), height = Number(byId("height").value);
    let cropWidth = width, cropHeight = Math.round(width * high / wide);
    if (cropHeight > height) { cropHeight = height; cropWidth = Math.round(height * wide / high); }
    byId("crop-width").value = cropWidth; byId("crop-height").value = cropHeight;
    byId("crop-x").value = Math.floor((width - cropWidth) / 2); byId("crop-y").value = Math.floor((height - cropHeight) / 2);
  });
  byId("crop").addEventListener("click", () => {
    const values = ["crop-x", "crop-y", "crop-width", "crop-height"].map((id) => Number(byId(id).value));
    if (!values.every(Number.isInteger) || values[0] < 0 || values[1] < 0 || values[2] < 1 || values[3] < 1) return status("Enter a valid crop rectangle.");
    void add({ type: "crop", x: values[0], y: values[1], width: values[2], height: values[3] });
  });
  byId("angle").addEventListener("input", () => { byId("angle-value").textContent = `${Number(byId("angle").value).toFixed(1)}°`; });
  byId("straighten").addEventListener("click", () => { const degrees = Number(byId("angle").value); if (degrees === 0) return status("Choose a straighten angle first."); void add({ type: "straighten", degrees }); });
  document.querySelectorAll("[data-reset]").forEach((button) => button.addEventListener("click", () => { document.querySelector(`[data-darkroom='${button.dataset.reset}']`).value = 0; }));
  byId("apply-darkroom").addEventListener("click", async () => {
    const settings = {};
    document.querySelectorAll("[data-darkroom]").forEach((input) => { settings[input.dataset.darkroom] = Number(input.value); });
    if (Object.values(settings).every((value) => value === 0)) return status("Set at least one darkroom adjustment.");
    await add({ type: "darkroom", settings });
    document.querySelectorAll("[data-darkroom]").forEach((input) => { input.value = 0; });
  });
  const number = (id) => Number(byId(id).value);
  byId("apply-levels").addEventListener("click", () => void add({ type: "levels", settings: { black: number("level-black"), gamma: number("level-gamma"), white: number("level-white"), output_black: 0, output_white: 1 } }));
  byId("apply-bw").addEventListener("click", () => void add({ type: "black-and-white", red: number("bw-red"), green: number("bw-green"), blue: number("bw-blue") }));
  byId("apply-vignette").addEventListener("click", () => void add({ type: "vignette", amount: number("vignette-amount"), midpoint: number("vignette-midpoint"), feather: number("vignette-feather") }));
  byId("apply-sharpen").addEventListener("click", () => void add({ type: "sharpen", amount: number("sharpen-amount"), threshold: number("sharpen-threshold") }));
  byId("apply-noise").addEventListener("click", () => void add({ type: "noise-reduction", luminance: number("nr-luminance"), chroma: number("nr-chroma") }));
  byId("undo").addEventListener("click", () => { if (operations.length) { redo.push(operations.pop()); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("redo").addEventListener("click", () => { if (redo.length) { operations.push(redo.pop()); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("reset").addEventListener("click", () => { redo.push(...operations.splice(0)); refreshHistory(); void render().catch((error) => status(error.message)); });
  byId("before").addEventListener("click", () => { if (opened) { showingBefore = !showingBefore; void render().catch((error) => status(error.message)); } });
  byId("open").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("choose_image", {}); if (!result) return status("Open cancelled"); operations.length = 0; redo.length = 0; opened = true; showingBefore = false; refreshHistory(); showResult(result); status(`Opened ${result.name}`); } catch (error) { status(error.message); } });
  byId("save").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("export_copy", { operations }); status(result ? `Saved ${result.output_path}` : "Save cancelled"); } catch (error) { status(error.message); } });
  document.addEventListener("keydown", (event) => { if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "z") return; event.preventDefault(); byId(event.shiftKey ? "redo" : "undo").click(); });
  window.SnapSmackEditorHost.call("recover", {}).then((result) => { if (!result) return; operations.push(...(result.operations || [])); opened = true; refreshHistory(); showResult(result); status(`Recovered ${result.name}`); }).catch(() => {});
})();
// ===== SNAPSMACK EOF =====
