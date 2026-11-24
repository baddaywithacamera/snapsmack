/**
 * SNAPSMACK release fleet safety net: roster and comparison rules.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const EXPECTED_SITE_COUNT = 17;
const EXPECTED_WIDTHS = [1920, 1024];
const EXPECTED_PAGE_TYPES = ['home', 'archive', 'solo'];

function readRoster(rosterPath) {
    const hosts = fs.readFileSync(rosterPath, 'utf8')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '' && !line.startsWith('#'));
    const unique = [...new Set(hosts)];
    if (unique.length !== hosts.length) {
        throw new Error(`fleet roster has duplicate hostnames: ${rosterPath}`);
    }
    if (hosts.length !== EXPECTED_SITE_COUNT) {
        throw new Error(`fleet roster must contain ${EXPECTED_SITE_COUNT} sites; found ${hosts.length}`);
    }
    return hosts;
}

function surfaceKey(surface) {
    return `${surface.host}|${surface.page_type}|${surface.width}`;
}

function isGoodHttp(status) {
    return Number.isInteger(status) && status >= 200 && status < 400;
}

function regressionsFor(before, after) {
    const regressions = [];
    const add = (rule, detail) => regressions.push({ rule, detail });
    if (isGoodHttp(before.http_status) && !isGoodHttp(after.http_status)) {
        add('http-status', `HTTP ${before.http_status} became ${after.http_status ?? 'no response'}`);
    }
    if (!before.navigation_error && after.navigation_error) {
        add('navigation', after.navigation_error);
    }
    if ((after.horizontal_overflow_px || 0) > Math.max(4, (before.horizontal_overflow_px || 0) + 4)) {
        add('horizontal-overflow', `${before.horizontal_overflow_px || 0}px became ${after.horizontal_overflow_px}px`);
    }
    if ((after.broken_images || 0) > (before.broken_images || 0)) {
        add('broken-images', `${before.broken_images || 0} became ${after.broken_images}`);
    }
    if ((after.visible_screen_reader_labels || 0) > (before.visible_screen_reader_labels || 0)) {
        add('visible-accessibility-labels', `${before.visible_screen_reader_labels || 0} became ${after.visible_screen_reader_labels}`);
    }
    if (before.main_has_content && !after.main_has_content) {
        add('empty-main', 'the main content region became empty');
    }
    if (before.page_type === 'archive') {
        if (!before.pagination_visible && after.pagination_visible) {
            add('archive-pagination', 'pagination appeared on an endless-scroll archive');
        }
        if ((before.content_item_count || 0) > 0 && (after.content_item_count || 0) === 0) {
            add('archive-empty', `archive had ${before.content_item_count} visible items and now has none`);
        }
    }
    if (before.page_type === 'solo' && before.primary_image_visible && !after.primary_image_visible) {
        add('solo-image-missing', 'the primary photograph disappeared');
    }
    if ((after.page_errors || 0) > (before.page_errors || 0)) {
        add('page-errors', `${before.page_errors || 0} became ${after.page_errors}`);
    }
    return regressions;
}

function compareRuns(beforeManifest, afterManifest) {
    const beforeByKey = new Map((beforeManifest.surfaces || []).map((surface) => [surfaceKey(surface), surface]));
    const afterByKey = new Map((afterManifest.surfaces || []).map((surface) => [surfaceKey(surface), surface]));
    const rows = [];
    const regressions = [];

    for (const [key, before] of beforeByKey) {
        const after = afterByKey.get(key);
        if (!after) {
            const failure = { key, rule: 'missing-after-surface', detail: 'the AFTER sweep did not check this surface' };
            regressions.push(failure);
            rows.push({ key, status: 'FAIL', screenshot_changed: null, regressions: [failure] });
            continue;
        }
        const failures = regressionsFor(before, after).map((failure) => ({ key, ...failure }));
        regressions.push(...failures);
        const unavailable = Boolean(before.not_checked_reason && after.not_checked_reason);
        rows.push({
            key,
            status: failures.length ? 'FAIL' : (unavailable ? 'NOT CHECKED' : 'PASS'),
            screenshot_changed: before.screenshot_sha256 !== after.screenshot_sha256,
            before_screenshot_sha256: before.screenshot_sha256 || null,
            after_screenshot_sha256: after.screenshot_sha256 || null,
            regressions: failures,
        });
    }
    for (const key of afterByKey.keys()) {
        if (!beforeByKey.has(key)) {
            const failure = { key, rule: 'missing-before-surface', detail: 'the BEFORE sweep did not check this surface' };
            regressions.push(failure);
            rows.push({ key, status: 'FAIL', screenshot_changed: null, regressions: [failure] });
        }
    }

    const changedScreenshots = rows.filter((row) => row.screenshot_changed === true).length;
    return {
        schema: 1,
        before_release: beforeManifest.release,
        after_release: afterManifest.release,
        expected_surface_count: EXPECTED_SITE_COUNT * EXPECTED_PAGE_TYPES.length * EXPECTED_WIDTHS.length,
        compared_surface_count: rows.length,
        changed_screenshot_count: changedScreenshots,
        status: regressions.length ? 'FAIL' : 'PASS',
        regressions,
        surfaces: rows,
    };
}

function parseArgs(argv) {
    const options = { _: [] };
    for (let index = 0; index < argv.length; index += 1) {
        const arg = argv[index];
        if (!arg.startsWith('--')) {
            options._.push(arg);
            continue;
        }
        const [name, inline] = arg.slice(2).split('=', 2);
        if (inline !== undefined) options[name] = inline;
        else if (argv[index + 1] && !argv[index + 1].startsWith('--')) options[name] = argv[++index];
        else options[name] = true;
    }
    return options;
}

function selfTest() {
    const base = {
        host: 'example.test', page_type: 'archive', width: 1920, http_status: 200,
        horizontal_overflow_px: 0, broken_images: 0, visible_screen_reader_labels: 0,
        main_has_content: true, pagination_visible: false, content_item_count: 12,
        page_errors: 0, screenshot_sha256: 'before',
    };
    const before = { release: 'abc', surfaces: [base] };
    const pass = compareRuns(before, { release: 'abc', surfaces: [{ ...base, screenshot_sha256: 'after' }] });
    if (pass.status !== 'PASS' || pass.changed_screenshot_count !== 1) throw new Error('changed screenshots must be recorded without being guessed worse');
    const fail = compareRuns(before, { release: 'abc', surfaces: [{ ...base, http_status: 500, content_item_count: 0 }] });
    const rules = fail.regressions.map((item) => item.rule);
    if (fail.status !== 'FAIL' || !rules.includes('http-status') || !rules.includes('archive-empty')) {
        throw new Error('known regressions were not refused');
    }
    process.stdout.write('PASS release fleet safety-net comparison self-test\n');
}

if (require.main === module) {
    if (process.argv.includes('--self-test')) selfTest();
    else {
        process.stderr.write('This module is used by release-fleet-safety-net-runner.cjs.\n');
        process.exitCode = 1;
    }
}

module.exports = {
    EXPECTED_PAGE_TYPES,
    EXPECTED_SITE_COUNT,
    EXPECTED_WIDTHS,
    compareRuns,
    parseArgs,
    readRoster,
    regressionsFor,
    surfaceKey,
};

// ===== SNAPSMACK EOF =====
