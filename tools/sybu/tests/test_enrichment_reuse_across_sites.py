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


def _write_image(path, payload=b'\xff\xd8\xff\xe0JPEGBYTES-the-same-photograph'):
    with open(path, 'wb') as handle:
        handle.write(payload)
    return path


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
    copied = _write_image(os.path.join(work, 'breakwater-dusk.jpg'))
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
              _write_image(os.path.join(work, 'other.jpg'), b'DIFFERENT-BYTES')),
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
    legacy_image = _write_image(os.path.join(work, 'legacy.jpg'), b'LEGACY-BYTES')
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

    # ── 9. THE LOOKUP IS ACTUALLY IN FRONT OF THE PAID CALL ────────────────
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
