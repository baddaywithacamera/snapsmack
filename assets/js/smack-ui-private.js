/**
 * SNAPSMACK - UI Engine
 * Version: 13.7 - Core Admin Logic & Time Sync
 * MASTER DIRECTIVE: Full logic preservation. Readability is paramount.
 * Handles: File Uploads, Metadata Preflight, AJAX Transmission, and Engine Configuration.
 * Renamed from smack-ui.js to smack-ui-private.js as handles private UI functions
 */

console.log("LOG: SNAPSMACK UI Engine v13.7 active.");

// --- 0. DYNAMIC REGISTRY FUNCTIONS ---
// Handles the multi-select dropdowns for Categories and Albums.

/**
 * TOGGLE DROPDOWN
 * Opens/Closes specific registries while ensuring others stay shut.
 */
window.toggleDropdown = function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = el.style.display === 'block';
    
    // Close all other dropdowns to prevent UI overlap
    document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = 'none');
    
    // Toggle the target
    el.style.display = isOpen ? 'none' : 'block';
};

/**
 * UPDATE LABEL
 * Changes the button text (e.g., "3 SELECTED") based on checkbox state.
 */
window.updateLabel = function (type) {
    const checkboxes = document.querySelectorAll(`input[name="${type}_ids[]"]:checked`);
    const label = document.getElementById(`${type}-label`);
    if (label) {
        if (checkboxes.length > 0) {
            label.innerText = checkboxes.length + " SELECTED";
            label.style.color = "#39FF14"; // Tactical Green
        } else {
            label.innerText = "SELECT " + (type === 'cat' ? 'CATEGORIES' : 'ALBUMS') + "...";
            label.style.color = "#eee";
        }
    }
};

/**
 * FILTER REGISTRY
 * Live search filter for finding tags inside the dropdowns.
 */
window.filterRegistry = function (input, containerId) {
    const filter = input.value.toUpperCase();
    const container = document.getElementById(containerId);
    if (!container) return;
    const items = container.getElementsByClassName('multi-cat-item');
    
    for (let i = 0; i < items.length; i++) {
        const text = items[i].querySelector('.cat-name-text').innerText.toUpperCase();
        items[i].style.display = text.indexOf(filter) > -1 ? "" : "none";
    }
};

// --- 1. CONFIGURATION HELPERS (NEW SECTION) ---
// Handles live previews for the Settings Dashboard (Clock, Sliders, Colors).

function initConfigListeners() {
    // 1. Timezone & Clock Preview Logic
    const clockElement = document.getElementById('local-clock');
    const tzSelect = document.getElementById('timezone-select');
    const fmtSelect = document.getElementById('format-select');

    if (clockElement && tzSelect && fmtSelect) {
        const updateClock = () => {
            const selectedTz = tzSelect.value;
            const selectedFormatExample = fmtSelect.options[fmtSelect.selectedIndex].text;
            const now = new Date();
            try {
                const timeFmt = new Intl.DateTimeFormat('en-US', {
                    timeZone: selectedTz,
                    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
                });
                clockElement.textContent = `${selectedFormatExample} - ${timeFmt.format(now)}`;
            } catch (e) { 
                clockElement.textContent = "Sync Error"; 
            }
        };
        
        // Update every second + on change
        setInterval(updateClock, 1000);
        tzSelect.addEventListener('change', updateClock);
        fmtSelect.addEventListener('change', updateClock);
        updateClock(); // Immediate run to clear "Syncing..."
    }

    // 2. Range Slider (Row) Visual Update
    const rowSlider = document.getElementById('row-slider');
    const rowDisplay = document.getElementById('row-display');
    if (rowSlider && rowDisplay) {
        rowSlider.addEventListener('input', function() {
            rowDisplay.innerText = this.value + " Row(s)";
        });
    }

    // 3. Color Hex Visual Update
    const colorPicker = document.getElementById('color-picker');
    const hexDisplay = document.getElementById('hex-display');
    if (colorPicker && hexDisplay) {
        colorPicker.addEventListener('input', function() {
            hexDisplay.innerText = this.value.toUpperCase();
        });
    }
}

