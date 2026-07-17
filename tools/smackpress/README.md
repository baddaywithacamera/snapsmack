<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->
# SmackPress

One-post-at-a-time WordPress to SMACKTALK migration workbench.

## Quick start

```
cd tools/smackpress
pip install -r requirements.txt
python app.py
```

On first launch, Settings will open automatically.  Fill in:

1. **WordPress site URL** - your WP site root, e.g. https://myblog.com
2. **WordPress username** - your WP admin username
3. **Application Password** - generate in WP Admin > Users > Profile > Application Passwords
4. **SnapSmack site URL** - the site you are migrating INTO
5. **SmackPress API key** - generate in SnapSmack Admin > API Keys (choose SmackPress type)

Then click **Test connections** to verify both ends before loading posts.

## WordPress companion plugin

Install `tools/smackpress-wp-companion/smackpress-wp-companion.php` on the
source WordPress site.  It exposes the /wp-json/smackpress/v1/ REST API
that SmackPress reads from.  Delete it once migration is complete.

## Workflow

1. Pick a post from the navigator (left pane).
2. Review the WordPress source (top of canvas).
3. Edit the SMACKTALK draft (bottom of canvas) - or hit AI rewrite.
4. Add tags and select a category in the right pane.
5. Click -> SnapSmack to push as a draft.
6. Review on site, publish when happy.
7. Click Hide WP post to set the original to private.

## AI rewrite

Supports Gemini, OpenAI, and Anthropic.  Set provider + API key in Settings.
No AI SDK is required - SmackPress calls the APIs directly over HTTPS.

## Data

Migration state is stored in ~/.smackpress/smackpress.db (SQLite).

<!-- ===== SNAPSMACK EOF ===== -->
