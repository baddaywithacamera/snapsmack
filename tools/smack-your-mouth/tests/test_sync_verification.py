"""SMACK YOUR MOUTH: an unconfirmed push is not a finished one. SS-1332.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

THE FAULT. This tool's whole claim, in its own spec, is the store-and-forward
SyncEngine with POSITIVE VERIFICATION: a decision or reply is only marked done
once the spoke confirms it. The engine honoured that. The verifier did not:

    still_pending = self._is_in_pending(cid)
    if still_pending is None:
        # Cannot read at all - trust the explicit 'ok' from apply_action
        return True

_is_in_pending returns None when the spoke CANNOT BE READ. So the one case
verification exists for -- we pushed something and cannot see whether it landed
-- returned success. The item was marked SYNCED and, because sync_all skips
synced items, nothing ever asked again.

WHY DELETE IS THE BAD ONE. An approve that silently did not take leaves a
comment unapproved: visible, recoverable, nobody harmed. A delete reported as
done means the operator believes a visitor's comment is gone from a live site
when it may still be published, and no later signal will ever contradict that.

THE FIX. verify_* may now answer three ways -- True confirmed, False
contradicted, None nobody knows -- and None lands the item in ST_UNVERIFIED
with its work still queued, so the next sync asks again.

AND THE TRAP IN THE FIX. A retry must not re-post a reply that already went
out. The first attempt got a reply id back; only the confirmation was missing.
Re-posting would put the operator's words under a visitor's comment twice,
which is worse than the problem retrying solves. The last group here is about
that.

These are the first automated tests this tool has had. It approves, spams and
deletes comments on live sites and posts replies under the hub's name, and
until now 2,711 lines of it were covered by nothing.
"""

import os
import sys

TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(os.path.dirname(TOOL), '_shared')
for folder in (TOOL, SHARED):
    if folder not in sys.path:
        sys.path.insert(0, folder)

import moderation_offline as mo                                    # noqa: E402

_fails = 0


def check(label, condition):
    global _fails
    print(('ok:   ' if condition else 'FAIL: ') + label)
    if not condition:
        _fails += 1


# ── doubles ────────────────────────────────────────────────────────────────

class FakeSession:
    """Just enough Session for the engine: it saves items."""

    def __init__(self, items=None):
        self.items = list(items or [])
        self.saves = 0

    def save_item(self, item):
        self.saves += 1
        for i, existing in enumerate(self.items):
            if existing.item_id == item.item_id:
                self.items[i] = item
                return
        self.items.append(item)

    def list_items(self):
        return list(self.items)


class FakePoster:
    """A poster whose verify answers are scripted, so the ENGINE is what is
    being measured and not a network."""

    def __init__(self, decision_verdict=True, reply_verdict=True,
                 decision_ok=True, reply_ok=True, reply_id=99):
        self.decision_verdict = decision_verdict
        self.reply_verdict = reply_verdict
        self.decision_ok = decision_ok
        self.reply_ok = reply_ok
        self.reply_id = reply_id
        self.applied = []
        self.posted = []
        self.verified_decisions = []
        self.verified_replies = []

    def apply_decision(self, item):
        self.applied.append(item.action)
        return mo.SyncResult(self.decision_ok, message='' if self.decision_ok else 'refused')

    def post_reply(self, item):
        self.posted.append(item.reply_text)
        return mo.SyncResult(self.reply_ok, remote_id=self.reply_id,
                             message='' if self.reply_ok else 'refused')

    def verify_decision(self, item):
        self.verified_decisions.append(item.action)
        return self.decision_verdict

    def verify_reply(self, item):
        self.verified_replies.append(item.remote_reply_id)
        return self.reply_verdict


