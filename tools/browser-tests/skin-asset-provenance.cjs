#!/usr/bin/env node
'use strict';

// Read-only inventory for SS-1379. It never changes a skin or a package.

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const SKINS = path.join(ROOT, 'skins');
const LOCKED = new Set(['photogram', 'the-grid']);
const ASSET_EXTENSIONS = new Set([
    '.avif', '.bmp', '.eot', '.gif', '.ico', '.jpeg', '.jpg', '.otf',
    '.png', '.svg', '.ttf', '.webp', '.woff', '.woff2',
]);
const TEXT_EXTENSIONS = new Set([
    '.css', '.html', '.js', '.json', '.md', '.php', '.scss', '.txt', '.xml',
]);
const LICENCE_NAMES = /^(?:copying|licen[cs]e|notice|ofl)(?:[._-].*)?$/i;

// Byte identities recovered from repository history after the owner identified
// these files as copyrighted/site-personal material that must never ship.
const FORBIDDEN_HASHES = new Map([
    ['ea5fdb4c92ba006b3267a0f4b327d4602bde522b4434854fcdcc0c0550495db3', 'Hitchcock mountain background (bg.jpg)'],
    ['2b5e863bb8023a443404cf3474285fc6361113dc747bc429bd9724ea7bc44aea', 'Hitchcock mountain background (renamed sean-storm-panorama.jpg)'],
    ['a85cc64ad9e552ffe5c49d5b3c51283d786dd8b246f2b038accf5689e04da8c0', 'A Bad Day With A Camera site masthead'],
]);

function walk(dir) {
    const result = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const absolute = path.join(dir, entry.name);
        if (entry.isDirectory()) result.push(...walk(absolute));
        else if (entry.isFile()) result.push(absolute);
    }
    return result;
}

function rel(file, base) {
    return path.relative(base, file).replaceAll('\\', '/');
}

function sha256(file) {
    return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function provenanceText(manifest, files) {
    const bits = [];
    if (manifest.assets) bits.push(JSON.stringify(manifest.assets));
    for (const file of files) {
        const name = path.basename(file);
        if (/^assets\.md$/i.test(name) || LICENCE_NAMES.test(name)) {
            bits.push(fs.readFileSync(file, 'utf8'));
        }
    }
    return bits.join('\n').toLowerCase();
}

function hasLicenceNearby(asset, files) {
    if (!/\.(?:eot|otf|ttf|woff2?)$/i.test(asset)) return null;
    const assetDir = path.dirname(asset);
    return files.some(file => {
        if (!LICENCE_NAMES.test(path.basename(file))) return false;
        const licenceDir = path.dirname(file);
        return licenceDir === assetDir || assetDir.startsWith(licenceDir + path.sep);
    });
}

function inventorySkin(skinDir) {
    const slug = path.basename(skinDir);
    const files = walk(skinDir);
    const manifestFile = path.join(skinDir, 'manifest.json');
    const manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
    const textFiles = files.filter(file => TEXT_EXTENSIONS.has(path.extname(file).toLowerCase()));
    const text = textFiles.map(file => ({
        file,
        body: fs.readFileSync(file, 'utf8').toLowerCase(),
    }));
    const provenance = provenanceText(manifest, files);

    const assets = files
        .filter(file => ASSET_EXTENSIONS.has(path.extname(file).toLowerCase()))
        .map(file => {
            const relative = rel(file, skinDir);
            const basename = path.basename(file).toLowerCase();
            const relativeLower = relative.toLowerCase();
            const references = text
                .filter(item => item.file !== file)
                .filter(item => item.body.includes(relativeLower) || item.body.includes(basename))
                .map(item => rel(item.file, skinDir));
            const digest = sha256(file);
            const forbidden = FORBIDDEN_HASHES.get(digest) || null;
            const recorded = provenance.includes(relativeLower) || provenance.includes(basename);
            const licence = hasLicenceNearby(file, files);
            let verdict = 'PASS';
            const findings = [];
            if (forbidden) {
                verdict = 'FAIL';
                findings.push(`forbidden bytes: ${forbidden}`);
            }
            if (!recorded) {
                verdict = 'FAIL';
                findings.push('provenance not recorded');
            }
            if (!references.length) {
                verdict = 'FAIL';
                findings.push('not referenced');
            }
            if (licence === false) {
                verdict = 'FAIL';
                findings.push('font licence not found nearby');
            }
            return {
                asset: relative,
                sha256: digest,
                referenced: references.length > 0,
                references,
                provenance_recorded: recorded,
                font_licence_present: licence,
                forbidden,
                verdict,
                findings,
            };
        });

    return {
        skin: slug,
        version: String(manifest.version || ''),
        locked_report_only: LOCKED.has(slug),
        assets,
        verdict: assets.every(asset => asset.verdict === 'PASS') ? 'PASS' : 'FAIL',
    };
}

function markdown(report) {
    const out = [];
    out.push('# SS-1379 skin asset inventory', '');
    out.push(`Scanned ${report.skins.length} skin manifests and ${report.summary.assets} binary assets.`, '');
    out.push('| Skin | Locked | Assets | Referenced | Provenance | Font licences | Forbidden | Verdict |');
    out.push('|---|---:|---:|---:|---:|---:|---:|---|');
    for (const skin of report.skins) {
        const referenced = skin.assets.filter(a => a.referenced).length;
        const recorded = skin.assets.filter(a => a.provenance_recorded).length;
        const fonts = skin.assets.filter(a => a.font_licence_present !== null);
        const licences = fonts.filter(a => a.font_licence_present).length;
        const forbidden = skin.assets.filter(a => a.forbidden).length;
        out.push(`| ${skin.skin} | ${skin.locked_report_only ? 'yes' : 'no'} | ${skin.assets.length} | ${referenced} | ${recorded} | ${licences}/${fonts.length} | ${forbidden} | ${skin.verdict} |`);
    }
    out.push('', '## Findings', '');
    for (const skin of report.skins) {
        for (const asset of skin.assets.filter(a => a.verdict !== 'PASS')) {
            out.push(`- ${skin.skin}/${asset.asset}: ${asset.findings.join('; ')}`);
        }
    }
    return out.join('\n') + '\n';
}

const skinDirs = fs.readdirSync(SKINS, { withFileTypes: true })
    .filter(entry => entry.isDirectory())
    .map(entry => path.join(SKINS, entry.name))
    .filter(dir => fs.existsSync(path.join(dir, 'manifest.json')))
    .sort();
const skins = skinDirs.map(inventorySkin);
const allAssets = skins.flatMap(skin => skin.assets);
const report = {
    generated_at: new Date().toISOString(),
    root: ROOT,
    forbidden_hashes: Object.fromEntries(FORBIDDEN_HASHES),
    summary: {
        skins: skins.length,
        assets: allAssets.length,
        referenced: allAssets.filter(asset => asset.referenced).length,
        provenance_recorded: allAssets.filter(asset => asset.provenance_recorded).length,
        forbidden: allAssets.filter(asset => asset.forbidden).length,
        failures: allAssets.filter(asset => asset.verdict !== 'PASS').length,
    },
    skins,
};

if (process.argv.includes('--markdown')) process.stdout.write(markdown(report));
else process.stdout.write(JSON.stringify(report, null, 2) + '\n');

// ===== SNAPSMACK EOF =====
