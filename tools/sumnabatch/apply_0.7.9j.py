"""
Apply SYBU 0.7.9j changes:
  - Daylight colour tweaks (BG_DEEP/BG_CARD/BG_MID/BG_HOVER/BORDER/FG_DIM)
  - Profile picker dropdown on POST tab
  - Active-profile auto-load on launch
  - BUILD_VERSION bump to 0.7.9j
  - CHANGELOG.md entry

Run from tools/sybu/ directory. Backs up files to .bak first; restores on
any failure. Self-verifies size and EOF marker before declaring success.

USAGE (from tools/sybu/):
    python apply_0.7.9j.py
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import re
import shutil
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
MAIN_PY  = os.path.join(HERE, 'main.py')
CHANGELOG = os.path.join(HERE, 'CHANGELOG.md')

for f in (MAIN_PY, CHANGELOG):
    if not os.path.exists(f):
        sys.exit(f"ERROR: {f} not found. Run this script from tools/sybu/.")

shutil.copy2(MAIN_PY, MAIN_PY + '.bak')
shutil.copy2(CHANGELOG, CHANGELOG + '.bak')
print("Backed up -> main.py.bak / CHANGELOG.md.bak")

try:
    with open(MAIN_PY, 'rb') as f:
        raw = f.read()
    if b'\x00' in raw:
        raise RuntimeError(f"main.py contains {raw.count(chr(0).encode())} null bytes already; aborting before edits")
    src = raw.decode('utf-8')
    orig_size = len(src)

    # Detect line endings: write back with the same convention.
    nl = '\r\n' if '\r\n' in src[:5000] else '\n'
    print(f"main.py loaded: {orig_size} bytes, line endings: {'CRLF' if nl == chr(13)+chr(10) else 'LF'}")

    # Normalise to LF internally for regex; restore at the end.
    if nl == '\r\n':
        src = src.replace('\r\n', '\n')

    # ----- 1. BUILD_VERSION ------------------------------------------------
    if 'BUILD_VERSION = "0.7.9j"' in src:
        print("  [skip] BUILD_VERSION already 0.7.9j")
    else:
        if 'BUILD_VERSION = "0.7.9i"' not in src:
            raise RuntimeError("BUILD_VERSION not at expected 0.7.9i")
        src = src.replace('BUILD_VERSION = "0.7.9i"', 'BUILD_VERSION = "0.7.9j"', 1)
        print("  [ok]   BUILD_VERSION -> 0.7.9j")

    # ----- 2. Daylight colours ---------------------------------------------
    colours = [
        ('BG_DEEP',  '#141414', '#1A1A1A'),
        ('BG_CARD',  '#1C1C1C', '#242424'),
        ('BG_MID',   '#050505', '#0F0F0F'),
        ('BG_HOVER', '#252525', '#2E2E2E'),
        ('BORDER',   '#2A2A2A', '#3A3A3A'),
        ('FG_DIM',   '#777777', '#A8A8A8'),
    ]
    for name, old, new in colours:
        # Match the whole assignment line (allows any spacing before #)
        pat = re.compile(rf'^({re.escape(name)}\s*=\s*)"{re.escape(old)}"', re.MULTILINE)
        new_pat = re.compile(rf'^{re.escape(name)}\s*=\s*"{re.escape(new)}"', re.MULTILINE)
        m = pat.search(src)
        if m:
            src, n = pat.subn(rf'\g<1>"{new}"', src, count=1)
            print(f"  [ok]   {name} {old} -> {new}")
        elif new_pat.search(src):
            print(f"  [skip] {name} already {new}")
        else:
            raise RuntimeError(f"Colour constant {name} not found at expected value {old}")

    # ----- 3. CONNECTION box: insert profile picker -----------------------
    # Anchor on the variable trio (stable Python code), not the comment.
    conn_old_pat = re.compile(
        r'self\._url_var\s+= tk\.StringVar\(\)\n'
        r'\s+self\._api_key_var = tk\.StringVar\(\)\n'
        r'\s+self\._rem_var\s+= tk\.BooleanVar\(\)\n'
        r'\n'
        r'\s+conn_box\s+= self\._box\(cols, "CONNECTION"\)\n'
        r'\s+conn_box\.grid\(row=0, column=0, sticky="nsew", padx=\(0, 7\)\)\n'
        r'\s+conn_body = self\._box_body\(conn_box\)\n'
        r'\n'
        r'(\s+)self\._field\(conn_body, "SITE URL", self\._url_var\)\n'
        r'\s+self\._field\(conn_body, "API KEY",  self\._api_key_var, show="•"\)\n'
    )
    if 'self._post_profile_var' in src:
        print("  [skip] CONNECTION box: profile picker already present")
    else:
        m = conn_old_pat.search(src)
        if not m:
            raise RuntimeError("CONNECTION box block not found")
        indent = m.group(1)  # leading whitespace before _field calls
        new_conn = (
            'self._url_var          = tk.StringVar()\n'
            f'{indent}self._api_key_var      = tk.StringVar()\n'
            f'{indent}self._rem_var          = tk.BooleanVar()\n'
            f'{indent}self._post_profile_var = tk.StringVar()\n'
            '\n'
            f'{indent}conn_box  = self._box(cols, "CONNECTION")\n'
            f'{indent}conn_box.grid(row=0, column=0, sticky="nsew", padx=(0, 7))\n'
            f'{indent}conn_body = self._box_body(conn_box)\n'
            '\n'
            f'{indent}# Profile picker - selecting populates SITE URL / API KEY / Drive / Gemini\n'
            f'{indent}# from the chosen profile. Source of truth for multi-site users.\n'
            f'{indent}tk.Label(conn_body, text="PROFILE", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")\n'
            f'{indent}self._post_profile_cb = ttk.Combobox(\n'
            f'{indent}    conn_body, textvariable=self._post_profile_var,\n'
            f'{indent}    font=FONT_SMALL, state="readonly",\n'
            f'{indent})\n'
            f'{indent}self._post_profile_cb.pack(fill="x", pady=(2, 8))\n'
            f'{indent}self._post_profile_cb.bind("<<ComboboxSelected>>", self._on_post_profile_select)\n'
            f'{indent}self._post_profile_refresh()\n'
            '\n'
            f'{indent}self._field(conn_body, "SITE URL", self._url_var)\n'
            f'{indent}self._field(conn_body, "API KEY",  self._api_key_var, show="•")\n'
        )
        src = src[:m.start()] + new_conn + src[m.end():]
        print("  [ok]   CONNECTION box: profile picker inserted")

    # ----- 4. Refactor _on_profile_load + add 3 new methods ---------------
    # Anchor on the def line and end of method (next blank line + def or
    # method-level body following the explicit "self._on_connect()" close).
    on_load_pat = re.compile(
        r'    def _on_profile_load\(self\):\n'
        r'        """Load selected profile into POST tab fields and reconnect\."""\n'
        r'        sel = self\._profile_lb\.curselection\(\)\n'
        r'        if not sel:\n'
        r'            messagebox\.showwarning\("No profile selected",\n'
        r'                                   "Select a site profile first\.", parent=self\)\n'
        r'            return\n'
        r'        name = self\._profile_lb\.get\(sel\[0\]\)\n'
        r'        p = profile_manager\.load_profile\(name\)\n'
        r'        if p is None:\n'
        r'            return\n'
        r'\n'
        r'        # Populate POST tab config vars\n'
        r'        self\._url_var\.set\(p\.get\(\'url\', \'\'\)\)\n'
        r'        self\._api_key_var\.set\(p\.get\(\'api_key\', \'\'\)\)\n'
        r'        self\._goog_creds_var\.set\(p\.get\(\'google_credentials\', \'\'\)\)\n'
        r'        self\._drive_folder_var\.set\(p\.get\(\'drive_folder_id\', \'\'\)\)\n'
        r'        self\._gemini_key_var\.set\(p\.get\(\'gemini_api_key\', \'\'\)\)\n'
        r'        self\._copyright_var\.set\(p\.get\(\'copyright_text\', \'\'\)\)\n'
        r'        self\._def_cat_var\.set\(p\.get\(\'default_category\', \'\'\)\)\n'
        r'        self\._def_alb_var\.set\(p\.get\(\'default_album\', \'\'\)\)\n'
        r'        orient = p\.get\(\'default_orientation\', \'auto\'\)\n'
        r'        self\._def_orient_var\.set\(orient\.capitalize\(\) if orient != \'auto\' else \'Auto\'\)\n'
        r'        drive_on = p\.get\(\'drive_enabled\', True\)\n'
        r'        self\._drive_enabled_var\.set\(drive_on\)\n'
        r'        self\._on_drive_toggle\(\)\n'
        r'\n'
        r'        # Save to config\.ini so values persist on next launch\n'
        r'        self\._save_config\(\)\n'
        r'\n'
        r'        # Switch to POST and connect\n'
        r"        self\._switch_tab\('post'\)\n"
        r'        self\._on_connect\(\)\n'
    )
    new_methods = (
        "    def _apply_profile_to_post(self, name: str) -> bool:\n"
        '        """\n'
        "        Shared helper: load profile `name` into POST tab vars and persist as\n"
        "        the active profile. Used by both Settings -> Load Site and the POST\n"
        "        tab profile picker. Returns True on success.\n"
        '        """\n'
        "        p = profile_manager.load_profile(name)\n"
        "        if p is None:\n"
        "            return False\n"
        "\n"
        "        self._url_var.set(p.get('url', ''))\n"
        "        self._api_key_var.set(p.get('api_key', ''))\n"
        "        self._goog_creds_var.set(p.get('google_credentials', ''))\n"
        "        self._drive_folder_var.set(p.get('drive_folder_id', ''))\n"
        "        self._gemini_key_var.set(p.get('gemini_api_key', ''))\n"
        "        self._copyright_var.set(p.get('copyright_text', ''))\n"
        "        self._def_cat_var.set(p.get('default_category', ''))\n"
        "        self._def_alb_var.set(p.get('default_album', ''))\n"
        "        orient = p.get('default_orientation', 'auto')\n"
        "        self._def_orient_var.set(orient.capitalize() if orient != 'auto' else 'Auto')\n"
        "        drive_on = p.get('drive_enabled', True)\n"
        "        self._drive_enabled_var.set(drive_on)\n"
        "        self._on_drive_toggle()\n"
        "\n"
        "        # Reflect on the POST tab dropdown and persist as active profile\n"
        "        self._post_profile_var.set(name)\n"
        "        self._save_config()\n"
        "        return True\n"
        "\n"
        "    def _post_profile_refresh(self):\n"
        '        """Repopulate the POST tab profile dropdown from disk."""\n'
        "        if not hasattr(self, '_post_profile_cb'):\n"
        "            return\n"
        "        names = profile_manager.list_profiles()\n"
        "        self._post_profile_cb['values'] = names\n"
        "        # Preserve current selection if it still exists; otherwise clear.\n"
        "        current = self._post_profile_var.get()\n"
        "        if current and current not in names:\n"
        "            self._post_profile_var.set('')\n"
        "\n"
        "    def _on_post_profile_select(self, _event=None):\n"
        '        """User picked a profile on the POST tab - load it and auto-connect."""\n'
        "        name = self._post_profile_var.get().strip()\n"
        "        if not name:\n"
        "            return\n"
        "        if not self._apply_profile_to_post(name):\n"
        "            return\n"
        "        # Auto-connect - same UX as Settings -> Load Site\n"
        "        self._on_connect()\n"
        "\n"
        "    def _on_profile_load(self):\n"
        '        """Load selected profile into POST tab fields and reconnect."""\n'
        "        sel = self._profile_lb.curselection()\n"
        "        if not sel:\n"
        '            messagebox.showwarning("No profile selected",\n'
        '                                   "Select a site profile first.", parent=self)\n'
        "            return\n"
        "        name = self._profile_lb.get(sel[0])\n"
        "        if not self._apply_profile_to_post(name):\n"
        "            return\n"
        "\n"
        "        # Switch to POST and connect\n"
        "        self._switch_tab('post')\n"
        "        self._on_connect()\n"
    )
    if '_apply_profile_to_post' in src:
        print("  [skip] _apply_profile_to_post already present")
    else:
        m = on_load_pat.search(src)
        if not m:
            raise RuntimeError("_on_profile_load block not found via regex")
        src = src[:m.start()] + new_methods + src[m.end():]
        print("  [ok]   _on_profile_load refactored, 3 new methods added")

    # ----- 5. Settings save: refresh dropdown -----------------------------
    save_pat = re.compile(
        r"(\s+self\._settings_refresh_list\(select_name=name\)\n)"
        r"(        self\._sp_status_lbl\.configure\(text='✓  Saved', fg=FG_OK\)\n)"
    )
    if "self._post_profile_refresh()\n        self._sp_status_lbl.configure(text='✓  Saved'" in src:
        print("  [skip] Settings save dropdown refresh already present")
    else:
        m = save_pat.search(src)
        if not m:
            raise RuntimeError("Settings save block not found")
        src = save_pat.sub(r"\1        self._post_profile_refresh()\n\2", src, count=1)
        print("  [ok]   Settings save: dropdown refresh added")

    # ----- 6. Settings delete: refresh dropdown ---------------------------
    del_pat = re.compile(
        r"(        profile_manager\.delete_profile\(name\)\n"
        r"        self\._settings_refresh_list\(\)\n)"
        r"(        self\._sp_status_lbl\.configure\(text='', fg=FG_DIM\)\n)"
    )
    if "self._post_profile_refresh()\n        self._sp_status_lbl.configure(text='', fg=FG_DIM)" in src:
        print("  [skip] Settings delete dropdown refresh already present")
    else:
        m = del_pat.search(src)
        if not m:
            raise RuntimeError("Settings delete block not found")
        src = del_pat.sub(r"\1        self._post_profile_refresh()\n\2", src, count=1)
        print("  [ok]   Settings delete: dropdown refresh added")

    # ----- 7. _save_config: add active_profile field ----------------------
    save_cfg_pat = re.compile(
        r"(            'remember':\s+self\._rem_var\.get\(\),\n)"
        r"(            'default_category':)"
    )
    if "'active_profile':" in src:
        print("  [skip] active_profile already in _save_config")
    else:
        m = save_cfg_pat.search(src)
        if not m:
            raise RuntimeError("_save_config block not found")
        src = save_cfg_pat.sub(
            r"\1            'active_profile':     self._post_profile_var.get().strip(),\n\2",
            src, count=1)
        print("  [ok]   _save_config: active_profile field added")

    # ----- 8. _load_config_to_ui: restore active_profile on launch --------
    load_cfg_pat = re.compile(
        r"(        self\._gemini_key_var\.set\(c\.get\('gemini_api_key', ''\)\)\n)"
        r"(        last_prompt = c\.get\('gemini_last_prompt', ''\)\n)"
    )
    insert = (
        "\n"
        "        # Active profile - if set in config.ini, profile is the source of\n"
        "        # truth: set the dropdown and overlay profile values onto config.\n"
        "        active = c.get('active_profile', '').strip()\n"
        "        if active and active in profile_manager.list_profiles():\n"
        "            self._post_profile_var.set(active)\n"
        "            p = profile_manager.load_profile(active)\n"
        "            if p is not None:\n"
        "                if p.get('url'):     self._url_var.set(p.get('url', ''))\n"
        "                if p.get('api_key'): self._api_key_var.set(p.get('api_key', ''))\n"
        "                if p.get('google_credentials'): self._goog_creds_var.set(p.get('google_credentials', ''))\n"
        "                if p.get('drive_folder_id'):    self._drive_folder_var.set(p.get('drive_folder_id', ''))\n"
        "                if p.get('gemini_api_key'):     self._gemini_key_var.set(p.get('gemini_api_key', ''))\n"
        "\n"
    )
    if "active = c.get('active_profile'" in src:
        print("  [skip] active_profile restore already present")
    else:
        m = load_cfg_pat.search(src)
        if not m:
            raise RuntimeError("_load_config_to_ui block not found")
        src = load_cfg_pat.sub(r"\1" + insert + r"\2", src, count=1)
        print("  [ok]   _load_config_to_ui: active_profile restore added")

    # ----- Restore line endings & write -----------------------------------
    new_size_lf = len(src)
    delta = new_size_lf - (orig_size if nl == '\n' else orig_size - src.count('\n'))
    print(f"\nmain.py LF-equivalent size: {new_size_lf} (delta vs orig)")

    if not src.rstrip().endswith('# ===== SNAPSMACK EOF ====='):
        raise RuntimeError("EOF marker missing after edits")

    if nl == '\r\n':
        src = src.replace('\n', '\r\n')

    with open(MAIN_PY, 'wb') as f:
        f.write(src.encode('utf-8'))

    # Sanity re-read
    with open(MAIN_PY, 'rb') as f:
        check = f.read()
    if b'\x00' in check:
        raise RuntimeError("Null bytes in main.py after write")
    if not check.rstrip().endswith(b'# ===== SNAPSMACK EOF ====='):
        raise RuntimeError("EOF marker missing in written file")
    print(f"main.py written: {len(check)} bytes, 0 nulls, EOF marker OK")

    # ----- CHANGELOG.md ---------------------------------------------------
    with open(CHANGELOG, 'rb') as f:
        ch_raw = f.read()
    ch = ch_raw.decode('utf-8')
    ch_orig_size = len(ch_raw)

    if '## 0.7.9j' in ch:
        print("CHANGELOG already has 0.7.9j entry, skipping")
    else:
        anchor = '## 0.7.9i'
        if anchor not in ch:
            raise RuntimeError("CHANGELOG anchor for 0.7.9i not found")

        new_entry = (
            "## 0.7.9j - Profile picker on POST tab + daylight colours (2026-05-08)\n"
            "\n"
            "### Added\n"
            "- **Profile dropdown on POST tab** - new PROFILE picker at the top of the CONNECTION box. Selecting a profile populates SITE URL, API KEY, Google Drive credentials, Drive folder ID, Gemini API key, copyright, default category/album/orientation, and Drive enabled flag in one click, then auto-connects. No more bouncing to Settings -> Load Site to switch sites.\n"
            "- **Active-profile persistence** - the selected profile name is saved to `config.ini` as `active_profile`. On launch, SYBU reads that field and overlays the profile's stored values onto the POST tab - the profile is the source of truth, config.ini is just a cache. Solves the \"I have a profile saved but POST tab is empty\" UX.\n"
            "- **Live dropdown sync** - the POST tab dropdown refreshes whenever a profile is saved or deleted in Settings. No app restart needed.\n"
            "\n"
            "### Changed\n"
            "- **Daylight colour tweaks** - lifted the darkest UI elements for legibility in bright rooms. `BG_DEEP` #141414->#1A1A1A, `BG_CARD` #1C1C1C->#242424, `BG_MID` (input fields) #050505->#0F0F0F, `BG_HOVER` #252525->#2E2E2E, `BORDER` #2A2A2A->#3A3A3A, `FG_DIM` (section labels) #777777->#A8A8A8. Neon lime accent and primary text colour unchanged.\n"
            "- **`_on_profile_load`** (Settings -> Load Site) refactored to share `_apply_profile_to_post(name)` with the new POST tab picker. Same code path; consistent behaviour.\n"
            "\n"
            "---\n"
            "\n"
        )
        # Insert before first occurrence of "## 0.7.9i"
        idx = ch.index('## 0.7.9i')
        ch = ch[:idx] + new_entry + ch[idx:]

        with open(CHANGELOG, 'wb') as f:
            f.write(ch.encode('utf-8'))

        with open(CHANGELOG, 'rb') as f:
            ch_check = f.read()
        if b'\x00' in ch_check:
            raise RuntimeError("Null bytes in CHANGELOG.md after write")
        if not ch_check.rstrip().endswith(b'<!-- ===== SNAPSMACK EOF ===== -->'):
            raise RuntimeError("EOF marker missing in written CHANGELOG.md")
        print(f"CHANGELOG.md written: {ch_orig_size} -> {len(ch_check)} bytes, 0 nulls, EOF marker OK")

    print("\nAll changes applied successfully.")
    print("Run build.bat to rebuild SYBU 0.7.9j.")
    print("Backups left at main.py.bak / CHANGELOG.md.bak - delete when satisfied.")

except Exception as e:
    print(f"\nERROR: {e}")
    print("Restoring from .bak files...")
    shutil.copy2(MAIN_PY + '.bak', MAIN_PY)
    shutil.copy2(CHANGELOG + '.bak', CHANGELOG)
    print("Restored. Files unchanged from before this run.")
    sys.exit(1)
# ===== SNAPSMACK EOF =====
