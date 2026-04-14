"""
Smack Up Your Backup — ftp_client.py
Paced FTP client: connect, upload, download, remote index, directory creation.
Supports FTP_TLS. Sends NOOP keepalives during pacing delays.
"""

import ftplib
import hashlib
import os
import time
from typing import Callable, Dict, Generator, List, Optional, Tuple

# Type alias for progress callbacks
ProgressCallback = Callable[[str, int, int, bool], None]
# (filename, bytes_done, bytes_total, success)


class FTPClient:
    """
    Thin wrapper around ftplib.FTP / FTP_TLS with pacing and keepalive.
    All public methods raise RuntimeError on unrecoverable failure.
    """

    def __init__(
        self,
        host:               str,
        user:               str,
        password:           str,
        remote_dir:         str  = "/",
        port:               int  = 21,
        use_tls:            bool = True,
        verify_cert:        bool = False,
        transfer_delay:     float = 2.0,
        batch_size:         int   = 0,
        keepalive_interval: int   = 60,
        connect_timeout:    int   = 15,
        transfer_timeout:   int   = 120,
    ):
        self.host               = host
        self.user               = user
        self.password           = password
        self.remote_dir         = remote_dir.rstrip("/") or "/"
        self.port               = port
        self.use_tls            = use_tls
        self.verify_cert        = verify_cert
        self.transfer_delay     = transfer_delay
        self.batch_size         = batch_size
        self.keepalive_interval = keepalive_interval
        self.connect_timeout    = connect_timeout
        self.transfer_timeout   = transfer_timeout

        self._ftp: Optional[ftplib.FTP] = None
        self._last_op_time: float = 0.0

    # ------------------------------------------------------------------
    # Connection
    # ------------------------------------------------------------------

    def connect(self) -> None:
        """Open connection and authenticate."""
        if self.use_tls:
            import ssl
            if self.verify_cert:
                ctx = ssl.create_default_context()
            else:
                # Shared hosting certs often don't match the domain.
                # Skip verification but keep encryption — same as clicking
                # "Trust this certificate" in FileZilla.
                ctx = ssl.create_default_context()
                ctx.check_hostname = False
                ctx.verify_mode = ssl.CERT_NONE
            ftp = ftplib.FTP_TLS(context=ctx, timeout=self.connect_timeout)
            ftp.connect(self.host, self.port)
            ftp.auth()
            ftp.login(self.user, self.password)
            ftp.prot_p()
        else:
            ftp = ftplib.FTP(timeout=self.connect_timeout)
            ftp.connect(self.host, self.port)
            ftp.login(self.user, self.password)
        ftp.set_pasv(True)
        self._ftp = ftp
        self._last_op_time = time.monotonic()

    def disconnect(self) -> None:
        if self._ftp:
            try:
                self._ftp.quit()
            except Exception:
                pass
            self._ftp = None

    def reconnect(self) -> None:
        self.disconnect()
        self.connect()

    def is_alive(self) -> bool:
        try:
            self._ftp.voidcmd("NOOP")
            return True
        except Exception:
            return False

    def _ensure_alive(self) -> None:
        if not self._ftp:
            self.connect()
            return
        if not self.is_alive():
            self.reconnect()

    # ------------------------------------------------------------------
    # Pacing
    # ------------------------------------------------------------------

    def _pace(self) -> None:
        """Sleep for transfer_delay, sending NOOP every keepalive_interval seconds."""
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
                    self._ftp.voidcmd("NOOP")
                except Exception:
                    pass

    # ------------------------------------------------------------------
    # Directory operations
    # ------------------------------------------------------------------

    def makedirs(self, remote_path: str) -> None:
        """Create directory tree on the server, ignoring existing dirs."""
        self._ensure_alive()
        parts = remote_path.replace("\\", "/").split("/")
        current = ""
        for part in parts:
            if not part:
                continue
            current = f"{current}/{part}" if current else part
            try:
                self._ftp.mkd(current)
            except ftplib.error_perm as e:
                if "550" not in str(e) and "521" not in str(e):
                    raise

    def ensure_directory_tree(self, directories: List[str], base: str = "") -> None:
        """
        Pre-create the full directory tree from a manifest's directory_structure list.
        Each path is relative to site root; base is the FTP remote_dir.
        """
        for rel_dir in sorted(directories):
            remote_path = f"{self.remote_dir}/{rel_dir}".replace("//", "/")
            try:
                self.makedirs(remote_path)
            except Exception:
                pass  # Non-fatal — upload will fail explicitly if dir is missing

    # ------------------------------------------------------------------
    # Remote file index
    # ------------------------------------------------------------------

    def build_remote_index(
        self, base_path: Optional[str] = None
    ) -> Dict[str, int]:
        """
        Walk the remote tree and return {relative_path: size_bytes}.
        Relative paths use forward slashes, relative to remote_dir.
        """
        self._ensure_alive()
        root = base_path or self.remote_dir
        index: Dict[str, int] = {}
        self._walk_remote(root, root, index)
        return index

    def _walk_remote(
        self, current: str, root: str, index: Dict[str, int]
    ) -> None:
        try:
            entries = []
            self._ftp.retrlines(f"LIST {current}", entries.append)
        except Exception:
            return

        for line in entries:
            parts = line.split(None, 8)
            if len(parts) < 9:
                continue
            perms = parts[0]
            size  = int(parts[4]) if parts[4].isdigit() else 0
            name  = parts[8].strip()
            if name in (".", ".."):
                continue
            full_path = f"{current}/{name}"
            rel_path  = full_path[len(root):].lstrip("/")
            if perms.startswith("d"):
                self._walk_remote(full_path, root, index)
            else:
                index[rel_path] = size

    # ------------------------------------------------------------------
    # Upload
    # ------------------------------------------------------------------

    def upload_file(
        self,
        local_path:      str,
        remote_rel_path: str,
        on_progress:     Optional[ProgressCallback] = None,
    ) -> bool:
        """
        Upload a single file to remote_dir/remote_rel_path.
        Returns True on success. Retries once after 5 seconds on failure.
        """
        remote_full = f"{self.remote_dir}/{remote_rel_path}".replace("//", "/")
        file_size   = os.path.getsize(local_path)

        for attempt in range(2):
            try:
                self._ensure_alive()
                # Ensure parent directory exists
                parent = "/".join(remote_full.split("/")[:-1])
                if parent:
                    self.makedirs(parent)

                bytes_sent = 0

                def _track(block: bytes) -> None:
                    nonlocal bytes_sent
                    bytes_sent += len(block)
                    if on_progress:
                        on_progress(
                            os.path.basename(local_path),
                            bytes_sent,
                            file_size,
                            False,
                        )

                with open(local_path, "rb") as f:
                    self._ftp.storbinary(
                        f"STOR {remote_full}", f,
                        blocksize=8192,
                        callback=_track,
                    )

                if on_progress:
                    on_progress(os.path.basename(local_path), file_size, file_size, True)
                self._last_op_time = time.monotonic()
                self._pace()
                return True

            except Exception as e:
                if attempt == 0:
                    time.sleep(5)
                    try:
                        self.reconnect()
                    except Exception:
                        pass
                else:
                    if on_progress:
                        on_progress(os.path.basename(local_path), 0, file_size, False)
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
    ) -> bool:
        """
        Download remote_dir/remote_rel_path to local_path.
        Returns True on success. Retries once on failure.
        """
        remote_full = f"{self.remote_dir}/{remote_rel_path}".replace("//", "/")

        # Get remote file size for progress
        try:
            self._ensure_alive()
            remote_size = int(self._ftp.size(remote_full) or 0)
        except Exception:
            remote_size = 0

        for attempt in range(2):
            try:
                self._ensure_alive()
                os.makedirs(os.path.dirname(local_path), exist_ok=True)
                bytes_recv = 0

                with open(local_path, "wb") as f:
                    def _write(block: bytes) -> None:
                        nonlocal bytes_recv
                        f.write(block)
                        bytes_recv += len(block)
                        if on_progress:
                            on_progress(
                                os.path.basename(remote_rel_path),
                                bytes_recv,
                                remote_size,
                                False,
                            )

                    self._ftp.retrbinary(f"RETR {remote_full}", _write, blocksize=8192)

                if on_progress:
                    on_progress(
                        os.path.basename(remote_rel_path),
                        bytes_recv, remote_size, True,
                    )
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
                        on_progress(os.path.basename(remote_rel_path), 0, remote_size, False)
                    return False

        return False

    def remote_size(self, remote_rel_path: str) -> int:
        """Return size of a remote file via FTP SIZE, or -1 if not found."""
        remote_full = f"{self.remote_dir}/{remote_rel_path}".replace("//", "/")
        try:
            self._ensure_alive()
            return int(self._ftp.size(remote_full) or -1)
        except Exception:
            return -1

    def get_remote_size(self, remote_path: str) -> Optional[int]:
        """Return the size of a remote file in bytes via FTP SIZE command.
        Returns None if the command is not supported or the file doesn't exist."""
        if not self._ftp:
            return None
        try:
            self._keepalive()
            return self._ftp.size(remote_path)
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
