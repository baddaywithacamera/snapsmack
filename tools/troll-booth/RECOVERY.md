# TROLL BOOTH recovery

Everything required to rebuild the applications belongs in Git. Secrets and
live user data do not.

## Preserved in Git

- server, worker, owner-console, OAuth, history, and CLI source;
- dependency and command metadata in `pyproject.toml`;
- automated tests;
- sanitized app configuration in `apps.example.json`;
- Linux service and web-proxy templates in `deploy/`;
- the credential-free Windows owner-console installer;
- this recovery procedure.

## Preserved by encrypted backup, never Git

- `/etc/troll-booth/server.env` and its worker credential;
- `~/.troll-booth/owner-token`;
- `~/.troll-booth/apps.json` when it contains local paths or credentials;
- conversation and audit databases;
- the working agreement and its previous copy;
- TLS, SSH, Cloudflare, signing, and TOTP secrets.

## Rebuild the Windows owner console

From a trusted checkout, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\windows\install-owner-console.ps1
```

The installer creates an isolated environment, installs the checked-out code,
adds a per-user startup entry, and starts the loopback-only owner console. It
does not create, read, print, or embed production credentials.

## Rebuild the remote adapter

1. Create a dedicated unprivileged `trollbooth` service account.
2. Install this package into `/opt/snapsmack/troll-booth/venv`.
3. Copy `deploy/server.env.example` to `/etc/troll-booth/server.env`, replace
   placeholders locally, set mode `0600`, and assign it to the service account.
4. Restore the conversation/audit database from the encrypted backup.
5. Install `deploy/troll-booth.service` and the appropriate reverse-proxy
   configuration.
6. Start the service and verify `/health` before reconnecting any browser agent.
7. Rotate the worker credential if its confidentiality cannot be proven.

## Recovery acceptance check

- Run `python -m unittest discover -s tests -v` and require all tests to pass.
- Confirm the owner console listens only on `127.0.0.1`.
- Confirm the public adapter exposes no shell capability.
- Confirm read-only browser sessions cannot post or claim work.
- Confirm database backup restoration before declaring recovery complete.