def make_item(action='', reply='', comment_id=7):
    comment = mo.CommentRecord(comment_id=comment_id,
                               comment_author='A Visitor',
                               comment_text='hello')
    item = mo.ModItem(item_id='i-%d' % comment_id,
                      site_key='demo_example', site_url='https://demo.example',
                      site_name='Demo', comment=comment)
    if action:
        item.set_action(action)
    if reply:
        item.set_reply(reply)
    return item


def run(item, poster):
    session = FakeSession([item])
    events = []
    engine = mo.SyncEngine(session, poster,
                           on_event=lambda phase, it, msg: events.append((phase, msg)))
    result = engine._sync_one(item)
    return result, events


# ── 1. the fault itself ────────────────────────────────────────────────────

def test_an_unconfirmable_delete_is_not_reported_done():
    for action in (mo.ACT_DELETE, mo.ACT_APPROVE, mo.ACT_SPAM):
        item = make_item(action=action)
        result, events = run(item, FakePoster(decision_verdict=None))
        check('a %s nobody could confirm is NOT synced (was the bug)' % action,
              item.status != mo.ST_SYNCED)
        check('  it is recorded as unverified, not as a failure' % (),
              item.status == mo.ST_UNVERIFIED)
        check('  the sync reports it as not ok', result.ok is False)
        check('  and the operator is told why',
              'NOT confirmed' in (item.error or ''))
        check('  an "unverified" event is emitted',
              any(phase == 'unverified' for phase, _ in events))


def test_the_queued_work_survives_so_it_can_be_asked_again():
    item = make_item(action=mo.ACT_DELETE)
    run(item, FakePoster(decision_verdict=None))
    check('the delete is still queued on the item', item.has_work())
    check('and the action is intact', item.action == mo.ACT_DELETE)
    session = FakeSession([item])
    due = [it for it in session.list_items()
           if it.has_work() and it.status != mo.ST_SYNCED]
    check('so the next sync_all picks it up again', item in due)


def test_a_confirmed_decision_is_still_synced():
    item = make_item(action=mo.ACT_APPROVE)
    result, _events = run(item, FakePoster(decision_verdict=True))
    check('the happy path is unchanged: confirmed means synced',
          item.status == mo.ST_SYNCED and result.ok)


def test_a_contradicted_decision_still_fails():
    item = make_item(action=mo.ACT_APPROVE)
    result, _events = run(item, FakePoster(decision_verdict=False))
    check('a decision the spoke contradicts is a failure, not unverified',
          item.status == mo.ST_FAILED and not result.ok)
    check('  and says the server did not reflect it',
          'did not reflect' in (item.error or ''))


def test_unverified_and_failed_are_different_states():
    """They read the same to a careless eye and must not be the same value."""
    check('the two states are distinct strings',
          mo.ST_UNVERIFIED != mo.ST_FAILED)
    unknown = make_item(action=mo.ACT_DELETE, comment_id=1)
    run(unknown, FakePoster(decision_verdict=None))
    refused = make_item(action=mo.ACT_DELETE, comment_id=2)
    run(refused, FakePoster(decision_verdict=False))
    check('an unreadable spoke and a refusing spoke do not look alike',
          unknown.status != refused.status)


def test_a_push_the_spoke_refused_is_still_a_failure():
    item = make_item(action=mo.ACT_DELETE)
    poster = FakePoster(decision_ok=False)
    _result, _events = run(item, poster)
    check('a refused push fails before verification is even asked',
          item.status == mo.ST_FAILED and poster.verified_decisions == [])


def test_a_verifier_that_throws_is_a_failure_not_a_pass():
    class Exploding(FakePoster):
        def verify_decision(self, item):
            raise RuntimeError('socket closed')

    item = make_item(action=mo.ACT_DELETE)
    run(item, Exploding())
    check('an exception during verification fails the item',
          item.status == mo.ST_FAILED)
    check('  and keeps the reason', 'socket closed' in (item.error or ''))


# ── 2. replies ─────────────────────────────────────────────────────────────

