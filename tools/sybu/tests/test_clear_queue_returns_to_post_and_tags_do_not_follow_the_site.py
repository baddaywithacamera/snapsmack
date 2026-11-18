# SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""SYBU 0.7.77 — two faults Sean reported on 0.7.76.

1. CLEARING THE QUEUE NO LONGER WENT BACK TO POST. The Tk app ended
   _on_clear with "if not self._cfg_visible: self._toggle_cfg()", so clearing
   reopened the build-and-publish panel. The Qt rebuild dropped it and left
   you looking at an empty table. RESTORED, not reinvented (OPAUDIT 027).

2. #fauxlaroid #fakepolaroid WENT UP ON A BATCH TO ANOTHER BLOG. The
   ADDITIONAL HASHTAGS field is for one import. It is cleared when a folder
   is scanned and when the queue is cleared, but NOT when the site changed,
   so a value typed for fauxlaroid.fyi was still in the box for
   theschoolofhardnocks.ca and post_start(additional_tags=...) appended it to
   every image. The same append is why removing a tag from the queue rows did
   not stick: the rows were right, and the field put it back at publish.

   Checked before fixing: NO profile prompt contains those tags. All 28
   profiles in the shared library were read; 'fauxlaroid' and 'fakepolaroid'
   appear only in fauxlaroid.fyi's own name and site_url. So the prompt, the
   presets and a per-site setting are all ruled out as the source.

THESE ARE SOURCE ASSERTIONS ON THE QT SHELL, which is the house pattern here
(see test_qt_prompt_and_queue.py): the handlers need a running Qt application
and a real engine. They are written against the handler BODIES, brace-free
Python so sliced by indentation, so a claim about ordering is a claim about
that handler and not about the file containing a word somewhere.
"""

import os
import re
import sys

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


def test_no_profile_prompt_carries_those_tags():
    """The claim this repair rests on, recorded.

    If a prompt ever does contain them, the cause is different and the fix
    above is not enough -- so this fails rather than letting someone assume
    the hashtag field was the whole story.
    """
    try:
        sys.path.insert(0, os.path.abspath(os.path.join(TOOL, "..", "_shared")))
        import snap_profiles
        directory = snap_profiles.profiles_dir()
    except Exception:
        return  # no shared library on this machine; nothing to check
    if not os.path.isdir(directory):
        return
    offenders = []
    for name in os.listdir(directory):
        if not name.endswith(".json"):
            continue
        try:
            text = open(os.path.join(directory, name), encoding="utf-8").read()
        except OSError:
            continue
        for needle in ("fauxlaroid", "fakepolaroid"):
            # The site's own name and url legitimately contain it.
            if needle in text.lower() and not name.lower().startswith("fauxlaroid"):
                offenders.append("%s mentions %s" % (name, needle))
    assert not offenders, (
        "a profile other than fauxlaroid's mentions those tags, so the source "
        "is not only the per-import field: " + "; ".join(offenders)
    )

# ===== SNAPSMACK EOF =====
