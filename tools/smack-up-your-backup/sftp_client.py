"""
Smack Up Your Backup — sftp_client.py
Paced SFTP client (paramiko): connect, upload, download, remote index,
directory creation. Mirrors ftp_client.FTPClient's public interface so the two
are drop-in interchangeable behind transport.get_client(). Sends SSH keepalives
during pacing delays.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import hashlib
import os
import stat as _stat
import time
from typing import Callable, Dict, List, Optional

try:
    import paramiko
except Exception as _e:  # surfaced with a clear message at connect()
    paramiko = None
    _PARAMIKO_IMPORT_ERROR = _e
else:
    _PARAMIKO_IMPORT_ERROR = None

# Type alias for progress callbacks (identical to ftp_client)
ProgressCallback = Callable[[str, int, int, bool], None]
# (filename, bytes_done, bytes_total, success)


class SFTPClient:
    """
    SFTP transport with the same public surface as ftp_client.FTPClient:
    connect/disconnect/reconnect/is_alive, makedirs, ensure_directory_tree,
    build_remote_index, upload_file, download_file, remote_size,
    get_remote_size, and the static sha256_file helper.

    Authentication: password and/or a private key file. Host identity is checked
    against known_hosts unless auto_add_host_key is True (trust-on-first-use).
    All public methods raise RuntimeError on unrecoverable failure.
    """

    def __init__(
        self,
        host:               str,
        user:               str,
        password:           str  = "",
        remote_dir:         str  = "/",
        port:               int  = 22,
        # Accepted for signature parity with FTPClient; ignored by SFTP.
        use_tls:            bool = True,
        verify_cert:        bool = False,
        transfer_delay:     float = 2.0,
        batch_size:         int   = 0,
        keepalive_interval: int   = 60,
        connect_timeout:    int   = 15,
        transfer_timeout:   int   = 120,
        # SFTP-specific
        key_file:           str  = "",
        key_passphrase:     str  = "",
        known_hosts:        str  = "",
        auto_add_host_key:  bool = True,
    ):
        self.host               = host
        self.user               = user
        self.password           = password
        self.remote_dir         = remote_dir.rstrip("/") or "/"
        self.port               = port or 22
        self.use_tls            = use_tls          # unused (parity)
        self.verify_cert        = verify_cert      # unused (parity)
        self.transfer_delay     = transfer_delay
        self.batch_size         = batch_size
        self.keepalive_interval = keepalive_interval
        self.connect_timeout    = connect_timeout
        self.transfer_timeout   = transfer_timeout
        self.key_file           = key_file or ""
        self.key_passphrase     = key_passphrase or ""
        self.known_hosts        = known_hosts or ""
        self.auto_add_host_key  = auto_add_host_key

        self._client = None     # paramiko.SSHClient
        self._sftp   = None     # paramiko.SFTPClient
        self._last_op_time: float = 0.0

    # ------------------------------------------------------------------
    # Connection
    # ------------------------------------------------------------------

    def connect(self) -> None:
        """Open the SSH connection, check the host key, open an SFTP channel."""
        if paramiko is None:
            raise RuntimeError(
                "SFTP support requires the 'paramiko' package, which is not "
                f"available: {_PARAMIKO_IMPORT_ERROR}"
            )
        client = paramiko.SSHClient()
        # Load known hosts: explicit file if provided, else the user's default.
        if self.known_hosts and os.path.exists(self.known_hosts):
            try:
                client.load_host_keys(self.known_hosts)
            except Exception:
                pass
        else:
            try:
                client.load_system_host_keys()
            except Exception:
                pass
        if self.auto_add_host_key:
            # Trust-on-first-use. Mirrors the FTP client's default of encrypting
            # without verifying the peer cert. Turn this off (and populate
            # known_hosts) for strict host-key verification.
            client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        else:
            client.set_missing_host_key_policy(paramiko.RejectPolicy())

        connect_kwargs = dict(
            hostname=self.host,
            port=self.port,
            username=self.user,
            timeout=self.connect_timeout,
            allow_agent=False,
            look_for_keys=False,
        )
        if self.key_file:
            connect_kwargs["key_filename"] = self.key_file
            if self.key_passphrase:
                connect_kwargs["passphrase"] = self.key_passphrase
        if self.password:
            connect_kwargs["password"] = self.password

        try:
            client.connect(**connect_kwargs)
        except Exception as e:
            raise RuntimeError(f"SFTP connection/auth failed: {e}") from e

        sftp = client.open_sftp()
        try:
            chan = sftp.get_channel()
            if chan is not None:
                chan.settimeout(self.transfer_timeout)
        except Exception:
            pass
        # Keep the SSH layer warm during long pacing sleeps.
        try:
            transport = client.get_transport()
            if transport is not None:
                transport.set_keepalive(self.keepalive_interval)
        except Exception:
            pass

        self._client = client
        self._sftp   = sftp
        self._last_op_time = time.monotonic()

    def disconnect(self) -> None:
        if self._sftp:
            try:
                self._sftp.close()
            except Exception:
                pass
            self._sftp = None
        if self._client:
            try:
                self._client.close()
            except Exception:
                pass
            self._client = None

    def reconnect(self) -> None:
        self.disconnect()
        self.connect()

    def is_alive(self) -> bool:
        try:
            transport = self._client.get_transport() if self._client else None
            if transport is None or not transport.is_active():
                return False
            # Cheap round-trip to confirm the SFTP channel really works.
            self._sftp.stat(self.remote_dir)
            return True
        except Exception:
            return False

    def _ensure_alive(self) -> None:
        if not self._client or not self._sftp:
            self.connect()
            return
        if not self.is_alive():
            self.reconnect()

    # ------------------------------------------------------------------
    # Pacing
    # ------------------------------------------------------------------

    def _pace(self) -> None:
        """Sleep transfer_delay, sending an SSH keepalive every keepalive_interval."""
        if self.transfer_delay <= 0:
            return
        elapsed = 0.0
        interval = min(self.keepalive_interval, self.transfer_delay)
        remaining = self.transfer_delay
        while remaining > 0:
            sleep_chunk = min(interval, remaining)
            time.sleep(sleep_chunk)
            remaining -= sleep_chunk
            elapsed   += sleep_chunk
            if elapsed >= self.keepalive_interval:
                elapsed = 0.0
                try:
                    transport = self._client.get_transport() if self._client else None
                    if transport is not None:
                        transport.send_ignore()
                except Exception:
                    pass

    # ------------------------------------------------------------------
    # Directory operations
    # ------------------------------------------------------------------

    def makedirs(self, remote_path: str) -> None:
        """Create directory tree on the server, ignoring existing dirs."""
        self._ensure_alive()
        parts = remote_path.replace("\\", "/").split("/")
        # Preserve a leading slash for absolute paths.
        current = "/" if remote_path.startswith("/") else ""
        for part in parts:
            if not part:
                continue
            current = f"{current.rstrip('/')}/{part}" if current else part
            try:
                self._sftp.stat(current)
            except IOError:
                try:
                    self._sftp.mkdir(current)
                except IOError:
                    # Race or perms — re-stat; only raise if it's truly absent.
                    try:
                        self._sftp.stat(current)
                    except IOError:
                        raise

    def ensure_directory_tree(self, directories: List[str], base: str = "") -> None:
        """Pre-create the directory tree from a manifest's directory list."""
        for rel_dir in sorted(directories):
            remote_path = f"{self.remote_dir}/{rel_dir}".replace("//", "/")
            try:
                self.makedirs(remote_path)
            except Exception:
                pass  # Non-fatal — upload will fail explicitly if dir is missing

    # ------------------------------------------------------------------
    # Remote file index
    # ------------------------------------------------------------------

    def build_remote_index(self, base_path: Optional[str] = None) -> Dict[str, int]:
        """Walk the remote tree → {relative_path: size_bytes}, forward slashes."""
        self._ensure_alive()
        root = base_path or self.remote_dir
        index: Dict[str, int] = {}
        self._walk_remote(root, root, index)
        return index

    def _walk_remote(self, current: str, root: str, index: Dict[str, int]) -> None:
        try:
            entries = self._sftp.listdir_attr(current)
        except Exception:
            return
        for attr in entries:
            name = attr.filename
            if name in (".", ".."):
                continue
            full_path = f"{current.rstrip('/')}/{name}"
            rel_path  = full_path[len(root):].lstrip("/")
            mode = attr.st_mode or 0
            if _stat.S_ISDIR(mode):
                self._walk_remote(full_path, root, index)
            else:
                index[rel_path] = int(attr.st_size or 0)

    # ------------------------------------------------------------------
    # Upload
    # ------------------------------------------------------------------

    def upload_file(
        self,
        local_path:      str,
        remote_rel_path: str,
        on_progress:     Optional[ProgressCallback] = None,
    ) -> bool:
        """Upload to remote_dir/remote_rel_path. Retries once after 5s."""
        remote_full = f"{self.remote_dir}/{remote_rel_path}".replace("//", "/")
        file_size   = os.path.getsize(local_path)
        base        = os.path.basename(local_path)

        for attempt in range(2):
            try:
                self._ensure_alive()
                parent = "/".join(remote_full.split("/")[:-1])
                if parent:
                    self.makedirs(parent)

                def _cb(done, total):
                    if on_progress:
                        on_progress(base, int(done), int(total or file_size), False)

                self._sftp.put(local_path, remote_full, callback=_cb)

                if on_progress:
                    on_progress(base, file_size, file_size, True)
                self._last_op_time = time.monotonic()
                self._pace()
                return True

            except Exception:
                if attempt == 0:
                    time.sleep(5)
                    try:
                        self.reconnect()
                    except Exception:
                        pass
                else:
                    if on_progress:
                        on_progress(base, 0, file_size, False)
                    return False

        return False

    # ------------------------------------------------------------------
    # Download
    # ------------------------------------------------------------------

    def download_file(
        self,
        remote_rel_path: str,
        local_path:      str,
        on_progress:     Optional[ProgressCallback] = None,
    ) -> tuple:
        """Download remote_dir/remote_rel_path → local_path. Retries once."""
        remote_full = f"{self.remote_dir}/{remote_rel_path}".replace("//", "/")
        base        = os.path.basename(remote_rel_path)

        try:
            self._ensure_alive()
            remote_size = int(self._sftp.stat(remote_full).st_size or 0)
        except Exception:
            remote_size = 0

        last_error = ""
        for attempt in range(2):
            try:
                self._ensure_alive()
                os.makedirs(os.path.dirname(local_path), exist_ok=True)

                def _cb(done, total):
                    if on_progress:
                        on_progress(base, int(done), int(total or remote_size), False)

                self._sftp.get(remote_full, local_path, callback=_cb)

                if on_progress:
                    on_progress(base, remote_size, remote_size, True)
                self._last_op_time = time.monotonic()
                self._pace()
                return True, ""

            except Exception as e:
                last_error = str(e)
                if attempt == 0:
                    time.sleep(5)
                    try:
                        self.reconnect()
                    except Exception:
                        pass
                else:
                    if on_progress:
                        on_progress(base, 0, remote_size, False)
                    return False, last_error

        return False, last_error

    def remote_size(self, remote_rel_path: str) -> int:
        """Return size of a remote file (relative to remote_dir), or -1."""
        remote_full = f"{self.remote_dir}/{remote_rel_path}".replace("//", "/")
        try:
            self._ensure_alive()
            return int(self._sftp.stat(remote_full).st_size or -1)
        except Exception:
            return -1

    def get_remote_size(self, remote_path: str) -> Optional[int]:
        """Return the size of a remote file (absolute path), or None."""
        if not self._sftp:
            return None
        try:
            return int(self._sftp.stat(remote_path).st_size)
        except Exception:
            return None

    # ------------------------------------------------------------------
    # Checksum helpers
    # ------------------------------------------------------------------

    @staticmethod
    def sha256_file(path: str) -> str:
        h = hashlib.sha256()
        with open(path, "rb") as f:
            for chunk in iter(lambda: f.read(65536), b""):
                h.update(chunk)
        return f"sha256:{h.hexdigest()}"
# ===== SNAPSMACK EOF =====
