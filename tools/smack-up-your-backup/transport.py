"""
Smack Up Your Backup — transport.py
Factory that returns the right remote-transport client (FTP or SFTP) for a
profile. Both clients share an identical public interface, so callers treat the
return value the same way regardless of protocol.

Profile keys:
  transport            'ftp' (default) | 'sftp'
  ftp_host / ftp_user / ftp_pass / ftp_remote_dir / ftp_port   (shared)
  ftp_ssl / ftp_verify_cert                                    (FTP only)
  sftp_key_file / sftp_key_passphrase / sftp_known_hosts        (SFTP only)
  sftp_verify_host_key  bool — when True, require a known host key (no TOFU)
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


def is_sftp(profile) -> bool:
    return str((profile or {}).get("transport", "ftp")).lower() == "sftp"


def make_client(profile, **overrides):
    """Build an FTP or SFTP client from a profile dict.

    `overrides` (e.g. transfer_delay=0, batch_size=N) are passed through to the
    client constructor — both clients accept the same kwargs.
    """
    profile = profile or {}
    common = dict(
        host        = profile.get("ftp_host", ""),
        user        = profile.get("ftp_user", ""),
        password    = profile.get("ftp_pass", ""),
        remote_dir  = profile.get("ftp_remote_dir", "/"),
        use_tls     = bool(profile.get("ftp_ssl", True)),
        verify_cert = bool(profile.get("ftp_verify_cert", False)),
    )
    common.update(overrides)

    if is_sftp(profile):
        # Lazy import so FTP-only installs don't require paramiko.
        from sftp_client import SFTPClient
        port = int(profile.get("ftp_port") or 22)
        if port == 21:           # an FTP default carried over to an SFTP profile
            port = 22
        return SFTPClient(
            port              = port,
            key_file          = profile.get("sftp_key_file", ""),
            key_passphrase    = profile.get("sftp_key_passphrase", ""),
            known_hosts       = profile.get("sftp_known_hosts", ""),
            auto_add_host_key = not bool(profile.get("sftp_verify_host_key", False)),
            **common,
        )

    import ftp_client
    port = int(profile.get("ftp_port") or 21)
    return ftp_client.FTPClient(port=port, **common)
# ===== SNAPSMACK EOF =====
