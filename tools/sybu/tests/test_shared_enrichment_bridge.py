"""SYBU enrichment is durable locally and propagates through its scoped API."""

import json
import os
from pathlib import Path
import sqlite3
import sys

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import library_bridge
from manifest_parser import ManifestEntry


class _Response:
    status_code = 200

    def json(self):
        return {'ok': True, 'revision': 1}


class _Session:
    def __init__(self):
        self.calls = []

    def post(self, url, **kwargs):
        self.calls.append((url, kwargs))
        return _Response()


def test_complete_bundle_is_saved_and_propagated(tmp_path, monkeypatch):
    monkeypatch.setenv('SNAPSMACK_HOME', str(tmp_path / 'home'))
    image_path = tmp_path / 'portrait.png'
    Image.new('RGB', (20, 30), (40, 90, 160)).save(image_path)
    entry = ManifestEntry(
        file=image_path.name, title='Blue wall', caption='A blue wall.',
        alt='Blue wall beneath a window.', tags='#blue #wall',
        category='Architecture', album='Town', orientation='1',
        colors='#285AA0 #FFFFFF #111111', color_mode='color',
    )
    entry._enrichment_prompt = 'exact site prompt'
    entry._enrichment_raw_response = 'CAPTION: A blue wall.'
    entry._enrichment_model = 'gemini-test'
    session = _Session()

    result = library_bridge.record_enrichment(
        'https://example.test', str(image_path), entry, session=session)

    assert result['asset']['width'] == 20
    assert result['asset']['height'] == 30
    assert session.calls[0][0].endswith('/api.php?route=gyss/enrichment-cache')
    sent = session.calls[0][1]['json']['record']
    assert sent['bundle']['orientation'] == '1'
    assert sent['bundle']['color_mode'] == 'color'
    assert sent['bundle']['colors'] == '#285AA0 #FFFFFF #111111'
    assert sent['raw_response'] == 'CAPTION: A blue wall.'

    db = tmp_path / 'home' / 'shared_library' / 'example.test' / 'db' / 'catalog.sqlite'
    with sqlite3.connect(db) as conn:
        cache = conn.execute(
            'SELECT bundle_json,raw_response,dirty FROM enrichment_cache').fetchone()
        asset = conn.execute(
            'SELECT width,height,alt,color_mode,status FROM assets').fetchone()
    assert json.loads(cache[0])['caption'] == 'A blue wall.'
    assert cache[1] == 'CAPTION: A blue wall.'
    assert cache[2] == 0
    assert asset == (20, 30, 'Blue wall beneath a window.', 'color', 'staged')


def test_offline_save_remains_pending(tmp_path, monkeypatch):
    monkeypatch.setenv('SNAPSMACK_HOME', str(tmp_path / 'home'))
    image_path = tmp_path / 'square.png'
    Image.new('RGB', (10, 10), (1, 2, 3)).save(image_path)
    entry = ManifestEntry(file=image_path.name, caption='Saved locally.')

    library_bridge.record_enrichment('https://offline.test', str(image_path), entry)

    db = tmp_path / 'home' / 'shared_library' / 'offline.test' / 'db' / 'catalog.sqlite'
    with sqlite3.connect(db) as conn:
        assert conn.execute('SELECT dirty FROM enrichment_cache').fetchone()[0] == 1

# ===== SNAPSMACK EOF =====
