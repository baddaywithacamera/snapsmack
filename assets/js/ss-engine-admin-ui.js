/**
 * SNAPSMACK - Admin UI Engine
 * Alpha v0.7.4
 *
 * Handles admin-side interface: custom multiselect dropdowns, label management,
 * registry filtering, file upload handling, EXIF preflight, and form submission.
 */

console.log("LOG: SS-ENGINE-ADMIN-UI v1.0 active.");

// --- CUSTOM MULTISELECT DROPDOWNS ---

window.toggleDropdown = function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    const isOpen = el.style.display === 'block';

    document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = 'none');

    el.style.display = isOpen ? 'none' : 'block';
};

window.updateLabel = function (type) {
    const checkboxes = document.querySelectorAll(`input[name="${type}_ids[]"]:checked`);
    const label = document.getElementById(`${type}-label`);
    if (label) {
        if (checkboxes.length > 0) {
            label.innerText = checkboxes.length + " SELECTED";
            label.style.color = "var(--neon-green)";
        } else {
            label.innerText = "SELECT " + (type === 'cat' ? 'CATEGORIES' : 'ALBUMS') + "...";
            label.style.color = "var(--text-main)";
        }
    }
};

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

// --- CLOSE DROPDOWNS ON OUTSIDE CLICK ---

window.addEventListener('click', function(event) {
    if (!event.target.closest('.custom-multiselect')) {
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = 'none');
    }
});

// --- INITIALIZATION AND EVENT HANDLERS ---

document.addEventListener("DOMContentLoaded", function () {

    // Clock display with timezone and format selection
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

        setInterval(updateClock, 1000);
        tzSelect.addEventListener('change', updateClock);
        fmtSelect.addEventListener('change', updateClock);
        updateClock();
    }

    // Row count slider for grid layout
    const rowSlider = document.getElementById('row-slider');
    const rowDisplay = document.getElementById('row-display');
    if (rowSlider && rowDisplay) {
        rowSlider.addEventListener('input', function() {
            rowDisplay.innerText = this.value + " Row(s)";
        });
    }

    // Color picker for display
    const colorPicker = document.getElementById('color-picker');
    const hexDisplay = document.getElementById('hex-display');
    if (colorPicker && hexDisplay) {
        colorPicker.addEventListener('input', function() {
            hexDisplay.innerText = this.value.toUpperCase();
        });
    }

    // File upload interaction and EXIF metadata extraction
    const fileInput = document.getElementById("post-file-input") || document.getElementById("file-input");
    const customBtn = document.querySelector(".file-custom-btn");
    const fileNameText = document.getElementById("post-file-name") || document.getElementById("file-name-text");
    const titleInput = document.getElementById("title-input");

    if (customBtn && fileInput) {
        customBtn.addEventListener("click", function(e) {
            e.stopPropagation();
            fileInput.click();
        });
    }

    if (fileInput) {
        fileInput.addEventListener("change", function () {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];

            if (fileNameText) {
                fileNameText.textContent = file.name;
                fileNameText.style.color = "var(--neon-green)";
            }

            const nameOnly = file.name.split(".").slice(0, -1).join(".");
            if (titleInput) {
                titleInput.value = nameOnly.replace(/[_-]/g, " ").replace(/\b\w/g, c => c.toUpperCase());
            }

            // Extract EXIF metadata from uploaded image and populate form fields
            const formData = new FormData();
            formData.append("image_file", file);
            fetch("smack-preflight.php", { method: "POST", body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.meta) {
                        const m = data.meta;
                        const fill = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };
                        fill("meta-camera", m.camera ? m.camera.toUpperCase() : "");
                        fill("meta-iso", m.iso);
                        fill("meta-aperture", m.aperture);
                        fill("meta-shutter", m.shutter);
                        fill("meta-focal", m.focal);
                        if (m.flash === "Yes") fill("meta-flash", "Yes");
                    }
                })
                .catch(err => console.error("LOG: Preflight error", err));
        });
    }

    // Form submission with upload progress tracking
    const form = document.getElementById("smack-form-post") || document.getElementById("smack-form");
    if (form) {
        form.onsubmit = function (e) {
            e.preventDefault();

            // Ensure a file is selected before allowing submission
            const fileField = form.querySelector('input[type="file"][name="img_file"]');
            if (fileField && (!fileField.files || !fileField.files.length)) {
                alert("NO ASSET SELECTED — Select a file before committing.");
                return;
            }

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

            xhr.onerror = function () {
                alert("MISSION FAILURE: Network error.");
                if (pCont) pCont.style.display = "none";
            };

            xhr.open("POST", form.action || window.location.href, true);
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            xhr.send(formData);
        };
    }

    // Conditional field locking: "Built-in" lens and "N/A" film options
    document.addEventListener("change", function (e) {
        if (e.target.id === "fixed-lens-check") {
            const input = document.getElementById("meta-lens");
            if (input) {
                input.value = e.target.checked ? "Built-in" : "";
                input.readOnly = e.target.checked;
                input.style.opacity = e.target.checked ? "0.5" : "1";
            }
        }
        if (e.target.id === "film-na-check") {
            const input = document.getElementById("meta-film");
            if (input) {
                input.value = e.target.checked ? "N/A" : "";
                input.readOnly = e.target.checked;
                input.style.opacity = e.target.checked ? "0.5" : "1";
            }
        }
    });
});
