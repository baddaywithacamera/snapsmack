#!/usr/bin/env node
'use strict';

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..', '..');
const SKIN_CSS = fs.readFileSync(path.join(ROOT, 'skins', 'new-horizon', 'style.css'), 'utf8');
const WIDTHS = [1920, 1440, 1024];

function render(fonts) {
  const fixture = path.join(os.tmpdir(), `snapsmack-ss1214-${process.pid}-${Date.now()}.php`);
  const php = `<?php
declare(strict_types=1);
require_once ${JSON.stringify(path.join(ROOT, 'core', 'skin-presentation.php').replace(/\\/g, '/'))};
require_once ${JSON.stringify(path.join(ROOT, 'core', 'trusted-html.php').replace(/\\/g, '/'))};
require_once ${JSON.stringify(path.join(ROOT, 'core', 'skin-render-helpers.php').replace(/\\/g, '/'))};
require_once ${JSON.stringify(path.join(ROOT, 'core', 'skin-view-contract.php').replace(/\\/g, '/'))};
define('SNAPSMACK_SKIN_RENDER', true);
$presentation = snapsmack_skin_presentation([
    'header_font_family' => getenv('SS_HEADER_FONT'),
    'tagline_font_family' => getenv('SS_TAGLINE_FONT'),
    'footer_font_family' => getenv('SS_FOOTER_FONT'),
], 'new-horizon');
$view = snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'landing', 'items' => [], 'navigation' => []],
    [
        'site_name' => 'Curb Appeal',
        'tagline' => 'curbing your enthusiasm one photo at a time',
        'language' => 'en',
        'direction' => 'ltr',
        'base_style_url' => '',
        'skin_style_url' => '',
        'skin_variant_url' => '',
        'skin_presentation' => $presentation,
        'footer' => ['copyright' => 'Curb Appeal'],
    ]
);
ob_start();
$rendered = snapsmack_render_strict_skin_template(${JSON.stringify(path.join(ROOT, 'skins', 'new-horizon').replace(/\\/g, '/'))}, 'layout.php', $view);
$html = (string)ob_get_clean();
if (!$rendered) { fwrite(STDERR, "strict render failed\\n"); exit(1); }
echo $html;
// ===== SNAPSMACK EOF =====
`;

  fs.writeFileSync(fixture, php, 'utf8');
  try {
    const result = spawnSync('php', [fixture], {
      cwd: ROOT,
      encoding: 'utf8',
      env: {
        ...process.env,
        SS_HEADER_FONT: fonts.header,
        SS_TAGLINE_FONT: fonts.tagline,
        SS_FOOTER_FONT: fonts.footer,
      },
    });
    if (result.status !== 0) {
      throw new Error(result.stderr || `PHP fixture exited ${result.status}`);
    }
    return result.stdout.replace('</head>', `<style id="skin-under-test">${SKIN_CSS}</style></head>`);
  } finally {
    fs.rmSync(fixture, { force: true });
  }
}

function firstFamily(value) {
  return value.split(',')[0].replace(/["']/g, '').trim();
}

async function measure(page, html) {
  await page.setContent(html, { waitUntil: 'domcontentloaded' });
  return page.evaluate(() => {
    const title = document.querySelector('.site-title-text');
    const tagline = document.querySelector('.site-tagline-text');
    const footer = document.querySelector('#system-footer .inside');
    if (!title || !tagline || !footer) throw new Error('NEW HORIZON font-control targets are absent');
    const titleBox = title.getBoundingClientRect();
    return {
      titleFamily: getComputedStyle(title).fontFamily,
      taglineFamily: getComputedStyle(tagline).fontFamily,
      footerFamily: getComputedStyle(footer).fontFamily,
      titleWidth: titleBox.width,
      titleHeight: titleBox.height,
      overflow: document.documentElement.scrollWidth - window.innerWidth,
    };
  });
}

(async () => {
  const primaryFonts = { header: 'DM Mono', tagline: 'Playfair Display', footer: 'Special Elite' };
  const alternateFonts = { ...primaryFonts, header: 'Playfair Display' };
  const primaryHtml = render(primaryFonts);
  const alternateHtml = render(alternateFonts);
  const browser = await chromium.launch({ headless: true });
  const failures = [];
  try {
    for (const width of WIDTHS) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const primary = await measure(page, primaryHtml);
      const alternate = await measure(page, alternateHtml);
      await page.close();

      const checks = [
        [firstFamily(primary.titleFamily) === primaryFonts.header, `masthead is ${primary.titleFamily}`],
        [firstFamily(primary.taglineFamily) === primaryFonts.tagline, `tagline is ${primary.taglineFamily}`],
        [firstFamily(primary.footerFamily) === primaryFonts.footer, `footer is ${primary.footerFamily}`],
        [firstFamily(alternate.titleFamily) === alternateFonts.header, `alternate masthead is ${alternate.titleFamily}`],
        [Math.abs(primary.titleWidth - alternate.titleWidth) >= 1, `masthead geometry changes (${primary.titleWidth} vs ${alternate.titleWidth})`],
        [primary.titleHeight > 0 && alternate.titleHeight > 0, 'masthead is visibly rendered'],
        [primary.overflow <= 0 && alternate.overflow <= 0, `no horizontal overflow (${primary.overflow}, ${alternate.overflow})`],
      ];
      const failed = checks.filter(([ok]) => !ok).map(([, label]) => label);
      if (failed.length) failures.push(`${width}: ${failed.join('; ')}`);
      console.log(`${width}: ${failed.length ? 'FAIL' : 'PASS'} header=${primary.titleFamily} -> ${alternate.titleFamily}; tagline=${primary.taglineFamily}; footer=${primary.footerFamily}; widths=${primary.titleWidth.toFixed(2)} -> ${alternate.titleWidth.toFixed(2)}`);
    }
  } finally {
    await browser.close();
  }
  if (failures.length) {
    console.error(`NEW HORIZON rendered typography verification failed:\n${failures.join('\n')}`);
    process.exit(1);
  }
  console.log('NEW HORIZON rendered typography verification: 3/3 supported widths PASS');
})().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});

// ===== SNAPSMACK EOF =====
