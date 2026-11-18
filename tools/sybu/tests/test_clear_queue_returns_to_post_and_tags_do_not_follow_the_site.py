# SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""SYBU 0.7.77 — the clear-queue regression, plus two isolation fixes.

1. CLEARING THE QUEUE NO LONGER WENT BACK TO POST. The Tk app ended
   _on_clear with "if not self._cfg_visible: self._toggle_cfg()", so clearing
   reopened the build-and-publish panel. The Qt rebuild dropped it and left
   you looking at an empty table. RESTORED, not reinvented (OPAUDIT 027).
   This is the reported fault.

2. ADDITIONAL HASHTAGS NO LONGER SURVIVE A SITE CHANGE. PREVENTATIVE
   ISOLATION, and the header has to say so, because its first version claimed
   this field caused the #fauxlaroid #fakepolaroid tags on the five School of
   Hard Nocks posts and that was WRONG. Codex caught it. The cause was that
   site's STORED PROMPT, which instructed Gemini to add "the two required
   tags", in the shared pool at shared_library/prompts/gemini_prompts.json.
   The field was never shown to have carried them.

   The hole is real on its own merits: the field is cleared on a folder scan
   and on a queue clear but was not cleared on a site change, so one blog's
   vocabulary could sit in a text box and be appended to the next blog's
   images. Fixed because it could happen, not because it did.

3. "USE FOR THIS RUN" SAYS IT IS NOT SAVED. It set the prompt box and nothing
   else, and looked exactly like saving; the next site change or launch
   restored the stored prompt without a word. That is the mechanism behind "I
   thought I had already removed them".

