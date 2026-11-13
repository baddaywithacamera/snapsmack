"""Regression coverage for SS-1317: plain AI tag words must not vanish."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import gemini
import manifest_parser
import recovery
import sybu_core


RAW_TAGS = "civic building facade siding stonework entrance overcast clouds architecture flags"
NORMAL_TAGS = "#civic #building #facade #siding #stonework #entrance #overcast #clouds #architecture #flags"


def test_plain_ai_words_become_backend_compatible_hashtags():
    assert manifest_parser.normalize_tags(RAW_TAGS) == NORMAL_TAGS


def test_normalizer_preserves_valid_tags_colors_order_and_deduplicates():
    raw = "#Civic civic #night-photo, #3a3430 #7F7D84 bad/tag"
    assert manifest_parser.normalize_tags(raw) == "#civic #night_photo #3a3430 #7f7d84"


def test_gemini_response_is_normalized_at_parse_boundary():
    parsed = gemini._parse_response(f"TITLE: Village office\nTAGS: {RAW_TAGS}")
    assert parsed['tags'] == NORMAL_TAGS


def test_manifest_import_normalizes_plain_words(tmp_path):
    path = tmp_path / 'manifest.txt'
    path.write_text(f"---\nFILE: office.jpg\nTAGS: {RAW_TAGS}\n---\n", encoding='utf-8')
    parsed = manifest_parser.parse(str(path))
    assert parsed.entries[0].tags == NORMAL_TAGS


def test_saved_enrichment_restores_without_another_ai_call(tmp_path, monkeypatch):
    image = tmp_path / 'office.jpg'
    image.write_bytes(b'image')
    store = recovery.RecoveryStore(str(tmp_path))
    monkeypatch.setattr(store, 'path', str(tmp_path / 'recovery.json'))
    entry = manifest_parser.ManifestEntry(file='office.jpg')
    key = recovery.item_key(str(tmp_path), entry.file)
    store.data = {'items': {key: {'status': 'enriched', 'title': 'Village office', 'tags': RAW_TAGS}}}

    assert store.restore_into([entry]) == 1
    assert entry.tags == NORMAL_TAGS


def test_batch_hashtags_normalize_existing_saved_tags_too():
    merged = sybu_core.merge_additional_hashtags("civic building", "#PhotoFriFarm horsconcours civic")
    assert merged == "#civic #building #photofrifarm #horsconcours"


# ===== SNAPSMACK EOF =====
