/* SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical JS marker. */
(() => {
  "use strict";
  const operations = [];
  const undoStack = [];
  const redoStack = [];
  let opened = false;
  let showingBefore = false;
  let renderGeneration = 0;
  let selectedTextLayer = null;
  let selectedOverlayLayer = null;
  let overlayAsset = null;
  const selectedForGroup = new Set();
  let selectedStyleLayer = null;
  let selectedStyleIndex = null;
  let copiedStyles = null;
  const byId = (id) => document.getElementById(id);
  const status = (text) => { byId("status").textContent = text; };
  document.querySelectorAll("aside fieldset").forEach((fieldset) => {
    const legend = fieldset.querySelector(":scope > legend"); if (!legend) return;
    const details = document.createElement("details"), summary = document.createElement("summary");
    summary.textContent = legend.textContent; legend.remove(); fieldset.classList.add("accordion-body");
    fieldset.replaceWith(details); details.append(summary, fieldset);
  });
  const resetLayerEditors = () => { selectedTextLayer = null; selectedOverlayLayer = null; selectedStyleLayer = null; selectedStyleIndex = null; selectedForGroup.clear(); byId("make-text-layer").textContent = "Add text layer"; byId("make-overlay-layer").textContent = "Add image overlay"; byId("save-style").textContent = "Add style"; byId("style-target").textContent = "Choose Styles on a text or image layer"; refreshStyles(); };
  const refreshStyles = () => {
    const list = byId("styles"); if (!list) return; list.innerHTML = "";
    const layer = selectedStyleLayer === null ? null : operations[selectedStyleLayer];
    if (!layer || !["text-layer", "image-overlay-layer"].includes(layer.type)) return;
    layer.styles ||= []; byId("style-target").textContent = layer.name;
    layer.styles.forEach((style, index) => {
      const item = document.createElement("li"); item.textContent = `${style.enabled ? "●" : "○"} ${style.type}`;
      const actions = document.createElement("div"); actions.className = "layer-actions";
      [["Edit", () => { selectedStyleIndex = index; byId("style-type").value = style.type; byId("style-color").value = `#${style.color.slice(0, 3).map((value) => value.toString(16).padStart(2, "0")).join("")}`; byId("style-alpha").value = style.color[3] / 255; if (style.size !== undefined) byId("style-size").value = style.size; if (style.position) byId("style-position").value = style.position; if (style.offset_x !== undefined) byId("style-offset-x").value = style.offset_x; if (style.offset_y !== undefined) byId("style-offset-y").value = style.offset_y; if (style.blur !== undefined) byId("style-blur").value = style.blur; if (style.spread !== undefined) byId("style-spread").value = style.spread; if (style.intensity !== undefined) byId("style-intensity").value = style.intensity; byId("save-style").textContent = "Update style"; }], [style.enabled ? "Disable" : "Enable", () => { style.enabled = !style.enabled; }], ["Up", () => { if (index > 0) [layer.styles[index - 1], layer.styles[index]] = [layer.styles[index], layer.styles[index - 1]]; }], ["Down", () => { if (index < layer.styles.length - 1) [layer.styles[index + 1], layer.styles[index]] = [layer.styles[index], layer.styles[index + 1]]; }], ["Delete", () => { layer.styles.splice(index, 1); selectedStyleIndex = null; byId("save-style").textContent = "Add style"; }]].forEach(([label, action]) => { const button = document.createElement("button"); button.textContent = label; button.addEventListener("click", () => { if (label !== "Edit") recordHistory(); action(); refreshStyles(); refreshLayers(); if (label !== "Edit") void render().catch((error) => status(error.message)); }); actions.appendChild(button); });
      item.appendChild(actions); list.appendChild(item);
    });
  };
  const refreshLayers = () => {
    const list = byId("layers"); list.innerHTML = "";
    operations.forEach((operation, index) => {
      if (!operation.type.endsWith("-layer") && operation.type !== "layer-group") return;
      const item = document.createElement("li");
      const title = document.createElement("div"); title.textContent = `${operation.visible ? "●" : "○"} ${operation.name} · ${operation.blend_mode}${operation.styles?.length ? ` · ${operation.styles.length} fx` : ""}`;
      const selector = document.createElement("label"); selector.className = "layer-select";
      const checkbox = document.createElement("input"); checkbox.type = "checkbox"; checkbox.checked = selectedForGroup.has(index); checkbox.setAttribute("aria-label", `Select ${operation.name} for grouping`); checkbox.addEventListener("change", () => { if (checkbox.checked) selectedForGroup.add(index); else selectedForGroup.delete(index); }); selector.append(checkbox, title);
      const actions = document.createElement("div"); actions.className = "layer-actions";
      const layerActions = [[operation.visible ? "Hide" : "Show", () => { operation.visible = !operation.visible; }], [operation.locked ? "Unlock" : "Lock", () => { operation.locked = !operation.locked; }], ["Duplicate", () => { operations.splice(index + 1, 0, structuredClone(operation)); }], ["Up", () => { if (!operation.locked && index < operations.length - 1) [operations[index], operations[index + 1]] = [operations[index + 1], operations[index]]; }], ["Down", () => { if (!operation.locked && index > 0) [operations[index], operations[index - 1]] = [operations[index - 1], operations[index]]; }], ["Delete", () => { if (!operation.locked) operations.splice(index, 1); }]];
      if (operation.type === "text-layer") layerActions.unshift(["Edit", () => { selectedOverlayLayer = null; selectedTextLayer = index; byId("text-content").value = operation.text; byId("text-font").value = operation.font_family; byId("text-size").value = operation.font_size; byId("text-color").value = `#${operation.color.slice(0, 3).map((value) => value.toString(16).padStart(2, "0")).join("")}`; byId("text-x").value = operation.x; byId("text-y").value = operation.y; byId("text-align").value = operation.alignment; byId("text-blend").value = operation.blend_mode; byId("text-opacity").value = operation.opacity; byId("make-text-layer").textContent = "Update text layer"; byId("make-overlay-layer").textContent = "Add image overlay"; }]);
      if (operation.type === "image-overlay-layer") layerActions.unshift(["Edit", () => { selectedTextLayer = null; selectedOverlayLayer = index; overlayAsset = { name: operation.original_name, data_uri: operation.data_uri }; byId("overlay-name").textContent = operation.original_name; byId("overlay-width").value = operation.width; byId("overlay-height").value = operation.height; byId("overlay-rotation").value = operation.rotation_degrees; byId("overlay-x").value = operation.x; byId("overlay-y").value = operation.y; byId("overlay-blend").value = operation.blend_mode; byId("overlay-opacity").value = operation.opacity; byId("make-overlay-layer").textContent = "Update image overlay"; byId("make-text-layer").textContent = "Add text layer"; }]);
      if (["text-layer", "image-overlay-layer"].includes(operation.type)) layerActions.unshift(["Styles", () => { selectedStyleLayer = index; selectedStyleIndex = null; byId("save-style").textContent = "Add style"; refreshStyles(); }]);
      if (operation.type === "layer-group") layerActions.unshift(["Ungroup", () => { if (!operation.locked) operations.splice(index, 1, ...operation.operations); }]);
      layerActions.forEach(([label, action]) => { const button = document.createElement("button"); button.textContent = label; button.addEventListener("click", () => { const uiOnly = label === "Edit" || label === "Styles"; if (!uiOnly) { recordHistory(); resetLayerEditors(); } action(); refreshHistory(); if (!uiOnly) void render().catch((error) => status(error.message)); }); actions.appendChild(button); });
      item.append(selector, actions); list.appendChild(item);
    });
  };
  const refreshHistory = () => { byId("undo").disabled = !undoStack.length; byId("redo").disabled = !redoStack.length; refreshLayers(); };
  const recordHistory = () => { undoStack.push(structuredClone(operations)); if (undoStack.length > 100) undoStack.shift(); redoStack.length = 0; };
  const restore = (snapshot) => { operations.splice(0, operations.length, ...structuredClone(snapshot)); };
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
    const generation = ++renderGeneration;
    status("Rendering preview…");
    const result = await window.SnapSmackEditorHost.call("render_preview", { operations: showingBefore ? [] : operations, generation });
    if (generation !== renderGeneration) return;
    showResult(result); status(showingBefore ? "Showing original" : `${operations.length} edit${operations.length === 1 ? "" : "s"}`);
  };
  const add = async (operation) => { if (!opened) return status("Open an image first."); recordHistory(); operations.push(operation); showingBefore = false; refreshHistory(); try { await render(); } catch (error) { restore(undoStack.pop()); refreshHistory(); status(error.message); } };
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
  document.querySelectorAll("[data-optics-reset]").forEach((button) => button.addEventListener("click", () => { byId(button.dataset.opticsReset).value = 0; }));
  byId("apply-darkroom").addEventListener("click", async () => {
    const settings = {};
    document.querySelectorAll("[data-darkroom]").forEach((input) => { settings[input.dataset.darkroom] = Number(input.value); });
    if (Object.values(settings).every((value) => value === 0)) return status("Set at least one darkroom adjustment.");
    await add({ type: "darkroom", settings });
    document.querySelectorAll("[data-darkroom]").forEach((input) => { input.value = 0; });
  });
  byId("apply-optics").addEventListener("click", () => {
    const distortion = number("lens-distortion"), red_shift = number("lens-red"), blue_shift = number("lens-blue");
    if ([distortion, red_shift, blue_shift].some((value) => !Number.isFinite(value) || value < -1 || value > 1)) return status("Optics controls must be between -1 and 1.");
    if (distortion === 0 && red_shift === 0 && blue_shift === 0) return status("Set at least one optics correction.");
    void add({ type: "lens-correction", distortion, red_shift, blue_shift });
  });
  const number = (id) => Number(byId(id).value);
  byId("apply-levels").addEventListener("click", () => void add({ type: "levels", settings: { black: number("level-black"), gamma: number("level-gamma"), white: number("level-white"), output_black: 0, output_white: 1 } }));
  byId("apply-bw").addEventListener("click", () => void add({ type: "black-and-white", red: number("bw-red"), green: number("bw-green"), blue: number("bw-blue") }));
  byId("apply-vignette").addEventListener("click", () => void add({ type: "vignette", amount: number("vignette-amount"), midpoint: number("vignette-midpoint"), feather: number("vignette-feather") }));
  byId("apply-grain").addEventListener("click", () => {
    const amount = number("grain-amount"), size = number("grain-size"), roughness = number("grain-roughness"), seed = number("grain-seed");
    if (!Number.isFinite(amount) || amount <= 0 || amount > 1 || !Number.isFinite(size) || size < 0.5 || size > 8 || !Number.isFinite(roughness) || roughness < 0 || roughness > 1 || !Number.isSafeInteger(seed) || seed < 0) return status("Check the film grain settings and set an amount above zero.");
    void add({ type: "film-grain", amount, size, roughness, seed });
  });
  byId("apply-sharpen").addEventListener("click", () => void add({ type: "sharpen", amount: number("sharpen-amount"), threshold: number("sharpen-threshold") }));
  byId("apply-noise").addEventListener("click", () => void add({ type: "noise-reduction", luminance: number("nr-luminance"), chroma: number("nr-chroma") }));
  byId("apply-local-contrast").addEventListener("click", () => void add({ type: "local-contrast", amount: number("local-contrast") }));
  byId("apply-haze").addEventListener("click", () => void add({ type: "haze-reduction", amount: number("haze") }));
  byId("apply-curve").addEventListener("click", () => void add({ type: "tone-curve", shadows: number("curve-shadows"), midtones: number("curve-midtones"), highlights: number("curve-highlights") }));
  byId("apply-hsl").addEventListener("click", () => void add({ type: "hsl", hue_degrees: number("hsl-hue"), saturation: number("hsl-saturation"), lightness: number("hsl-lightness") }));
  byId("apply-preset").addEventListener("click", () => {
    const preset = byId("preset").value;
    if (preset === "punch") return void add({ type: "darkroom", settings: { exposure: 0.2, contrast: 0.25, highlights: -0.1, shadows: 0.1, whites: 0.05, blacks: -0.05, temperature: 0, tint: 0, saturation: 0.05, vibrance: 0.2 } });
    if (preset === "soft") return void add({ type: "darkroom", settings: { exposure: 0.15, contrast: -0.05, highlights: -0.2, shadows: 0.25, whites: 0, blacks: 0.05, temperature: 0.08, tint: 0.02, saturation: -0.05, vibrance: 0.1 } });
    if (preset === "mono") return void add({ type: "black-and-white", red: 0.3, green: 0.6, blue: 0.1 });
    status("Choose a preset first.");
  });
  byId("make-layer").addEventListener("click", () => {
    if (!operations.length) return status("Make an edit first.");
    recordHistory(); const edit = operations.pop();
    operations.push({ type: "adjustment-layer", name: byId("layer-name").value.trim() || "Adjustment", visible: true, locked: false, opacity: number("layer-opacity"), fill_opacity: number("layer-fill"), blend_mode: byId("layer-blend").value, operations: [edit] });
    refreshHistory(); void render().catch((error) => status(error.message));
  });
  byId("make-text-layer").addEventListener("click", () => {
    if (!opened) return status("Open an image first.");
    const text = byId("text-content").value;
    const size = number("text-size"), opacity = number("text-opacity");
    if (!text.trim()) return status("Enter some text first.");
    if (!Number.isFinite(size) || size < 4 || size > 2048 || !Number.isFinite(opacity) || opacity < 0 || opacity > 1) return status("Check the text size and opacity.");
    const hex = byId("text-color").value;
    const color = [Number.parseInt(hex.slice(1, 3), 16), Number.parseInt(hex.slice(3, 5), 16), Number.parseInt(hex.slice(5, 7), 16), 255];
    const layer = { type: "text-layer", name: text.split(/\r?\n/, 1)[0].slice(0, 40) || "Text", visible: true, locked: false, opacity, fill_opacity: 1, blend_mode: byId("text-blend").value, text, font_family: byId("text-font").value, font_size: size, color, x: Math.round(number("text-x")), y: Math.round(number("text-y")), alignment: byId("text-align").value, styles: [] };
    if (selectedTextLayer !== null && operations[selectedTextLayer]?.type === "text-layer") {
      if (operations[selectedTextLayer].locked) return status("Unlock the text layer before editing it.");
      layer.styles = structuredClone(operations[selectedTextLayer].styles || []);
      recordHistory(); operations[selectedTextLayer] = layer; selectedTextLayer = null; byId("make-text-layer").textContent = "Add text layer"; refreshHistory(); void render().catch((error) => status(error.message));
    } else void add(layer);
  });
  byId("choose-overlay").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("choose_overlay", {}); if (!result) return status("Overlay selection cancelled"); overlayAsset = result; byId("overlay-name").textContent = result.name; byId("overlay-width").value = result.width; byId("overlay-height").value = result.height; status(`Selected ${result.name}`); } catch (error) { status(error.message); } });
  byId("make-overlay-layer").addEventListener("click", () => {
    if (!opened) return status("Open an image first.");
    if (!overlayAsset) return status("Choose an overlay image first.");
    const width = number("overlay-width"), height = number("overlay-height"), rotation = number("overlay-rotation"), opacity = number("overlay-opacity");
    if (![width, height].every(Number.isInteger) || width < 1 || height < 1 || width > 65535 || height > 65535 || !Number.isFinite(rotation) || rotation < -180 || rotation > 180 || !Number.isFinite(opacity) || opacity < 0 || opacity > 1) return status("Check the overlay size, rotation, and opacity.");
    const layer = { type: "image-overlay-layer", name: overlayAsset.name.slice(0, 200), visible: true, locked: false, opacity, fill_opacity: 1, blend_mode: byId("overlay-blend").value, original_name: overlayAsset.name, data_uri: overlayAsset.data_uri, width, height, rotation_degrees: rotation, x: Math.round(number("overlay-x")), y: Math.round(number("overlay-y")), styles: [] };
    if (selectedOverlayLayer !== null && operations[selectedOverlayLayer]?.type === "image-overlay-layer") {
      if (operations[selectedOverlayLayer].locked) return status("Unlock the image overlay before editing it.");
      layer.styles = structuredClone(operations[selectedOverlayLayer].styles || []);
      recordHistory(); operations[selectedOverlayLayer] = layer; selectedOverlayLayer = null; byId("make-overlay-layer").textContent = "Add image overlay"; refreshHistory(); void render().catch((error) => status(error.message));
    } else void add(layer);
  });
  byId("make-group").addEventListener("click", () => {
    const indices = [...selectedForGroup].filter((index) => operations[index] && (operations[index].type.endsWith("-layer") || operations[index].type === "layer-group")).sort((a, b) => a - b);
    if (indices.length < 2) return status("Select at least two layers to group.");
    const opacity = number("group-opacity"), name = byId("group-name").value.trim();
    if (!name || !Number.isFinite(opacity) || opacity < 0 || opacity > 1) return status("Enter a group name and valid opacity.");
    recordHistory(); const children = indices.map((index) => operations[index]); const insertAt = indices[0];
    [...indices].reverse().forEach((index) => operations.splice(index, 1));
    operations.splice(insertAt, 0, { type: "layer-group", name, visible: true, locked: false, opacity, fill_opacity: 1, blend_mode: byId("group-blend").value, operations: children });
    resetLayerEditors(); refreshHistory(); void render().catch((error) => status(error.message));
  });
  const styleColor = () => { const hex = byId("style-color").value, alpha = number("style-alpha"); return [Number.parseInt(hex.slice(1, 3), 16), Number.parseInt(hex.slice(3, 5), 16), Number.parseInt(hex.slice(5, 7), 16), Math.round(alpha * 255)]; };
  const styleFromForm = () => {
    const type = byId("style-type").value, color = styleColor();
    if (type === "stroke") return { type, enabled: true, color, size: number("style-size"), position: byId("style-position").value };
    if (type === "drop-shadow" || type === "inner-shadow") return { type, enabled: true, color, offset_x: Math.round(number("style-offset-x")), offset_y: Math.round(number("style-offset-y")), blur: number("style-blur"), spread: number("style-spread") };
    return { type, enabled: true, color, size: number("style-size"), intensity: number("style-intensity") };
  };
  byId("save-style").addEventListener("click", () => {
    const layer = selectedStyleLayer === null ? null : operations[selectedStyleLayer];
    if (!layer || !["text-layer", "image-overlay-layer"].includes(layer.type)) return status("Choose Styles on a text or image layer first.");
    if (layer.locked) return status("Unlock the layer before changing its styles.");
    const style = styleFromForm(); const values = Object.values(style).filter((value) => typeof value === "number");
    if (values.some((value) => !Number.isFinite(value)) || style.color[3] < 0 || style.color[3] > 255) return status("Check the style settings.");
    recordHistory(); layer.styles ||= [];
    if (selectedStyleIndex === null) layer.styles.push(style); else layer.styles[selectedStyleIndex] = style;
    selectedStyleIndex = null; byId("save-style").textContent = "Add style"; refreshStyles(); refreshLayers(); void render().catch((error) => status(error.message));
  });
  byId("apply-style-preset").addEventListener("click", () => {
    const layer = selectedStyleLayer === null ? null : operations[selectedStyleLayer], preset = byId("style-preset").value;
    if (!layer || !["text-layer", "image-overlay-layer"].includes(layer.type)) return status("Choose Styles on a text or image layer first.");
    if (layer.locked) return status("Unlock the layer before changing its styles.");
    if (!preset) return status("Choose a style preset first.");
    recordHistory();
    layer.styles = preset === "midnight-lime" ? [
      { type: "stroke", enabled: true, color: [0, 0, 0, 255], size: 2, position: "outside" },
      { type: "outer-glow", enabled: true, color: [57, 255, 20, 180], size: 8, intensity: 1 },
    ] : [{ type: "drop-shadow", enabled: true, color: [0, 0, 0, 170], offset_x: 6, offset_y: 6, blur: 8, spread: 1 }];
    selectedStyleIndex = null; byId("save-style").textContent = "Add style"; refreshStyles(); refreshLayers(); void render().catch((error) => status(error.message));
  });
  byId("copy-styles").addEventListener("click", () => {
    const layer = selectedStyleLayer === null ? null : operations[selectedStyleLayer];
    if (!layer || !["text-layer", "image-overlay-layer"].includes(layer.type)) return status("Choose Styles on a text or image layer first.");
    copiedStyles = structuredClone(layer.styles || []); status(`Copied ${copiedStyles.length} layer style${copiedStyles.length === 1 ? "" : "s"}.`);
  });
  byId("paste-styles").addEventListener("click", () => {
    const layer = selectedStyleLayer === null ? null : operations[selectedStyleLayer];
    if (!layer || !["text-layer", "image-overlay-layer"].includes(layer.type)) return status("Choose Styles on a text or image layer first.");
    if (layer.locked) return status("Unlock the layer before changing its styles.");
    if (!copiedStyles) return status("Copy styles from another layer first.");
    recordHistory(); layer.styles = structuredClone(copiedStyles); selectedStyleIndex = null; refreshStyles(); refreshLayers(); void render().catch((error) => status(error.message));
  });
  byId("undo").addEventListener("click", () => { if (undoStack.length) { redoStack.push(structuredClone(operations)); restore(undoStack.pop()); resetLayerEditors(); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("redo").addEventListener("click", () => { if (redoStack.length) { undoStack.push(structuredClone(operations)); restore(redoStack.pop()); resetLayerEditors(); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("reset").addEventListener("click", () => { if (operations.length) { recordHistory(); operations.length = 0; resetLayerEditors(); refreshHistory(); void render().catch((error) => status(error.message)); } });
  byId("before").addEventListener("click", () => { if (opened) { showingBefore = !showingBefore; void render().catch((error) => status(error.message)); } });
  byId("open").addEventListener("click", async () => { try { ++renderGeneration; const result = await window.SnapSmackEditorHost.call("choose_image", {}); if (!result) return status("Open cancelled"); operations.length = 0; undoStack.length = 0; redoStack.length = 0; resetLayerEditors(); opened = true; showingBefore = false; refreshHistory(); showResult(result); status(`Opened ${result.name}`); } catch (error) { status(error.message); } });
  byId("open-project").addEventListener("click", async () => { try { ++renderGeneration; const result = await window.SnapSmackEditorHost.call("open_project", {}); if (!result) return status("Open project cancelled"); operations.splice(0, operations.length, ...(result.operations || [])); undoStack.length = 0; redoStack.length = 0; resetLayerEditors(); opened = true; showingBefore = false; refreshHistory(); showResult(result); status(`Opened project ${result.project_name}`); } catch (error) { status(error.message); } });
  byId("save-project").addEventListener("click", async () => { try { if (!opened) return status("Open an image first."); const result = await window.SnapSmackEditorHost.call("save_project", { operations }); status(result ? `Saved project ${result.project_path}` : "Save project cancelled"); } catch (error) { status(error.message); } });
  byId("save").addEventListener("click", async () => { try { const result = await window.SnapSmackEditorHost.call("export_copy", { operations }); status(result ? `Saved ${result.output_path}` : "Save cancelled"); } catch (error) { status(error.message); } });
  document.addEventListener("keydown", (event) => { if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "z") return; event.preventDefault(); byId(event.shiftKey ? "redo" : "undo").click(); });
  window.SnapSmackEditorHost.call("recover", {}).then((result) => { if (!result) return; operations.push(...(result.operations || [])); opened = true; refreshHistory(); showResult(result); status(`Recovered ${result.name}`); }).catch(() => {});
})();
// ===== SNAPSMACK EOF =====