def test_an_unconfirmable_reply_is_not_reported_done():
    item = make_item(reply='thank you')
    result, events = run(item, FakePoster(reply_verdict=None))
    check('a reply nobody could confirm is unverified',
          item.status == mo.ST_UNVERIFIED and not result.ok)
    check('  and says so plainly', 'NOT confirmed' in (item.error or ''))
    check('  with an unverified event',
          any(phase == 'unverified' for phase, _ in events))


def test_a_reply_the_spoke_denies_still_fails():
    item = make_item(reply='thank you')
    run(item, FakePoster(reply_verdict=False))
    check('a reply the spoke does not show is a failure',
          item.status == mo.ST_FAILED)


# ── 3. THE TRAP IN THE FIX: a retry must not post the reply twice ──────────

def test_retrying_an_unverified_reply_verifies_instead_of_posting_again():
    item = make_item(reply='thank you')
    first = FakePoster(reply_verdict=None)
    run(item, first)
    check('the first attempt posted the reply once', len(first.posted) == 1)
    check('  and held on to the reply id', item.remote_reply_id == 99)
    check('  and left it unverified', item.status == mo.ST_UNVERIFIED)

    second = FakePoster(reply_verdict=True)
    run(item, second)
    check('THE TRAP: the retry did NOT post the reply again', second.posted == [])
    check('  it verified the reply it already had',
          second.verified_replies == [99])
    check('  and the item is now synced', item.status == mo.ST_SYNCED)


def test_a_retry_still_posts_when_no_reply_ever_went_out():
    """The skip must key on a reply id actually held, not on the status."""
    item = make_item(reply='thank you')
    item.status = mo.ST_UNVERIFIED          # unverified, but nothing was posted
    poster = FakePoster(reply_verdict=True)
    run(item, poster)
    check('an item with no reply id does post the reply', poster.posted == ['thank you'])
    check('  and is synced once confirmed', item.status == mo.ST_SYNCED)


def test_a_reply_plus_decision_retry_does_not_duplicate_either():
    item = make_item(action=mo.ACT_APPROVE, reply='thanks')
    first = FakePoster(reply_verdict=True, decision_verdict=None)
    run(item, first)
    check('the reply went out and the decision could not be confirmed',
          first.posted == ['thanks'] and item.status == mo.ST_UNVERIFIED)
    second = FakePoster(reply_verdict=True, decision_verdict=True)
    run(item, second)
    check('the retry re-sent no reply', second.posted == [])
    check('  re-applied the decision, which is safe and idempotent',
          second.applied == [mo.ACT_APPROVE])
    check('  and the item is synced', item.status == mo.ST_SYNCED)


# ── 4. what the operator is shown ──────────────────────────────────────────

def test_unverified_items_are_counted_separately_from_ready():
    ready = make_item(action=mo.ACT_APPROVE, comment_id=1)
    unverified = make_item(action=mo.ACT_DELETE, comment_id=2)
    run(unverified, FakePoster(decision_verdict=None))
    synced = make_item(action=mo.ACT_APPROVE, comment_id=3)
    run(synced, FakePoster(decision_verdict=True))
    failed = make_item(action=mo.ACT_APPROVE, comment_id=4)
    run(failed, FakePoster(decision_verdict=False))

    counts = mo.Session.counts(FakeSession([ready, unverified, synced, failed]))
    check('unverified has its own count', counts.get('unverified') == 1)
    check('  it is NOT folded into ready', counts.get('ready') == 1)
    check('  synced counts only what was confirmed', counts.get('synced') == 1)
    check('  failed counts only what was contradicted', counts.get('failed') == 1)
    check('  and the total still adds up',
          counts['ready'] + counts['unverified'] + counts['synced']
          + counts['failed'] + counts['pending'] == counts['total'])


# ── 5. the verifier itself, where the bug lived ────────────────────────────

