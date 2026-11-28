"""Windows IPC for selecting an action in the shared authorization form.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

ACTIONS = ("preview", "publish", "signed-release", "restore-updater")


class LauncherSignals:
    """Pass fixed action selections through Windows objects owned by this user."""

    def __init__(self):
        import ntsecuritycon
        import pywintypes
        import win32api
        import win32event
        import win32security

        token = win32security.OpenProcessToken(win32api.GetCurrentProcess(), win32security.TOKEN_QUERY)
        try:
            user, _attributes = win32security.GetTokenInformation(token, win32security.TokenUser)
        finally:
            token.Close()
        permissions = win32security.ACL()
        permissions.AddAccessAllowedAce(win32security.ACL_REVISION, ntsecuritycon.GENERIC_ALL, user)
        security = pywintypes.SECURITY_ATTRIBUTES()
        security.SECURITY_DESCRIPTOR.SetSecurityDescriptorDacl(1, permissions, 0)
        prefix = "Local\\SnapSmackProxVox-" + win32security.ConvertSidToStringSid(user)

        self._win32event = win32event
        self._events = {}
        self._mutex = None
        try:
            for action in ACTIONS:
                self._events[action] = win32event.CreateEvent(security, False, False, f"{prefix}-{action}")
            self._mutex = win32event.CreateMutex(security, False, prefix)
            self.already_open = win32api.GetLastError() == 183
        except BaseException:
            self.close()
            raise

    def select(self, action):
        self._win32event.SetEvent(self._events[action])

    def selections(self):
        for action, event in self._events.items():
            if self._win32event.WaitForSingleObject(event, 0) == self._win32event.WAIT_OBJECT_0:
                yield action

    def close(self):
        for event in self._events.values():
            event.Close()
        self._events.clear()
        if self._mutex is not None:
            self._mutex.Close()
            self._mutex = None


# ===== SNAPSMACK EOF =====
