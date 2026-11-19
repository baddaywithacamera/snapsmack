#!/usr/bin/env node
'use strict';

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Compare two complete sets of generated skin fixtures in a real browser.
 *
 * The PHP render-snapshot gate records markup and asset references. It does
 * not inspect the pixels a stylesheet paints. SS-1436 proved that by adding a
 * visible body colour rule: both existing parity gates passed it. This check
 * closes the browser half of that gap. The release-gate owner wires it into
 * the release workflow; this file stays in Codex's browser-test lane.
 *
 * Generate the two fixture directories from their respective source trees:
 *
 *   php tools/_build/render-geometry-fixtures.php C:\tmp\baseline
 *   php tools/_build/render-geometry-fixtures.php C:\tmp\candidate
 *   node tools/browser-tests/skin-render-pixel-parity.cjs \
 *        C:\tmp\baseline C:\tmp\candidate \
 *        --output C:\tmp\pixel-parity.json \
 *        --evidence-dir C:\tmp\pixel-parity-evidence
 *
 * Any changed, missing, or unrenderable page exits non-zero. A difference is
 * not automatically a defect: it is evidence the release signer must review.
 */

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const ROOT = path.resolve(__dirname, '..', '..');
const { chromium } = require(path.join(__dirname, 'runtime.cjs'));

const DESKTOP_WIDTHS = [
  { name: 'reference', width: 1920, height: 1080 },
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 1024, height: 768 },
];
const PHONE_WIDTHS = [
  { name: 'phone', width: 390, height: 844 },
];

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex').toUpperCase();
}

function isFile(file) {
  try {
    return fs.statSync(file).isFile();
  } catch {
    return false;
  }
}

function parseArgs(argv) {
  const positional = [];
  const options = { output: '', evidenceDir: '', selfTest: false };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--self-test') {
      options.selfTest = true;
    } else if (arg === '--output') {
      options.output = argv[++i] || '';
    } else if (arg === '--evidence-dir') {
      options.evidenceDir = argv[++i] || '';
    } else if (arg.startsWith('--')) {
      throw new Error(`Unknown option: ${arg}`);
    } else {
      positional.push(arg);
    }
  }
  if (!options.selfTest && positional.length !== 2) {
    throw new Error('Usage: skin-render-pixel-parity.cjs BASELINE_DIR CANDIDATE_DIR '
      + '[--output REPORT.json] [--evidence-dir DIR]');
  }
  return {
    ...options,
    baselineDir: positional[0] ? path.resolve(positional[0]) : '',
    candidateDir: positional[1] ? path.resolve(positional[1]) : '',
  };
}

function readIndex(directory) {
  const indexPath = path.join(directory, 'index.json');
  if (!isFile(indexPath)) {
    throw new Error(`Fixture index is missing: ${indexPath}`);
  }
  const parsed = JSON.parse(fs.readFileSync(indexPath, 'utf8'));
  if (!Array.isArray(parsed.pages) || parsed.pages.length === 0) {
    throw new Error(`Fixture index lists no pages: ${indexPath}`);
  }
  const pages = new Map();
  for (const entry of parsed.pages) {
    const skin = String(entry.skin || '');
    const kind = String(entry.kind || '');
    const file = String(entry.file || '');
    if (!skin || !kind || !file) {
      throw new Error(`Malformed fixture entry in ${indexPath}`);
    }
    const key = `${skin}\u0000${kind}`;
    if (pages.has(key)) {
      throw new Error(`Duplicate fixture ${skin}/${kind} in ${indexPath}`);
    }
    pages.set(key, { ...entry, skin, kind, file });
  }
  return pages;
}

function widthsFor(skin) {
  const manifestPath = path.join(ROOT, 'skins', skin, 'manifest.json');
  try {
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    return manifest.features && manifest.features.mobile_only
      ? PHONE_WIDTHS : DESKTOP_WIDTHS;
  } catch {
    // Missing metadata is never an excuse to skip the house widths.
    return DESKTOP_WIDTHS;
  }
}

