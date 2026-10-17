"""TICKET WICKET tests.

The ones that matter are the refusals. A ticketing tool that merely stores
tickets is easy; what is hard is that it must not publish a person, must not
send, must not delete, and must not let an agent sign as somebody else.

Each of those is tested by trying to do it and watching the code say no.

Run:  .python-build/python.exe -m pytest tools/ticket-wicket/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
from datetime import datetime, timedelta

import pytest

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

from ticketwicket import anonymise, forum as forummod, model, store as storemod


@pytest.fixture()
def store(tmp_path):
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.CLAUDE)
    yield s
    s.close()


# ── urgency: Sean's three inputs ─────────────────────────────────────────────

def test_security_outranks_everything_below_it():
    assert model.urgency(security=True, usability="cosmetic", affected=1) == model.DOOR_OPEN


def test_data_at_risk_is_the_top_band():
    assert model.urgency(data_at_risk=True) == model.LOSING_WORK


def test_one_site_broken_and_the_whole_fleet_cosmetic_can_match():
    # The reason urgency is computed and not picked off a ladder.
    one_site_stopped = model.urgency(usability="stopped", affected=1)
    fleet_cosmetic = model.urgency(usability="cosmetic", affected=9)
    assert one_site_stopped == fleet_cosmetic == model.WORKING_ROUND_IT


def test_stopped_across_the_fleet_is_the_worst_non_security_band():
    assert model.urgency(usability="stopped", affected=9) == model.STOPPED


def test_a_lone_cosmetic_request_is_wanted():
    assert model.urgency(usability="cosmetic", affected=1) == model.WANTED


# ── the store: no delete, real audit, monotonic ids ──────────────────────────

def test_there_is_no_delete():
    # GL-6: moderation hides, never destroys. Enforced by absence.
    assert not hasattr(storemod.Store, "delete")
    assert not hasattr(storemod.Store, "destroy")


def test_ids_are_monotonic_and_never_reused(store):
    a = store.create("one"); b = store.create("two")
    assert a.id != b.id
    assert int(b.id.split("-")[1]) == int(a.id.split("-")[1]) + 1


def test_every_change_is_audited_with_who_did_it(store):
    t = store.create("thing")
    store.update(t.id, state=model.ANSWERED)
    kinds = [(e.author, e.kind) for e in store.get(t.id).entries]
    assert (model.CLAUDE, "audit") in kinds


def test_the_actor_cannot_claim_to_be_someone_else(tmp_path):
    # The store stamps who is driving; it is not an argument the caller passes.
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.CHATGPT)
    t = s.create("thing")
    assert all(e.author == model.CHATGPT for e in s.get(t.id).entries)
    s.close()


def test_merging_keeps_both_readable(store):
    a = store.create("dupe"); b = store.create("real")
    store.merge(a.id, b.id)
    assert store.get(a.id) is not None          # still there, still readable
    assert store.get(a.id).merged_into == b.id
    assert store.get(a.id).state == model.WITHDRAWN


# ── the outbox: the CLI cannot send ──────────────────────────────────────────

def test_queueing_does_not_send(store):
    t = store.create("thing")
    store.enqueue(t.id, "email", "a reply")
    row = store.outbox()[0]
    assert row["state"] == "queued"
    assert row["sent"] == ""


def test_the_store_has_no_way_to_send():
    # A separate armed sender holds the only credential. Enforced by absence.
    assert not hasattr(storemod.Store, "send")
    assert not hasattr(storemod.Store, "deliver")


def test_a_pulse_ticket_refuses_an_agent_reply(store):
    # GL-4: anything with a pulse reaches the human.
    t = store.create("someone is upset", pulse=True)
    with pytest.raises(PermissionError, match="pulse"):
        store.enqueue(t.id, "email", "there there")


def test_sean_may_still_reply_to_a_pulse_ticket(tmp_path):
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.SEAN)
    t = s.create("someone is upset", pulse=True)
    assert s.enqueue(t.id, "email", "I'm on it") > 0
    s.close()


def test_nobody_signs_as_an_unknown_author(store):
    t = store.create("thing")
    with pytest.raises(ValueError, match="signs as themselves"):
        store.enqueue(t.id, "email", "x", author="someone_else")


def test_the_four_signatures_are_distinct():
    # Sean: "I would expect you and ChatGPT to sign as yourselves."
    sigs = set(model.SIGNATURES.values())
    assert len(sigs) == 4
    assert model.SIGNATURES[model.ASSISTANT] != model.SIGNATURES[model.CLAUDE]
    assert "Claude" not in model.SIGNATURES[model.ASSISTANT]


def test_the_acknowledgement_is_a_constant_that_says_it_is_an_ai():
    # It is SENT, never composed. A model that generates an acknowledgement can
    # be steered into generating something else.
    assert "AI assistant" in model.ACKNOWLEDGEMENT
    assert "{" not in model.ACKNOWLEDGEMENT      # no placeholders to fill


# ── anonymity: the rule that makes public tickets safe ───────────────────────

@pytest.mark.parametrize("secret", [
    "sean@example.com",
    "allinthewrist.photoblogs.fyi",
    "203.0.113.9",
    "https://craptasti.ca/album/my-kids",
    r"C:\snapsmack\logs\x.log",
    "@sean@photoblogs.fyi",
    "deadbeefcafe1234567890abcdef",
])
def test_identifying_things_do_not_survive_scrubbing(secret):
    out = anonymise.scrub(f"the user said {secret} is broken")
    assert secret not in out


def test_check_catches_what_scrub_missed():
    # A scrubber nobody watches fail is trusted for no reason.
    assert anonymise.check("ping 198.51.100.7 please")
    assert anonymise.check("clean text about a broken border") == []


def test_the_reporters_own_identifier_is_caught_even_if_generic():
    problems = anonymise.check("thanks, Bartholomew", extra=["Bartholomew"])
    assert any("reporter's own identifier" in p for p in problems)


def test_the_forum_post_carries_no_private_field(store):
    t = store.create(
        "borders missing",
        body="The animated ring does not appear on the grid skins.",
        reporter="sean@example.com", reporter_channel="email",
        original="my borders are gone, site allinthewrist.photoblogs.fyi",
    )
    post = anonymise.forum_post(store.get(t.id))
    assert "sean@example.com" not in post
    assert "allinthewrist" not in post
    assert "my borders are gone" not in post      # the original never travels
    assert anonymise.check(post, ["sean@example.com"]) == []


def test_public_dict_omits_reporter_and_original(store):
    t = store.create("x", reporter="a@b.ca", original="private words")
    pub = store.get(t.id).public_dict()
    assert "reporter" not in pub and "original" not in pub
    assert "private words" not in str(pub)


# ── staleness: the mechanism behind "keep me honest" ─────────────────────────

def test_a_wanted_ticket_is_never_stale(store):
    t = store.create("nice to have")          # band WANTED
    t.updated = (datetime.now() - timedelta(days=900)).isoformat()
    assert t.is_stale() is False


def test_a_losing_work_ticket_goes_stale_in_a_day(store):
    t = store.create("corruption", data_at_risk=True)
    t.updated = (datetime.now() - timedelta(days=2)).isoformat()
    assert t.band == model.LOSING_WORK
    assert t.is_stale() is True


def test_a_closed_ticket_is_never_stale(store):
    t = store.create("corruption", data_at_risk=True)
    store.update(t.id, state=model.CLOSED)
    done = store.get(t.id)
    done.updated = (datetime.now() - timedelta(days=900)).isoformat()
    assert done.is_stale() is False


def test_the_queue_puts_the_worst_band_first(store):
    store.create("idea")                                  # WANTED
    store.create("hole", security=True)                   # DOOR_OPEN
    store.create("corruption", data_at_risk=True)         # LOSING_WORK
    bands = [t.band for t in store.queue()]
    assert bands[0] == model.LOSING_WORK
    assert bands[1] == model.DOOR_OPEN


# ── the forum: what exists, and what honestly does not ───────────────────────

def test_small_counts_are_not_disclosed():
    # With a tiny fleet "2 affected" plus knowing you are not one of them
    # narrows it to two people. Arithmetic, not a bug.
    assert forummod.Forum.public_affected(2) == "a few"
    assert forummod.Forum.public_affected(9) == "9"


def test_the_client_refuses_to_run_without_a_key():
    f = forummod.Forum("https://snapsmack.ca/api/forum", "")
    with pytest.raises(forummod.ForumError, match="inert without one"):
        f.categories()


def test_the_two_missing_endpoints_say_so_rather_than_faking_it():
    f = forummod.Forum("https://x", "k")
    with pytest.raises(NotImplementedError, match="no move endpoint"):
        f.move_thread(1, 2)
    with pytest.raises(NotImplementedError, match="Breadcrumbs do not exist"):
        f.leave_breadcrumb(1, "t", 2)

# ===== SNAPSMACK EOF =====
