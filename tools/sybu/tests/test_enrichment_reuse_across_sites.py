# -*- coding: utf-8 -*-
"""SS-1137: enrichment already paid for is never paid for twice.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

THE MONEY TEST, and the one the ticket asks for. Sean pays Gemini per image per
blog. The enrichment was already on his disk every time and nothing looked for
it, so he paid again.

A test that only asserts the cache row exists proves STORAGE, not SAVING -- the
same distinction that caught the dead appearance controls. So this one makes
any API call a FAILURE: the stub raises. If the reuse path has a hole, the test
does not report a wrong value, it reports a call Sean would have been billed
for.

The case that matters is deliberately hostile: the SAME BYTES arriving at a
DIFFERENT SITE under a DIFFERENT FILENAME. Both of those were enough to miss
before -- the domain was in the cache key and the filename was baked into the
prompt hash -- and a test that changed only the site would have passed while
the money kept leaking.
"""

import os
import shutil
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
SYBU = os.path.dirname(HERE)
SHARED = os.path.join(os.path.dirname(SYBU), '_shared')
for path in (SYBU, SHARED):
    if path not in sys.path:
        sys.path.insert(0, path)

# A scratch SNAPSMACK_HOME, so no real catalogue is touched.
_HOME = tempfile.mkdtemp(prefix='ss1137_home_')
os.environ['SNAPSMACK_HOME'] = _HOME

import snap_enrichment_cache as cache                                # noqa: E402

_fails = 0


def check(label, ok):
    global _fails
    print(('PASS ' if ok else 'FAIL ') + label)
    if not ok:
        _fails += 1


class _Entry:
    """The fields library_bridge._bundle reads off a SYBU entry."""

    def __init__(self, file_name):
        self.file = file_name
        self.title = 'Low Tide At Dusk'
        self.caption = 'A long exposure of the breakwater.'
        self.alt = 'Breakwater at dusk, long exposure'
        self.tags = '#ocean #dusk'
        self.category = 'Landscapes'
        self.album = 'Around Town'
        self.colors = 'blue, grey'
        self.color_mode = 'colour'
        self.orientation = 'landscape'


BASE_PROMPT = "Describe the photograph. Give a haiku title."
MODEL = 'gemini-3.5-flash'


def _write_image(path, colour=(70, 80, 90)):
    """A REAL JPEG. enrich_batch reads orientation and colour mode off the
    file with PIL before it calls the model, so a handful of fake bytes
    aborts the run -- the first version of this test used fake bytes and
    died inside PIL. `colour` is how two files are made to differ."""
    from PIL import Image
    Image.new("RGB", (160, 120), colour).save(path, "JPEG", quality=70)
    return path


def _copy_image(src, dst):
    """Byte-identical copy under a different name -- Sean carrying the same
    photograph to a second blog, the case that was costing money."""
    shutil.copyfile(src, dst)
    return dst