// --- 2. DOM READY LOGIC (MASTER INIT) ---
document.addEventListener("DOMContentLoaded", function () {
    
    // A. Initialize Configuration Listeners
    initConfigListeners();

    // B. File Upload Logic
    const fileInput = document.getElementById("file-input");
    const customBtn = document.querySelector(".file-custom-btn");
    const fileNameText = document.getElementById("file-name-text");
    const titleInput = document.getElementById("title-input");

    if (customBtn && fileInput) {
        customBtn.addEventListener("click", () => fileInput.click());
    }

    if (fileInput) {
        fileInput.addEventListener("change", function () {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];

            // Update UI Filename
            if (fileNameText) {
                fileNameText.textContent = file.name;
                fileNameText.style.color = "#39FF14";
            }

            // Auto-titling (Sanitize filename)
            const nameOnly = file.name.split(".").slice(0, -1).join(".");
            if (titleInput) {
                titleInput.value = nameOnly.replace(/[_-]/g, " ").replace(/\b\w/g, c => c.toUpperCase());
            }

            // METADATA PREFLIGHT (Extract EXIF)
            const formData = new FormData();
            formData.append("image_file", file);
            fetch("smack-preflight.php", { method: "POST", body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.meta) {
                        const m = data.meta;
                        if (m.camera) document.getElementById("meta-camera").value = m.camera.toUpperCase();
                        if (m.iso) document.getElementById("meta-iso").value = m.iso;
                        if (m.aperture) document.getElementById("meta-aperture").value = m.aperture;
                        if (m.shutter) document.getElementById("meta-shutter").value = m.shutter;
                        if (m.focal) document.getElementById("meta-focal").value = m.focal;
                        if (m.flash === "Yes") document.getElementById("meta-flash").value = "Yes";
                    }
                })
                .catch(err => console.error("LOG: Preflight error", err));
        });
    }

    // C. Hardware Overrides (Lens/Film Checks)
    document.addEventListener("change", function (e) {
        if (e.target.id === "fixed-lens-check") {
            const input = document.getElementById("meta-lens");
            input.value = e.target.checked ? "Built-in" : "";
            input.readOnly = e.target.checked;
            input.style.opacity = e.target.checked ? "0.5" : "1";
        }
        if (e.target.id === "film-na-check") {
            const input = document.getElementById("meta-film");
            input.value = e.target.checked ? "N/A" : "";
            input.readOnly = e.target.checked;
            input.style.opacity = e.target.checked ? "0.5" : "1";
        }
    });

    // D. AJAX Smack Engine (Form Submission)
    const form = document.getElementById("smack-form");
    if (form) {
        form.onsubmit = function (e) {
            e.preventDefault();
            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();
            const pCont = document.getElementById("progress-container");
            const pBar = document.getElementById("progress-bar");

            if (pCont) pCont.style.display = "block";

            xhr.upload.addEventListener("progress", e => {
                if (e.lengthComputable && pBar) pBar.style.width = (e.loaded / e.total) * 100 + "%";
            });

            xhr.onload = function () {
                if (xhr.status === 200 && xhr.responseText.trim() === "success") {
                    window.location.href = "smack-manage.php?msg=success";
                } else {
                    alert("MISSION FAILURE: " + xhr.responseText);
                    if (pCont) pCont.style.display = "none";
                }
            };

            xhr.open("POST", "smack-post.php", true);
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.send(formData);
        };
    }
});

// --- 3. GLOBAL CLICK HANDLER ---
// Closes dropdowns when clicking outside
window.onclick = function(event) {
    if (!event.target.closest('.custom-multiselect')) {
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = 'none');
    }
};