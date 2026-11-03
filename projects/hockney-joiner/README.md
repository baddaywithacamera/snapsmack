# Hockney Joiner project

SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.

This desktop project is being integrated into SnapSmack under `projects/hockney-joiner`. It is separate from the Hockney Joiner photoblog site.

Project-owned code is licensed under SMACK PUBLIC LICENSE (SPL) v2.4; see LICENSE. Third-party dependencies and model weights retain their own licences.

The application uses **PySide6** (LGPL), which SPL permits. It previously used PyQt6, which is GPL-or-commercial and which SPL:294 forbids being linked in, so the project contradicted its own licence until that was changed.

Dependency licences, read from the installed distributions rather than assumed: PySide6 and shiboken6 are LGPL-3.0 **or** GPL-2.0 **or** GPL-3.0 at the user's choice and are taken here under LGPL; Pillow is MIT-CMU; opencv-python, transformers, huggingface-hub and lightglue are Apache 2.0; torch and numpy are BSD. `rawpy` is optional and not installed, so LibRaw's LGPL/CDDL terms do not arise unless it is enabled.

Two things are still NOT reviewed: the licences of the **downloaded model weights** (LightGlue and moondream2 are fetched at first run, not shipped here), and `lightglue` is installed from a bare GitHub URL with no version or commit pin — an Apache licence, but whatever that branch holds at install time is what arrives.

Original project history: https://github.com/baddaywithacamera/hockney-joiner (snapshot source 6645cfc). Its local Git metadata is preserved outside this repository. Downloaded models are not part of this import.

See BUILD.md, CONTROLS.md and REFERENCE_PLACEMENT.md. This project is excluded from CMS release packages.

<!-- ===== SNAPSMACK EOF ===== -->