def test_the_poster_says_unknown_when_it_cannot_read_the_spoke():
    try:
        import moderation_api
    except ImportError as exc:            # requests is not in every environment
        print('skip: moderation_api needs requests (%s)' % exc)
        return

    class DeadConnection:
        def pull_pending(self):
            raise RuntimeError('connection refused')

        def get_comment(self, _cid):
            return None

        def apply_action(self, _cid, _action):
            return True, 'ok'

    poster = moderation_api.MouthPoster(DeadConnection())
    for action in (mo.ACT_DELETE, mo.ACT_APPROVE, mo.ACT_SPAM):
        item = make_item(action=action)
        check('an unreadable spoke verifies %s as UNKNOWN, not True' % action,
              poster.verify_decision(item) is None)
    reply_item = make_item(reply='hi')
    reply_item.remote_reply_id = 5
    check('and an unreadable spoke verifies a reply as UNKNOWN too',
          poster.verify_reply(reply_item) is None)


def test_the_poster_still_contradicts_a_delete_that_did_not_take():
    try:
        import moderation_api
    except ImportError:
        return

    class LiveConnection:
        """The comment is still there — the delete did not happen."""

        def get_comment(self, _cid):
            return {'id': 7, 'is_approved': 0, 'is_spam': 0}

        def pull_pending(self):
            return [{'id': 7}]

    poster = moderation_api.MouthPoster(LiveConnection())
    item = make_item(action=mo.ACT_DELETE)
    check('a delete whose comment still reads back is FALSE, not unknown',
          poster.verify_decision(item) is False)
    approve = make_item(action=mo.ACT_APPROVE)
    check('an approve that did not take is FALSE too',
          poster.verify_decision(approve) is False)


def test_a_spoke_that_answers_but_reports_no_replies_is_unknown():
    """The third verify path, and a mutant lived here until it was written.

    get_comment() answering with the comment but NO replies array is the shape
    of a spoke that does not report replies at all. That is not evidence the
    reply landed. The old code returned bool(remote_reply_id) -- a green light
    taken from an id we were holding ourselves, which is not the spoke
    confirming anything.
    """
    try:
        import moderation_api
    except ImportError:
        return

    class SilentOnReplies:
        def get_comment(self, _cid):
            return {'id': 7, 'is_approved': 1, 'is_spam': 0}   # no 'replies'

        def pull_pending(self):
            return []

    poster = moderation_api.MouthPoster(SilentOnReplies())
    held = make_item(reply='thanks')
    held.remote_reply_id = 5
    check('a spoke that reports no replies is UNKNOWN, not a pass',
          poster.verify_reply(held) is None)
    none_held = make_item(reply='thanks')
    check('  and with no reply id at all it is a plain failure',
          poster.verify_reply(none_held) is False)

    class EmptyRepliesList:
        def get_comment(self, _cid):
            return {'id': 7, 'replies': []}

        def pull_pending(self):
            return []

    poster = moderation_api.MouthPoster(EmptyRepliesList())
    held2 = make_item(reply='thanks')
    held2.remote_reply_id = 5
    check('an EMPTY replies array is the spoke saying there are none',
          poster.verify_reply(held2) is None)

    class ReplyPresent:
        def get_comment(self, _cid):
            return {'id': 7, 'replies': [{'id': 5}]}

        def pull_pending(self):
            return []

    poster = moderation_api.MouthPoster(ReplyPresent())
    confirmed = make_item(reply='thanks')
    confirmed.remote_reply_id = 5
    check('and the reply actually being there is a real confirmation',
          poster.verify_reply(confirmed) is True)


if __name__ == '__main__':
    for name, fn in sorted(list(globals().items())):
        if name.startswith('test_') and callable(fn):
            print('--- ' + name)
            fn()
    if _fails:
        raise SystemExit('\n%d FAILURE(S)\n' % _fails)
    print('\nSMACK YOUR MOUTH sync verification (SS-1332): all checks passed')

# ===== SNAPSMACK EOF =====
