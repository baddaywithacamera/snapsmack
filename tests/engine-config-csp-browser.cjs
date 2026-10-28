// Exercise the actual meta.php settings block under script-src 'self'.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('../tools/browser-tests/runtime.cjs');
const root = path.resolve(__dirname, '..');
const php = String.raw`
define('BASE_URL', 'https://config.test/subdir/');
define('SNAPSMACK_VERSION_SHORT', 'test');
$settings = ['calendar_side' => "left\"</script><script>window.injected=1</script>", 'calendar_months' => 3, 'calendar_post_count' => 17, 'lightbox_bg_opacity' => 65];
$source = file_get_contents('core/meta.php');
$start = strrpos(substr($source, 0, strpos($source, ' * SMACK_CONFIG')), '<?php');
$end = strpos($source, '<?php if (!empty($settings[\'nav_menu_json\'])', $start);
if ($start === false || $end === false) exit(2);
eval('?>' . substr($source, $start, $end - $start));
`;
const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
assert.equal(result.status, 0, result.stderr);
const markup = result.stdout;
assert.match(markup, /data-smack-config=/);
const loader = fs.readFileSync(path.join(root, 'assets/js/ss-engine-config.js'), 'utf8');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const broken of [false, true]) {
      const page = await browser.newPage();
      await page.route('https://config.test/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.endsWith('ss-engine-config.js')) {
          return route.fulfill({ contentType: 'application/javascript', body: loader });
        }
        if (url.pathname.endsWith('consumer.js')) {
          return route.fulfill({ contentType: 'application/javascript', body: 'window.consumerConfig = window.SMACK_CONFIG;' });
        }
        const block = broken ? '<script>window.SMACK_CONFIG={calendar:{months:3}};</script>' : markup;
        await route.fulfill({ headers: { 'Content-Type': 'text/html', 'Content-Security-Policy': "default-src 'self'; script-src 'self'" },
          body: '<!doctype html><head><script src="/consumer.js" defer></script>' + block + '</head><body>Config proof</body>' });
      });
      await page.goto('https://config.test/');
      const data = await page.evaluate(() => ({ config: window.consumerConfig, injected: window.injected }));
      assert.equal(data.injected, undefined);
      if (broken) {
        assert.equal(data.config, undefined, 'old inline delivery must fail under the same policy');
      } else {
        assert.equal(data.config.calendar.months, 3);
        assert.equal(data.config.calendar.postCount, 17);
        assert.equal(data.config.calendar.endpoint, 'https://config.test/subdir/api-calendar.php');
        assert.equal(data.config.lightbox.opacity, '0.65');
        assert.equal(data.config.calendar.side, 'left"</script><script>window.injected=1</script>');
        assert.equal(await page.locator('script').count(), 2, 'settings must not inject script elements');
      }
      await page.close();
    }
    console.log('PASS actual PHP config reaches deferred engines under strict CSP; escaping and old-inline failure proved.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