def main():
    work = tempfile.mkdtemp(prefix='ss1137_')
    site_a = 'https://baddaywithacamera.com'
    site_b = 'https://theschoolofhardnocks.com'

    original = _write_image(os.path.join(work, 'DSC_0041.jpg'))

    # ── 1. the paid enrichment, stored as the real bridge stores it ─────────
    image_hash = cache.image_sha256(original)
    # The RENDERED prompt is what the old code hashed: filename bound in.
    rendered_a = "FILENAME: DSC_0041\n\n" + BASE_PROMPT
    entry = _Entry('DSC_0041.jpg')
    bundle = {key: getattr(entry, key) for key in
              ('title', 'caption', 'alt', 'tags', 'category', 'album',
               'colors', 'color_mode', 'orientation')}
    cache.put(site_a, image_hash, 'baddaywithacamera.com', MODEL,
              cache.prompt_sha256(rendered_a), bundle,
              base_prompt_hash=cache.prompt_sha256(BASE_PROMPT))
    check('the paid enrichment is stored', cache.get(
        site_a, image_hash, 'baddaywithacamera.com', MODEL,
        cache.prompt_sha256(rendered_a)) is not None)

    # ── 2. THE OLD BEHAVIOUR, asserted so the fault cannot come back ───────
    # Same photograph, second blog, new filename. This is what Sean did.
    copied = _copy_image(original, os.path.join(work, 'breakwater-dusk.jpg'))
    check('the copy is byte-identical, so it is the same photograph',
          cache.image_sha256(copied) == image_hash)
    rendered_b = "FILENAME: breakwater-dusk\n\n" + BASE_PROMPT
    check('the exact-key lookup MISSES on the second blog, as it always did',
          cache.get(site_b, image_hash, 'theschoolofhardnocks.com', MODEL,
                    cache.prompt_sha256(rendered_b)) is None)
    check('and it misses on the first blog too, purely because of the filename',
          cache.get(site_a, image_hash, 'baddaywithacamera.com', MODEL,
                    cache.prompt_sha256(rendered_b)) is None)

    # ── 3. THE FIX: the content lookup finds it ─────────────────────────────
    hit = cache.find_reusable(image_hash, MODEL, cache.prompt_sha256(BASE_PROMPT),
                              prefer_site=site_b)
    check('the reuse lookup finds the paid enrichment from the OTHER site',
          hit is not None)
    check('and it is the same answer, not an empty row',
          bool(hit) and hit['bundle']['title'] == 'Low Tide At Dusk')
    check('it says where it came from, so reuse is not silent',
          bool(hit) and hit.get('reused_from_domain') == 'baddaywithacamera.com')

    # ── 4. A GENUINELY DIFFERENT INSTRUCTION STILL RE-ENRICHES ─────────────
    # This is the half the ticket wanted KEPT. If this passes wrongly, Sean
    # gets a caption written to an instruction he changed.
    check('a changed owner prompt does NOT reuse',
          cache.find_reusable(image_hash, MODEL,
                              cache.prompt_sha256(BASE_PROMPT + " In French."),
                              prefer_site=site_b) is None)
    check('a different model does NOT reuse',
          cache.find_reusable(image_hash, MODEL + '-pro',
                              cache.prompt_sha256(BASE_PROMPT),
                              prefer_site=site_b) is None)
    check('a different photograph does NOT reuse',
          cache.find_reusable(cache.image_sha256(
              _write_image(os.path.join(work, 'other.jpg'), (200, 20, 20))),
              MODEL, cache.prompt_sha256(BASE_PROMPT), prefer_site=site_b) is None)
    check('an empty prompt hash refuses rather than matching everything',
          cache.find_reusable(image_hash, MODEL, '', prefer_site=site_b) is None)
    check('an empty image hash refuses too',
          cache.find_reusable('', MODEL, cache.prompt_sha256(BASE_PROMPT)) is None)

    # ── 5. THE 90-DAY EXPIRY MUST NOT CHARGE HIM AGAIN ─────────────────────
    # A photograph does not change in 90 days and neither does its caption.
    conn = cache._connect(site_a)
    with conn:
        conn.execute("UPDATE enrichment_cache SET expires_at = 1")
    conn.close()
    check('an EXPIRED row is still reused, because he already paid for it',
          cache.find_reusable(image_hash, MODEL, cache.prompt_sha256(BASE_PROMPT),
                              prefer_site=site_b) is not None)
    check('while get() still honours expiry, so nothing else changed',
          cache.get(site_a, image_hash, 'baddaywithacamera.com', MODEL,
                    cache.prompt_sha256(rendered_a)) is None)

    # ── 6. NO ROW HE HAS ALREADY PAID FOR IS INVALIDATED ───────────────────
    # The migration risk the ticket names: re-keying would make the first run
    # after the fix re-charge for everything. Nothing is re-keyed, so the old
    # exact key still resolves.
    check('the original exact key still resolves, so no migration is needed',
          cache.get(site_a, image_hash, 'baddaywithacamera.com', MODEL,
                    cache.prompt_sha256(rendered_a), include_expired=True) is not None)

    # ── 7. A ROW FROM BEFORE THE FIX simply does not reuse ──────────────────
    # Rows written before today have no base_prompt_sha256. They must not match
    # anything, and must not crash the lookup.
    legacy_image = _write_image(os.path.join(work, 'legacy.jpg'), (20, 200, 20))
    legacy_hash = cache.image_sha256(legacy_image)
    cache.put(site_a, legacy_hash, 'baddaywithacamera.com', MODEL,
              cache.prompt_sha256('whatever'), bundle)        # no base hash
    check('a pre-fix row does not reuse, and does not raise',
          cache.find_reusable(legacy_hash, MODEL,
                              cache.prompt_sha256(BASE_PROMPT)) is None)
    # AND IT MUST NOT BE REACHABLE BY ASKING WITH AN EMPTY HASH. A pre-fix row
    # stores '' for the base prompt, so without the guard an empty argument
    # matches it exactly -- and Sean gets a caption written to an instruction
    # nobody can identify. A mutation run removed the guard and every other
    # assertion still passed, because they all asked with a real hash.
    check('an empty prompt hash cannot reach a pre-fix row',
          cache.find_reusable(legacy_hash, MODEL, '') is None)
    check('nor can an empty one reach it via the other site',
          cache.find_reusable(legacy_hash, MODEL, '', prefer_site=site_b) is None)

    # ── 8. THE PROMPT FUNCTION THE KEY DEPENDS ON ──────────────────────────
    # enrich_batch and the restore path must hash the SAME text or every lookup
    # misses and the bill comes back. One function, asserted directly.
    import gemini
    check('an owner prompt is used as-is',
          gemini.base_prompt('  my own words  ', ['Landscapes'], ['Town'])
          == 'my own words')
    built = gemini.base_prompt('', ['Landscapes'], ['Town'])
    check('with no owner prompt, the default is built and is stable',
          built and built == gemini.base_prompt('', ['Landscapes'], ['Town']))
    check('and the built prompt still reflects the categories',
          'Landscapes' in built)
    check('the filename is NOT in the base prompt, which is the whole point',
          'DSC_0041' not in gemini.prompt_with_filename(built, 'x.jpg').replace(
              gemini.base_prompt('', ['Landscapes'], ['Town']), ''))

    # ── 9. MUSE'S FINDING: A PRE-FIX ROW MUST NOT BE RE-PAID ───────────────
    # Every row written before this change stores '' for base_prompt_sha256, so
    # matching only on that column means the first run after the fix re-pays
    # for everything already enriched. "Nothing is re-keyed" was true of the
    # key and misleading about the money.
    #
    # The fallback recomputes the hash the OLD code wrote for this file -- the
    # owner prompt with this filename bound in -- and matches prompt_sha256.
    import gemini
    legacy_file = _write_image(os.path.join(work, 'IMG_7788.jpg'), (20, 20, 200))
    legacy_h = cache.image_sha256(legacy_file)
    old_rendered = gemini.prompt_with_filename(BASE_PROMPT, 'IMG_7788.jpg')
    cache.put(site_a, legacy_h, 'baddaywithacamera.com', MODEL,
              cache.prompt_sha256(old_rendered), bundle)      # no base hash: pre-fix
    check('without the fallback a pre-fix row is a miss, so it would be re-paid',
          cache.find_reusable(legacy_h, MODEL,
                              cache.prompt_sha256(BASE_PROMPT)) is None)
    check('WITH the fallback it is found, and the money is not spent twice',
          cache.find_reusable(
              legacy_h, MODEL, cache.prompt_sha256(BASE_PROMPT),
              legacy_prompt_hashes=(cache.prompt_sha256(old_rendered),)) is not None)
    check('the fallback still refuses a genuinely different owner prompt',
          cache.find_reusable(
              legacy_h, MODEL, cache.prompt_sha256(BASE_PROMPT + ' In French.'),
              legacy_prompt_hashes=(cache.prompt_sha256(
                  gemini.prompt_with_filename(BASE_PROMPT + ' In French.',
                                              'IMG_7788.jpg')),)) is None)
    check('an empty legacy hash is ignored rather than matching everything',
          cache.find_reusable(legacy_h, MODEL, cache.prompt_sha256('nope'),
                              legacy_prompt_hashes=('', None)) is None)

    # ── 10. THE SHIPPED APP IS COVERED, NOT JUST THE Qt ENGINE ─────────────
    # Muse's second finding. desktop-versions.json builds SYBU from main.py,
    # the Tk app, which calls enrich_batch DIRECTLY and never touched
    # sybu_core.enrich_start. A money fix in the engine Sean does not run is
    # not a fix, so the restore now lives inside enrich_batch where every
    # caller gets it.
    gem = open(os.path.join(SYBU, 'gemini.py'), encoding='utf-8').read()
    batch = gem[gem.index('def enrich_batch('):]
    call = batch.find('restore_from_library(')
    first_api = batch.find('model.generate_content(')
    check('enrich_batch consults the library itself', call != -1)
    check('and does so before any generate_content call',
          call != -1 and first_api != -1 and call < first_api)
    check('it passes the legacy hashes, or pre-fix rows get re-paid',
          'legacy_prompt_hashes' in gem)
    tk = open(os.path.join(SYBU, 'main.py'), encoding='utf-8').read()
    check('the shipped Tk app reaches it through enrich_batch',
          'gemini_module.enrich_batch(' in tk)

    # restore_from_library itself, driven directly.
    class _E:
        def __init__(self, name):
            self.file = name
            for f in ('title', 'caption', 'alt', 'tags', 'category', 'album',
                      'colors', 'color_mode', 'orientation'):
                setattr(self, f, '')
    seen = []
    remaining, n = gemini.restore_from_library(
        [_E('breakwater-dusk.jpg'), _E('other.jpg')], work, BASE_PROMPT,
        site=site_b, on_progress=lambda *a: seen.append(a))
    check('the renamed copy is restored and dropped from the batch', n == 1)
    check('and the photograph with no cached answer still goes to the API',
          len(remaining) == 1 and remaining[0].file == 'other.jpg')
    check('the restored entry carries the answer', bool(seen) and
          seen[0][2].title == 'Low Tide At Dusk')
    check('and the UI is told, so reuse is not silent', len(seen) == 1)

    # ── 11. REDO MUST REACH THE API. BEHAVIOURALLY, NOT BY GREP ────────────
    # MUSE'S THIRD FINDING, and the sharpest one. enrich_start(redo=True) skips
    # its own restore pass, then called enrich_batch with no flag -- and
    # enrich_batch restored anyway. So Qt's REDO silently returned the cached
    # answer, never reached Gemini, and marked the row enriched. The button
    # lied, which is exactly what my own comment said must not happen.
    #
    # And MY TEST PASSED THROUGHOUT, because it grepped sybu_core for
    # "if not redo:" -- pinning the wrapper while the behaviour was broken.
    # A source assertion standing in for a behavioural one is the fault I have
    # spent this whole ticket complaining about, so this drives the real call.
    calls = []

    class _FakeModel:
        def __init__(self, name):
            self.name = name

        def generate_content(self, parts):
            calls.append(parts)
            class _R:
                text = chr(10).join([
                    'TITLE: Fresh Answer', 'CAPTION: c', 'ALT: a',
                    'TAGS: #t', 'CATEGORY: Landscapes', 'ALBUM: Around Town',
                    'COLORS: blue', 'COLOR_MODE: colour',
                    'ORIENTATION: landscape'])
            return _R()

    class _FakeGenai:
        def configure(self, **kw):
            pass

        def GenerativeModel(self, name):
            return _FakeModel(name)

    real_genai, real_part = gemini._genai, gemini._load_image_part
    gemini._genai = _FakeGenai()
    gemini._load_image_part = lambda genai, path, max_edge=600: b'PART'
    try:
        # restore=True: the cached answer is used and the API is NEVER called.
        calls.clear()
        kept = gemini.enrich_batch(api_key='k', entries=[_E('breakwater-dusk.jpg')],
                                   image_folder=work, categories=['Landscapes'],
                                   albums=['Around Town'], custom_prompt=BASE_PROMPT,
                                   site=site_b, restore=True)
        check('with restore on, the cached answer is used and Gemini is NOT called',
              len(calls) == 0)
        check('and the restored entry carries the cached title',
              kept and kept[0].title == 'Low Tide At Dusk')

        # THE DEFAULT, called the way the SHIPPED Tk app calls it: main.py
        # passes no restore= at all, so it relies on the default being True.
        # A mutation run flipped that default to False and every other
        # assertion still passed, because they all pass restore= explicitly --
        # while the app Sean actually runs would have paid for every image.
        calls.clear()
        gemini.enrich_batch(api_key='k', entries=[_E('breakwater-dusk.jpg')],
                            image_folder=work, categories=['Landscapes'],
                            albums=['Around Town'], custom_prompt=BASE_PROMPT,
                            site=site_b)
        check('with NO restore argument, as the Tk app calls it, reuse still happens',
              len(calls) == 0)

        # restore=False, which is what a REDO passes: the API IS reached.
        calls.clear()
        redone = gemini.enrich_batch(api_key='k', entries=[_E('breakwater-dusk.jpg')],
                                     image_folder=work, categories=['Landscapes'],
                                     albums=['Around Town'], custom_prompt=BASE_PROMPT,
                                     site=site_b, restore=False)
        check('a REDO reaches generate_content, so the button does something',
              len(calls) == 1)
        check('and it comes back with the FRESH answer, not the cached one',
              redone and redone[0].title == 'Fresh Answer')
    finally:
        gemini._genai, gemini._load_image_part = real_genai, real_part

    # And the wiring: enrich_start must pass restore=not redo, or the wrapper's
    # own skip is undone one call later.
    core_src = open(os.path.join(SYBU, 'sybu_core.py'), encoding='utf-8').read()
    check('enrich_start passes restore=not redo to enrich_batch',
          'restore=not redo' in core_src)

    # ── 12. THE LOOKUP IS ACTUALLY IN FRONT OF THE PAID CALL ───────────────
    # The cache layer above is proved by behaviour. The ORCHESTRATION is not:
    # driving enrich_start needs a connected SYBU engine, a Gemini key and a
    # real folder, which this test has none of. A correct cache that nothing
    # consults is exactly the fault being repaired, so the wiring is pinned at
    # the source with the reason stated rather than left unchecked.
    core = open(os.path.join(SYBU, 'sybu_core.py'), encoding='utf-8').read()
    start = core.index('def enrich_start(')
    body = core[start:]
    call = body.find('restore_enrichment_for(')
    paid = body.find('g.enrich_batch(')
    check('enrich_start consults the library', call != -1)
    check('and it does so BEFORE the Gemini call, not after',
          call != -1 and paid != -1 and call < paid)
    check('a redo deliberately skips the library, or the button would do nothing',
          'if not redo:' in body[:paid])

    shutil.rmtree(work, ignore_errors=True)


if __name__ == '__main__':
    try:
        main()
    finally:
        shutil.rmtree(_HOME, ignore_errors=True)
    if _fails:
        raise SystemExit('\n%d FAILURE(S)\n' % _fails)
    print('enrichment reuse across sites: PASS')

# ===== SNAPSMACK EOF =====
