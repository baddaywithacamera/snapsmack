"""PROX VOX — the broker runs on one machine or it does not run. (SS-1507 P1)

Sean's ruling: the broker lives on asus-rog-strix-desktop and "refuses to
start on any other host". This is that refusal.

WHY IT IS THE MACHINE GUID AND NOT AN ENVIRONMENT VARIABLE. Reviewing the
Desktop CLI step-up I found current_device_id() honouring a SNAPSMACK_DEVICE_ID
override, and said at the time that "trust bound to one device is not a
control when the device identity is caller-supplied". The same objection
applies here, so this reads the Windows MachineGuid out of the registry and
takes no override from anywhere -- not the environment, not a config file, not
an argument.

WHAT THIS DOES AND DOES NOT BUY. It stops the broker being copied to another
machine and run there with a stolen token. It does NOT stop someone who is
already running as Sean on Sean's desktop: nothing at this layer can, and the
honest answer to that threat is the per-action TOTP in P3, not a better host
check. Saying so here because a guard that is believed to do more than it does
is worse than no guard.
"""

import os
import platform

# Where Windows keeps the installation's own identifier. It is stable across
# reboots and is not writable by a standard user.
_GUID_KEY = r"SOFTWARE\Microsoft\Cryptography"
_GUID_VALUE = "MachineGuid"


class WrongHost(Exception):
    """Raised when the broker is started anywhere but its pinned machine."""


def machine_guid() -> str:
    """This machine's Windows MachineGuid, or '' when it cannot be read.

    Read from the 64-bit view explicitly: a 32-bit Python on 64-bit Windows is
    otherwise redirected to the WOW6432Node copy, which is a different value,
    and the broker would then refuse to start on the very machine it is pinned
    to.
    """
    if os.name != "nt":
        return ""
    try:
        import winreg
    except ImportError:                                   # pragma: no cover
        return ""
    try:
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, _GUID_KEY, 0,
                            winreg.KEY_READ | winreg.KEY_WOW64_64KEY) as key:
            value, _ = winreg.QueryValueEx(key, _GUID_VALUE)
            return str(value).strip().lower()
    except OSError:
        return ""


def require_host(pinned_guid: str, actual_guid: str = None) -> None:
    """Raise WrongHost unless this machine is the pinned one.

    An UNSET pin is refused as loudly as a wrong one. A broker configured with
    no pin would otherwise run anywhere, which is the exact condition the
    ruling exists to prevent, and it would do it silently.
    """
    pinned = (pinned_guid or "").strip().lower()
    if not pinned:
        raise WrongHost(
            "No host is pinned. The broker will not start without one: set the "
            "pinned machine GUID in its config. Refusing is the safe answer, "
            "because an unpinned broker runs anywhere."
        )

    actual = (machine_guid() if actual_guid is None else actual_guid).strip().lower()
    if not actual:
        raise WrongHost(
            "Could not read this machine's GUID (host: %s, %s). The broker "
            "will not start when it cannot tell where it is."
            % (platform.node(), platform.system())
        )

    if actual != pinned:
        # Neither GUID goes in the message. They are not secrets, but they are
        # machine identifiers, and this message reaches logs and the room.
        raise WrongHost(
            "This is not the machine PROX VOX is pinned to. Refusing to start. "
            "If the desktop was rebuilt, the pin has to be changed deliberately, "
            "by Sean, not by the broker noticing and adjusting itself."
        )

# ===== SNAPSMACK EOF =====