function safeName(value) {
  return value.replace(/[^a-z0-9_.-]+/gi, '-');
}

async function render(browser, file, viewport) {
  if (!isFile(file)) {
    throw new Error(`Fixture file is missing: ${file}`);
  }
  const page = await browser.newPage({
    viewport: { width: viewport.width, height: viewport.height },
    deviceScaleFactor: 1,
  });
  try {
    // Generated fixtures are self-contained. Network access makes font and
    // tracker availability part of a release result, so refuse it here.
    await page.route(/^https?:\/\//, (route) => route.abort());
    await page.goto(pathToFileURL(file).href, { waitUntil: 'load', timeout: 30000 });
    await page.addStyleTag({ content: [
      '*, *::before, *::after {',
      '  animation: none !important;',
      '  caret-color: transparent !important;',
      '  transition: none !important;',
      '}',
    ].join('\n') });
    await page.evaluate(async () => {
      const bounded = (promise, milliseconds) => Promise.race([
        promise,
        new Promise((resolve) => setTimeout(resolve, milliseconds)),
      ]);
      if (document.fonts && document.fonts.ready) {
        // A blocked remote @font-face can otherwise leave the release gate
        // waiting forever. The fallback face is deterministic on both sides.
        await bounded(document.fonts.ready, 250);
      }
      await bounded(Promise.all([...document.images].map(async (image) => {
        if (!image.complete) {
          await new Promise((resolve) => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
          });
        }
        if (image.complete && image.naturalWidth > 0 && image.decode) {
          try { await image.decode(); } catch { /* screenshot records the failure */ }
        }
      })), 2000);
    });
    const brokenImages = await page.evaluate(() => [...document.images]
      .filter((image) => !image.complete || image.naturalWidth === 0)
      .map((image) => image.getAttribute('src') || '')
      .sort());
    const png = await page.screenshot({
      animations: 'disabled',
      caret: 'hide',
      fullPage: true,
      type: 'png',
    });
    return { png, brokenImages };
  } finally {
    await page.close();
  }
}

async function compareFixtures(browser, baselineDir, candidateDir, evidenceDir = '') {
  const baseline = readIndex(baselineDir);
  const candidate = readIndex(candidateDir);
  const keys = [...new Set([...baseline.keys(), ...candidate.keys()])].sort();
  const results = [];
  if (evidenceDir) fs.mkdirSync(evidenceDir, { recursive: true });

  for (const key of keys) {
    const beforeEntry = baseline.get(key);
    const afterEntry = candidate.get(key);
    const entry = afterEntry || beforeEntry;
    if (!beforeEntry || !afterEntry) {
      results.push({
        skin: entry.skin,
        kind: entry.kind,
        viewport: null,
        status: 'ERROR',
        error: beforeEntry ? 'candidate fixture missing' : 'baseline fixture missing',
      });
      continue;
    }
    for (const viewport of widthsFor(entry.skin)) {
      const row = {
        skin: entry.skin,
        kind: entry.kind,
        viewport: viewport.name,
        width: viewport.width,
        height: viewport.height,
      };
      try {
        const before = await render(browser, path.join(baselineDir, beforeEntry.file), viewport);
        const after = await render(browser, path.join(candidateDir, afterEntry.file), viewport);
        row.baseline_sha256 = sha256(before.png);
        row.candidate_sha256 = sha256(after.png);
        row.baseline_broken_images = before.brokenImages;
        row.candidate_broken_images = after.brokenImages;
        row.warning = before.brokenImages.length > 0
          ? `${before.brokenImages.length} broken fixture image(s) on both sides`
          : null;
        row.status = before.png.equals(after.png)
          && JSON.stringify(before.brokenImages) === JSON.stringify(after.brokenImages)
          ? 'PASS' : 'DIFF';
        if (row.status === 'DIFF' && evidenceDir) {
          const stem = safeName(`${entry.skin}--${entry.kind}--${viewport.name}`);
          fs.writeFileSync(path.join(evidenceDir, `${stem}--baseline.png`), before.png);
          fs.writeFileSync(path.join(evidenceDir, `${stem}--candidate.png`), after.png);
        }
      } catch (error) {
        row.status = 'ERROR';
        row.error = String(error && error.message ? error.message : error);
      }
      results.push(row);
      if (process.env.SNAP_PIXEL_PARITY_PROGRESS === '1' && results.length % 20 === 0) {
        console.log(`Rendered ${results.length} fixture/viewport comparisons...`);
      }
    }
  }

  const report = {
    schema: 'snapsmack.skin-render-pixel-parity.v1',
    baseline_dir: baselineDir,
    candidate_dir: candidateDir,
    checked: results.length,
    unchanged: results.filter((row) => row.status === 'PASS').length,
    changed: results.filter((row) => row.status === 'DIFF').length,
    errors: results.filter((row) => row.status === 'ERROR').length,
    warnings: results.filter((row) => row.warning).length,
    results,
  };
  return report;
}

