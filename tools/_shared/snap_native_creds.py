"""Machine-protected bridge for non-Python SnapSmack desktop clients."""

import ctypes
import os
from ctypes import wintypes

import snap_home

_PREFIX = "SnapSmack.Shared:"
_CRED_TYPE_GENERIC = 1
_CRED_PERSIST_LOCAL_MACHINE = 2


class _FILETIME(ctypes.Structure):
    _fields_ = [("dwLowDateTime", wintypes.DWORD), ("dwHighDateTime", wintypes.DWORD)]


class _CREDENTIALW(ctypes.Structure):
    _fields_ = [
        ("Flags", wintypes.DWORD), ("Type", wintypes.DWORD),
        ("TargetName", wintypes.LPWSTR), ("Comment", wintypes.LPWSTR),
        ("LastWritten", _FILETIME), ("CredentialBlobSize", wintypes.DWORD),
        ("CredentialBlob", ctypes.POINTER(ctypes.c_ubyte)),
        ("Persist", wintypes.DWORD), ("AttributeCount", wintypes.DWORD),
        ("Attributes", ctypes.c_void_p), ("TargetAlias", wintypes.LPWSTR),
        ("UserName", wintypes.LPWSTR),
    ]


def account(site_url: str, key_type: str) -> str:
    return f"{_PREFIX}{snap_home.site_key(site_url)}:{key_type.lower()}"


def set_site(site_url: str, key_type: str, secret: str) -> bool:
    if os.name != "nt" or not secret:
        return False
    target = account(site_url, key_type)
    raw = secret.encode("utf-8")
    blob = (ctypes.c_ubyte * len(raw)).from_buffer_copy(raw)
    cred = _CREDENTIALW(Type=_CRED_TYPE_GENERIC, TargetName=target,
                        CredentialBlobSize=len(raw), CredentialBlob=blob,
                        Persist=_CRED_PERSIST_LOCAL_MACHINE,
                        UserName="SnapSmack desktop fleet")
    return bool(ctypes.windll.advapi32.CredWriteW(ctypes.byref(cred), 0))


def get_site(site_url: str, key_type: str) -> str:
    if os.name != "nt":
        return ""
    result = ctypes.POINTER(_CREDENTIALW)()
    if not ctypes.windll.advapi32.CredReadW(account(site_url, key_type),
                                            _CRED_TYPE_GENERIC, 0,
                                            ctypes.byref(result)):
        return ""
    try:
        cred = result.contents
        return ctypes.string_at(cred.CredentialBlob, cred.CredentialBlobSize).decode("utf-8")
    except Exception:
        return ""
    finally:
        ctypes.windll.advapi32.CredFree(result)


def delete_site(site_url: str, key_type: str) -> bool:
    if os.name != "nt":
        return False
    return bool(ctypes.windll.advapi32.CredDeleteW(account(site_url, key_type),
                                                   _CRED_TYPE_GENERIC, 0))

# ===== SNAPSMACK EOF =====
