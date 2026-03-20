"""
ft-batch-poster — manifest_parser.py
Parses the Found Textures .txt manifest files produced by the AI metadata prompt.

Manifest format:
    ---
    FILE: filename.jpg
    TITLE: haiku title here
    TAGS: #tag1 #tag2 #tag3
    CATEGORY: Concrete
    ALBUM: Spring 2026
    ---
"""

import os
from dataclasses import dataclass, field
from typing import List, Tuple


@dataclass
class ManifestEntry:
    file:        str = ''
    title:       str = ''
    tags:        str = ''
    category:    str = ''
    album:       str = ''
    orientation: str = 'auto'   # auto | 0 (landscape) | 1 (portrait) | 2 (square)
    # Source line number for error reporting
    line_num:    int = 0


@dataclass
class ParseResult:
    entries: List[ManifestEntry] = field(default_factory=list)
    errors:  List[str]           = field(default_factory=list)


def parse(manifest_path: str) -> ParseResult:
    """
    Parse a manifest .txt file and return all entries plus any parse errors.
    Errors are non-fatal — we collect them and return whatever we could parse.
    """
    result = ParseResult()

    try:
        with open(manifest_path, 'r', encoding='utf-8') as f:
            lines = f.readlines()
    except OSError as e:
        result.errors.append(f"Cannot read manifest file: {e}")
        return result

    current: ManifestEntry | None = None
    in_entry = False

    for i, raw_line in enumerate(lines, start=1):
        line = raw_line.strip()

        if line == '---':
            # Close current entry if we have one
            if in_entry and current is not None:
                if current.file:
                    result.entries.append(current)
                elif any([current.title, current.tags, current.category, current.album]):
                    result.errors.append(f"Line {current.line_num}: entry has no FILE field — skipped")
            # Always start a fresh entry — --- is a separator, not an open/close toggle
            current = ManifestEntry(line_num=i)
            in_entry = True
            continue

        if not in_entry or current is None:
            # Content outside of --- delimiters is ignored
            continue

        if ':' not in line:
            # Not a key: value line — skip silently (blank lines, etc.)
            continue

        key, _, value = line.partition(':')
        key   = key.strip().upper()
        value = value.strip()

        if key == 'FILE':
            current.file = value
        elif key == 'TITLE':
            current.title = value
        elif key == 'TAGS':
            current.tags = value
        elif key == 'CATEGORY':
            current.category = value
        elif key == 'ALBUM':
            current.album = value

    # Handle unterminated entry at EOF
    if in_entry and current is not None and current.file:
        result.entries.append(current)

    if not result.entries:
        result.errors.append("No valid entries found in manifest.")

    return result


if __name__ == '__main__':
    import sys
    path = sys.argv[1] if len(sys.argv) > 1 else 'manifest01.txt'
    result = parse(path)
    print(f"\nFound {len(result.entries)} entries")
    for err in result.errors:
        print(f"ERROR: {err}")
    for i, entry in enumerate(result.entries):
        print(f"\nEntry {i+1}: {entry.file}")
        print(f"  Title: {entry.title[:60]}")
        print(f"  Cat: {entry.category}  Album: {entry.album}")
    input("\nPress Enter to close...")


def validate(
    entries: List[ManifestEntry],
    image_folder: str,
    known_categories: List[str],
    known_albums: List[str],
    default_category: str = '',
    default_album: str = '',
) -> List[Tuple[ManifestEntry, List[str]]]:
    """
    Validate a list of parsed entries against the image folder and known
    SnapSmack categories/albums.

    Returns a list of (entry, [warning strings]) for every entry that has issues.
    Entries with no issues are not included.
    """
    issues: List[Tuple[ManifestEntry, List[str]]] = []

    # Build case-insensitive lookup sets
    cats_lower  = {c.lower() for c in known_categories}
    albums_lower = {a.lower() for a in known_albums}

    for entry in entries:
        warnings: List[str] = []

        # 1. Does the image file exist?
        img_path = os.path.join(image_folder, entry.file)
        if not os.path.isfile(img_path):
            warnings.append(f"Image file not found: {entry.file}")

        # 2. Title present?
        if not entry.title:
            warnings.append("TITLE is blank")

        # 3. Category check
        cat = entry.category or default_category
        if cat and cat.lower() not in cats_lower:
            warnings.append(f"Category not found in SnapSmack: \"{cat}\"")

        # 4. Album check
        album = entry.album or default_album
        if album and album.lower() not in albums_lower:
            warnings.append(f"Album not found in SnapSmack: \"{album}\"")

        if warnings:
            issues.append((entry, warnings))

    return issues
