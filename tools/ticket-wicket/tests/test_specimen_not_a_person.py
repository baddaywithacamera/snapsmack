"""SS-1632: the publish guard must tell a documentation specimen from a person.

THE FAULT. A ticket documenting what a Pixelfed profile link looks like has to
show one. The guard read the example address as "a URL" and refused to publish
the ticket, and its advice on refusal is to rewrite the body about the problem
-- which there meant deleting the example that IS the problem. Eight tickets
were stuck behind this.

THE SHAPE OF THE FIX, and the half that matters is the half that still refuses.
A shared instance's HOSTNAME names nobody; the PATH on it names somebody. So a
URL on a shared instance passes only when its final segment is a known
placeholder. Everything below is driven through the real checker, including
every case it must still refuse -- a guard proved only by what it lets through
is not proved at all.
"""
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from ticketwicket import anonymise  # noqa: E402


def url_refused(url):
    """True when the real checker reports this URL as identifying someone."""
    return any(p.startswith("a URL") for p in anonymise.check(url))


def domain_refused(token):
    return any(p.startswith("a domain") for p in anonymise.check(token))


# ── WHAT MUST NOW PASS ───────────────────────────────────────────────────────

def test_house_placeholder_on_a_shared_instance_is_a_specimen():
    assert not url_refused("https://pixelfed.social/cordwainerbird")


def test_the_handle_form_is_a_specimen_too():
    # Mastodon and Pixelfed profile links are written with a leading @.
    assert not url_refused("https://mastodon.social/@cordwainerbird")


def test_every_spelling_of_the_house_name_is_recognised():
    for spelling in ("cordwainerbird", "cordwainer-bird",
                     "cordwainer.bird", "cordwainer_bird"):
        assert not url_refused(f"https://pixelfed.social/{spelling}"), spelling


def test_the_older_placeholder_vocabulary_still_works():
    # Bodies written before the owner named the house placeholder.
    for word in ("username", "example", "yourname", "placeholder"):
        assert not url_refused(f"https://pixelfed.social/{word}"), word


def test_reserved_documentation_domains_are_never_anybody():
    # RFC 2606 and RFC 6761 reserve these so documentation has somewhere safe.
    assert not url_refused("https://anything.example/foo")
    assert not url_refused("https://site.invalid/bar")
    assert not domain_refused("anything.example")
    assert not domain_refused("site.invalid")


# ── WHAT MUST STILL BE REFUSED. THIS IS THE LOAD-BEARING HALF. ───────────────

def test_a_real_looking_segment_on_a_shared_instance_is_still_refused():
    assert url_refused("https://pixelfed.social/realpersonhandle")
    assert url_refused("https://mastodon.social/@someonereal")


def test_a_placeholder_on_somebodys_own_host_is_still_refused():
    """The host names them even when the path does not.

    This is the case the easy wrong fix would have let through: adding
    pixelfed.social to the public-host list would have passed a real account,
    and excepting any placeholder anywhere would pass somebody's own domain.
    """
    assert url_refused("https://baddaywithacamera.ca/cordwainerbird")
    assert url_refused("https://foundtextures.ca/username")


def test_a_bare_shared_hostname_is_not_newly_excepted():
    """Being a shared service must not become a reason to publish a bare host.

    I FIRST WROTE THIS ASSERTING THAT pixelfed.social IS REFUSED AS A DOMAIN,
    and it failed. The cause is not this change: the domain pattern does not
    match a .social hostname at all, so a bare one was never caught, before
    this change or after. That is a PRE-EXISTING GAP in the domain pattern's
    reach and it is reported separately rather than hidden behind an assertion
    that would have implied I had covered it.

    What this test can honestly hold is that the specimen exception did not
    widen to bare hosts: a shared hostname is not excepted by name anywhere,
    so if the domain pattern is ever taught .social it will be caught then.
    """
    assert "pixelfed.social" not in anonymise._PUBLIC_HOSTS
    assert "mastodon.social" not in anonymise._PUBLIC_HOSTS
    assert not anonymise._is_reserved_example_host("pixelfed.social")
    # And a host in the shared list is still identifying when asked directly.
    assert anonymise._is_identifying_domain("pixelfed.social")


def test_the_fleets_own_domains_are_still_refused():
    for host in ("foundtextures.ca", "acolourlesslife.ca",
                 "curbappeal.photoblogs.fyi", "portalproject.photoblogs.fyi"):
        assert domain_refused(host), host


def test_the_other_detectors_are_untouched():
    """The change must not have made the guard quieter anywhere else."""
    assert any(p.startswith("an email address")
               for p in anonymise.check("write to someone@somewhere.ca"))
    assert any(p.startswith("an IP address")
               for p in anonymise.check("the host was 203.0.113.7"))
    assert any(p.startswith("a filesystem path")
               for p in anonymise.check(r"C:\\Users\\someone\\photos"))