function writeFixture(directory, css) {
  fs.mkdirSync(directory, { recursive: true });
  fs.writeFileSync(path.join(directory, 'index.json'), JSON.stringify({
    pages: [{ skin: 'alfred', kind: 'page', file: 'alfred--page.html' }],
  }));
  fs.writeFileSync(path.join(directory, 'alfred--page.html'), [
    '<!doctype html><html><head><meta charset="utf-8">',
    `<style>html,body{margin:0;background:#fff;color:#111}${css}</style>`,
    '</head><body><main><h1>Visible CSS must be measured.</h1></main></body></html>',
  ].join(''));
}

async function selfTest(browser) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'snapsmack-ss1436-'));
  const baseline = path.join(root, 'baseline');
  const candidate = path.join(root, 'candidate');
  try {
    writeFixture(baseline, '');
    writeFixture(candidate, '');
    const same = await compareFixtures(browser, baseline, candidate);
    assert.equal(same.changed, 0, 'identical rendered fixtures must pass');
    assert.equal(same.errors, 0, 'identical rendered fixtures must be measurable');

    writeFixture(candidate, 'body{color:#ff00ff!important}');
    const mutated = await compareFixtures(browser, baseline, candidate);
    assert.equal(mutated.changed, DESKTOP_WIDTHS.length,
      'a visible CSS mutation must differ at every supported width');
    assert.equal(mutated.errors, 0, 'the CSS mutation must remain measurable');
    console.log('PASS SS-1436 self-test: identical pixels pass; visible CSS mutation '
      + `detected at ${DESKTOP_WIDTHS.map((item) => item.width).join('/')}.`);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const browser = await chromium.launch({ headless: true });
  try {
    if (args.selfTest) {
      await selfTest(browser);
      return;
    }
    const report = await compareFixtures(
      browser,
      args.baselineDir,
      args.candidateDir,
      args.evidenceDir ? path.resolve(args.evidenceDir) : '',
    );
    const output = args.output
      ? path.resolve(args.output)
      : path.join(args.candidateDir, 'pixel-parity.json');
    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, JSON.stringify(report, null, 2) + '\n');

    for (const row of report.results.filter((item) => item.status !== 'PASS')) {
      console.error(`${row.status} ${row.skin}/${row.kind}/${row.viewport || '-'} `
        + (row.error || `${row.baseline_sha256} -> ${row.candidate_sha256}`));
    }
    console.log(`Pixel parity: ${report.unchanged}/${report.checked} unchanged, `
      + `${report.changed} changed, ${report.errors} errors, `
      + `${report.warnings} existing-fixture warnings. Report: ${output}`);
    if (report.changed > 0 || report.errors > 0) process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exitCode = 1;
});

// ===== SNAPSMACK EOF =====
