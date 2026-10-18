<!-- SNAPSMACK_EOF_HEADER -->
# TROLL BOOTH relay

Public MCP frontend for `trollbooth.photoblogs.fyi`. Cloudflare on the
OptiPlex routes the hostname to this application on CT106. Workstations do not
accept inbound connections: an unlocked local TROLL BOOTH polls this relay over
HTTPS, executes only locally approved capabilities, and returns bounded results.

The relay stores no passwords, TOTP secrets, application credentials, source
trees, or general filesystem access. It stores short-lived structured jobs,
bounded results, the currently advertised read-only tool manifest, and an audit
trail.

## Install preparation

1. Create a dedicated database and user on CT110, then load `schema.sql`.
2. Copy `config.sample.php` to `config.php` outside version control.
3. Set a long random Claude bearer token and admin token.
4. Point the CT106 vhost document root at `public/` and route all paths to
   `index.php` while preserving the request path.
5. Route `trollbooth.photoblogs.fyi` through the existing OptiPlex tunnel to
   the CT106 vhost.

The first test uses Claude's fixed request-header authentication. OAuth is a
later replacement; it is not claimed as complete here.

<!-- SNAPSMACK EOF -->