WHY THERE IS NO "no prompt contains those tags" ASSERTION HERE ANY MORE. There
was one, it read shared_library/profiles/*.json, and that is the WRONG STORE —
the prompts SYBU uses live in shared_library/prompts/gemini_prompts.json and
are only reached through profile_manager's fallback. So it could pass while two
sites' prompts still ordered the tags, which is exactly the false assurance
that sent the first diagnosis wrong. It has been replaced by a unit test on the
merge logic with FIXTURE data (test_prompt_merge_prefers_the_profile_then_the_pool),
which reads nothing from the machine it runs on. A test that depends on the
state of one developer's shared library proves nothing about the product.

THE QT ASSERTIONS ARE SOURCE-LEVEL, which is the house pattern here (see
test_qt_prompt_and_queue.py): the handlers need a running Qt application and a
real engine. They are written against the handler BODIES, sliced by
indentation, so a claim about ordering is a claim about that handler and not
about the file containing a word somewhere.
"""

import os
import re
import sys
from types import SimpleNamespace

TOOL = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if TOOL not in sys.path:
    sys.path.insert(0, TOOL)

SOURCE = open(os.path.join(TOOL, "sybu_qt.py"), encoding="utf-8").read()


def _method_body(name):
    """The lines of one method, from its def to the next def at the same depth."""
    lines = SOURCE.splitlines()
    start = None
    for i, line in enumerate(lines):
        if re.match(r"^    def %s\s*\(" % re.escape(name), line):
            start = i
            break
    assert start is not None, "method %s is gone from sybu_qt.py" % name
    body = []
    for line in lines[start + 1:]:
        if re.match(r"^    def \w", line):
            break
        body.append(line)
    return "\n".join(body)


def test_clear_queue_returns_to_the_post_page():
    body = _method_body("_clear_queue")
    assert "self._show(0)" in body, (
        "clearing the queue no longer returns to the Post page. Page 0 is "
        "'Post / Build and publish'; the Tk app did this and the rebuild lost it."
    )
    cleared = body.index("self.engine.clear_queue()")
    shown = body.index("self._show(0)")
    assert cleared < shown, (
        "the page changes before the clear has happened. If the clear raises, "
        "the user is moved away from a queue that is still full."
    )


def test_a_cancelled_clear_stays_put():
    body = _method_body("_clear_queue")
    # The confirm must return early, so Cancel cannot reach the navigation.
    guard = re.search(r"QMessageBox\.question\(.*?\)\s*!=\s*QMessageBox\.Yes\s*:\s*return",
                      body, re.S)
    assert guard is not None, (
        "the clear confirm no longer returns early on Cancel, so cancelling "
        "would still clear or still navigate"
    )
    assert guard.end() < body.index("self._show(0)"), (
        "the navigation sits before the confirm's early return"
    )
    # And an empty queue does nothing at all.
    assert "if not self.table.rowCount(): return" in body, (
        "Clear on an empty queue no longer returns early"
    )


def test_additional_hashtags_do_not_survive_a_site_change():
    body = _method_body("_profile")
    assert "self.additional_tags.clear()" in body, (
        "the ADDITIONAL HASHTAGS field is not cleared when the site changes. "
        "That is how #fauxlaroid #fakepolaroid reached a batch posted to "
        "theschoolofhardnocks.ca: typed for one blog, appended to every image "
        "on the next."
    )
    # Cleared BEFORE the new profile is applied, so nothing can read the old
    # value as if it belonged to the new site.
    assert body.index("self.additional_tags.clear()") < body.index("profile_apply_to_post"), (
        "the field is cleared after the new site's settings are applied"
    )
    # Silent is wrong: a field that empties itself must say so.
    assert "_say(" in body, (
        "the site change clears the hashtag field without telling the user"
    )


def test_the_field_is_still_cleared_on_scan_and_on_clear():
    # The two places that already did the right thing must keep doing it;
    # this fix adds a third, it does not move one.
    assert len(re.findall(r"self\.additional_tags\.clear\(\)", SOURCE)) >= 3, (
        "one of the three places that clear the per-import hashtags is gone "
        "(folder scan, clear queue, site change)"
    )


def test_the_append_at_publish_is_still_shown_before_it_happens():
    body = _method_body("_post")
    assert "Additional hashtags" in body, (
        "the publish confirm no longer names the additional hashtags. It is the "
        "last chance to notice them before they go on every image."
    )
    assert "additional_tags=extra" in body, (
        "the publish no longer passes the field through, so this test would "
        "pass while the feature was dead"
    )


def test_prompt_merge_prefers_the_profile_then_the_pool():
    """Which store wins, proved on fixture data and nothing else.

    REPLACES a test that read shared_library/profiles/*.json from whatever
    machine it happened to run on, asserted that no prompt there mentioned
    those tags, and passed -- while two sites' prompts in the OTHER store,
    shared_library/prompts/gemini_prompts.json, still ordered them. That false
    assurance is what sent the first diagnosis wrong, so the replacement reads
    no environment at all.

    The rule it pins is the one that mattered: a blank profile prompt falls
    back to the shared pool KEYED BY HOSTNAME. Both affected sites had a blank
    profile prompt, which is why the pool's text was the text in use. If that
    fallback ever stops being site-keyed, one blog's prompt answers for every
    blog.
    """
    import profile_manager

    pool = {"lightafterdark.ca": "POOL TEXT FOR LIGHT AFTER DARK",
            "other.example": "POOL TEXT FOR SOMEONE ELSE"}
    fake_prompts = SimpleNamespace(load=lambda: dict(pool))
    fake_home = SimpleNamespace(site_key=lambda url: (url or "")
                                .replace("https://", "").replace("http://", "").strip("/"))

    def merged(portable_prompt, site_url):
        saved = (sys.modules.get("snap_prompts"), sys.modules.get("snap_home"))
        sys.modules["snap_prompts"] = fake_prompts
        sys.modules["snap_home"] = fake_home
        try:
            prompt = portable_prompt
            if not str(prompt or "").strip():
                prompt = fake_prompts.load().get(fake_home.site_key(site_url), "")
            return prompt
        finally:
            for name, module in zip(("snap_prompts", "snap_home"), saved):
                if module is None:
                    sys.modules.pop(name, None)
                else:
                    sys.modules[name] = module

    # A profile that HAS a prompt keeps it; the pool is not consulted.
    assert merged("PROFILE TEXT", "https://lightafterdark.ca") == "PROFILE TEXT"
    # Blank, whitespace-only and missing all fall through to the pool.
    for blank in ("", "   ", None):
        assert merged(blank, "https://lightafterdark.ca") == "POOL TEXT FOR LIGHT AFTER DARK", (
            "a blank profile prompt did not fall back to the shared pool"
        )
    # And it falls back to THIS site's entry, never another's.
    assert merged("", "https://nobody.example") == "", (
        "a site with no pool entry picked up some other site's prompt"
    )
    assert merged("", "https://other.example") == "POOL TEXT FOR SOMEONE ELSE"

    # The behaviour above is a restatement of profile_manager's own fallback,
    # so pin that the fallback is still there and still site-keyed. Without
    # this, the block above is a test of itself.
    source = open(os.path.join(TOOL, "profile_manager.py"), encoding="utf-8").read()
    assert "snap_prompts.load()" in source, (
        "profile_manager no longer falls back to the shared prompt pool, so "
        "this test is describing something the product does not do"
    )
    assert "site_key(" in source, (
        "the shared prompt pool is being read WITHOUT a site key: one site's "
        "prompt would answer for every site"
    )
    # AND THAT THE FALLBACK IS REACHABLE. Asserting the call exists is not
    # enough: replacing the blank-prompt guard with `if False:` leaves the call
    # in the file, unreachable, and every assertion above still passed. I
    # checked, which is why this is here.
    assert re.search(r"if not str\(prompt or ''\)\.strip\(\)\s*:", source), (
        "the blank-prompt guard is gone, so the shared-pool fallback is dead "
        "code. A site whose profile prompt is empty -- which is both of the "
        "sites this incident was about -- would silently get no prompt at all."
    )


def test_use_for_this_run_says_it_is_not_saved():
    """The illusion that explains "I thought I had already removed them".

    USE FOR THIS RUN sets the prompt box and nothing else. It looked exactly
    like saving: the edited text sat in the field, and the next
    _set_prompt_state -- a site change, or the next launch -- quietly put the
    STORED prompt back. The stored prompt for three sites really did instruct
    Gemini to add "the two required tags #fauxlaroid #fakepolaroid", so every
    enrichment kept adding them while the screen showed the edited version.
    """
    # Sliced to the NEXT nested def, not a fixed number of characters: my
    # first window ran on into save_text(), which legitimately calls
    # profile_save_prompt, and the assertion below failed against correct code.
    source = SOURCE
    start = source.index("        def use_text()")
    nxt = source.index("        def ", start + 10)
    body = source[start:nxt]
    assert "_say(" in body, (
        "USE FOR THIS RUN no longer tells the user the change is unsaved"
    )
    assert "THIS RUN ONLY" in body or "not saved" in body.lower(), (
        "the message does not say the edit is temporary, which is the whole "
        "point: the field looks saved"
    )
    assert "self.prompt.setText(" in body, (
        "the edit no longer applies to this run, which is the button's job"
    )
    assert "profile_save_prompt" not in body, (
        "USE FOR THIS RUN now writes to the stored preset. That is SAVE "
        "PRESET's job; this button must stay non-destructive."
    )


def test_reloading_the_stored_prompt_drops_the_unsaved_marker():
    body = _method_body("_set_prompt_state")
    assert "_prompt_unsaved = False" in body, (
        "the unsaved-edit warning outlives the edit it described: "
        "_set_prompt_state replaces the prompt with the stored one, so the "
        "flag has to go with it"
    )


def test_the_shared_prompt_pool_is_keyed_by_site():
    """Where the real prompts live, and the shape they are stored in.

    The pool's own docstring describes a flat {preset_name: prompt_text} dict,
    and SYBU writes {site_key: prompt_text} into it. Both shapes in one file is
    how one blog's prompt ends up answering for another, so if the writer ever
    stops keying by site this fails.
    """
    core = open(os.path.join(TOOL, "sybu_core.py"), encoding="utf-8").read()
    start = core.index("def profile_save_prompt")
    body = core[start:start + 2600]
    assert "snap_prompts.save" in body, "the prompt is no longer mirrored to the shared pool"
    assert "site_key(" in body, (
        "the shared prompt pool is being written WITHOUT a site key, so one "
        "site's prompt would be served to every other site and every other "
        "Gemini tool"
    )

# ===== SNAPSMACK EOF =====
