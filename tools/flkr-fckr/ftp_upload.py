"""
Unzucker — ftp_upload.py
FTP/SFTP file transfer module.

Uploads processed images to the SnapSmack server, creating the
year/month directory structure as needed. Supports plain FTP and
SFTP (auto-detected from config).

Retry strategy: 3 attempts with exponential backoff (2s, 4s, 8s).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import ftplib
import os
import time
from dataclasses import dataclass
from datetime import datetime
from typing import Callable, List, Optional

# SFTP via paramiko is optional — FTP works without it
try:
    import paramiko
    HAS_PARAMIKO = True
except ImportError:
    HAS_PARAMIKO = False


@dataclass
class UploadResult:
    local_path:  str
    remote_path: str
    success:     bool
    message:     str = ''


# ---------------------------------------------------------------------------
# Filename generation
# ---------------------------------------------------------------------------

def generate_filename(timestamp: int, sequence: int) -> str:
    """
    Generate a unique filename from timestamp and sequence number.
    Format: {unix_timestamp}_{sequence:02d}_{random4hex}.jpg
    """
    import secrets
    rand = secrets.token_hex(2)  # 4 hex chars
    return f"{timestamp}_{sequence:02d}_{rand}.jpg"


def remote_dir_for_timestamp(remote_base: str, timestamp: int) -> str:
    """Return remote directory path: {base}/YYYY/MM"""
    dt = datetime.utcfromtimestamp(timestamp)
    return f"{remote_base}/{dt.year}/{dt.month:02d}"


# ---------------------------------------------------------------------------
# FTP transport
# ---------------------------------------------------------------------------

class FTPTransport:
    """Plain FTP upload using ftplib."""

    def __init__(self, host: str, port: int, username: str, password: str):
        self.host     = host
        self.port     = port
        self.username = username
        self.password = password
        self._ftp:    Optional[ftplib.FTP] = None

    def connect(self) -> None:
        self._ftp = ftplib.FTP()
        self._ftp.connect(self.host, self.port, timeout=30)
        self._ftp.login(self.username, self.password)
        self._ftp.set_pasv(True)

    def disconnect(self) -> None:
        if self._ftp:
            try:
                self._ftp.quit()
            except Exception:
                try:
                    self._ftp.close()
                except Exception:
                    pass
            self._ftp = None

    def ensure_dir(self, remote_dir: str) -> None:
        """Create remote directory tree, one level at a time."""
        parts = remote_dir.replace('\\', '/').strip('/').split('/')
        current = ''
        for part in parts:
            current += f'/{part}'
            try:
                self._ftp.cwd(current)
            except ftplib.error_perm:
                try:
                    self._ftp.mkd(current)
                except ftplib.error_perm:
                    pass  # directory might already exist (race condition)

    def upload(self, local_path: str, remote_path: str) -> None:
        """Upload a single file."""
        remote_dir = os.path.dirname(remote_path).replace('\\', '/')
        self.ensure_dir(remote_dir)
        with open(local_path, 'rb') as f:
            self._ftp.storbinary(f'STOR {remote_path}', f)

    @property
    def connected(self) -> bool:
        if not self._ftp:
            return False
        try:
            self._ftp.voidcmd('NOOP')
            return True
        except Exception:
            return False


# ---------------------------------------------------------------------------
# SFTP transport
# ---------------------------------------------------------------------------

class SFTPTransport:
    """SFTP upload using paramiko."""

    def __init__(self, host: str, port: int, username: str, password: str):
        if not HAS_PARAMIKO:
            raise RuntimeError(
                "SFTP requires the paramiko library.\n"
                "Install it with: pip install paramiko"
            )
        self.host     = host
        self.port     = port
        self.username = username
        self.password = password
        self._ssh:    Optional[paramiko.SSHClient]    = None
        self._sftp:   Optional[paramiko.SFTPClient]   = None

    def connect(self) -> None:
        self._ssh = paramiko.SSHClient()
        self._ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        self._ssh.connect(
            self.host, port=self.port,
            username=self.username, password=self.password,
            timeout=30,
        )
        self._sftp = self._ssh.open_sftp()

    def disconnect(self) -> None:
        if self._sftp:
            try:
                self._sftp.close()
            except Exception:
                pass
            self._sftp = None
        if self._ssh:
            try:
                self._ssh.close()
            except Exception:
                pass
            self._ssh = None

    def ensure_dir(self, remote_dir: str) -> None:
        """Create remote directory tree, one level at a time."""
        parts = remote_dir.replace('\\', '/').strip('/').split('/')
        current = ''
        for part in parts:
            current += f'/{part}'
            try:
                self._sftp.stat(current)
            except IOError:
                try:
                    self._sftp.mkdir(current)
                except IOError:
                    pass

    def upload(self, local_path: str, remote_path: str) -> None:
        """Upload a single file."""
        remote_dir = os.path.dirname(remote_path).replace('\\', '/')
        self.ensure_dir(remote_dir)
        self._sftp.put(local_path, remote_path)

    @property
    def connected(self) -> bool:
        if not self._sftp:
            return False
        try:
            self._sftp.stat('.')
            return True
        except Exception:
            return False


# ---------------------------------------------------------------------------
# Upload manager
# ---------------------------------------------------------------------------

MAX_RETRIES    = 3
BACKOFF_BASE   = 2   # seconds


def create_transport(protocol: str, host: str, port: int,
                     username: str, password: str):
    """Factory: return the right transport for the protocol."""
    if protocol.lower() == 'sftp':
        return SFTPTransport(host, port, username, password)
    return FTPTransport(host, port, username, password)


def upload_images(
    transport,
    local_paths:   List[str],
    remote_paths:  List[str],
    on_progress:   Optional[Callable[[int, int, UploadResult], None]] = None,
) -> List[UploadResult]:
    """
    Upload a list of images via the given transport.
    Retries individual files on failure. Calls on_progress after each file.
    """
    results = []
    total   = len(local_paths)

    # Connect (or reconnect)
    if not transport.connected:
        transport.connect()

    for i, (local, remote) in enumerate(zip(local_paths, remote_paths)):
        result = _upload_one(transport, local, remote)
        results.append(result)
        if on_progress:
            on_progress(i + 1, total, result)

    return results


def _upload_one(transport, local_path: str, remote_path: str) -> UploadResult:
    """Upload a single file with retry logic."""
    for attempt in range(MAX_RETRIES):
        try:
            if not transport.connected:
                transport.connect()
            transport.upload(local_path, remote_path)
            return UploadResult(local_path, remote_path, True, "Uploaded")
        except Exception as e:
            if attempt < MAX_RETRIES - 1:
                wait = BACKOFF_BASE ** (attempt + 1)
                time.sleep(wait)
            else:
                return UploadResult(local_path, remote_path, False, f"Failed after {MAX_RETRIES} attempts: {e}")

    # Should not reach here, but safety net
    return UploadResult(local_path, remote_path, False, "Upload failed")
# ===== SNAPSMACK EOF =====
