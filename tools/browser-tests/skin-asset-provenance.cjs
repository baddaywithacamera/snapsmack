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
    ['51fd40033983773fffbd22af3ecf8f6f0a7c8695a0092d26e5de8c938c7932a9', 'Dormant copied font-awesome.css'],
    ['eaa1cd9155e72b697dfd1b1da8e6ba573fff468c0f6e9eef0ad693fead3e35cd', 'Dormant copied fonts.css'],
    ['a423dcb39a82cb4243659c608f01dccc6bade8d6bc5b6980fd59d8307638be91', 'Dormant copied Droid Serif 400'],
    ['400af9c5f4b71cba96dd718693ebe018980cfe8a028f57b8add280405ddd2131', 'Dormant copied Droid Serif 400 italic'],
    ['b24fe3be6a11c964fe2c1316f9c4bda5561d5d78f72a493beb5b0573331797e0', 'Dormant copied Droid Serif 700'],
    ['5cf9fdf3b7f9089b6d84ca0a916c56c745031eccb81df4652251e147481900c1', 'Dormant copied Droid Serif 700 italic'],
    ['e1e30bb6e54ee8694d77ce63ff9b71e1174c2ade3791f094c6b9bb3ba292fda6', 'Dormant copied Font Awesome brands WOFF'],
    ['974956f1b7b82cecd8ae88a0b685f0d5dfe5c8534c2784e59abeea719eadbbc4', 'Dormant copied Font Awesome brands WOFF2'],
    ['b7e697ebc01ea8d96bcda256f6fe634afaf5b8f1bf98522c3d7263382ba5bfc7', 'Dormant copied Font Awesome solid WOFF'],
    ['a5587a9dca3673b604a8a0e144d268f3dcb180aac337e2b2e163704bc1fc508a', 'Dormant copied Font Awesome solid WOFF2'],
    ['204187f463441785baa841f388afac2feb7dbe8415889bee026b55874c161479', 'Dormant copied Font Awesome stylesheet'],
    ['d1fc21927293f00261a8795efadbdfa16d14521479402d72328c00482a6ba6b9', 'Dormant copied Montserrat 500'],
    ['e7816b6bd80713ced0fabbf061d7ad97d6d1ff4fbf94a1e2b17fbd61421a3a17', 'Dormant copied Montserrat 600'],
    ['1efb6b34aee777f657af03fc18ab7ec444bcb7025b175edbd489c3c984035f34', 'Dormant copied Montserrat 700'],
    ['73c4a0df57e860afb462aeea0bfca3ea0259f88ec8378ca95c2d66783d9dd6db', 'Dormant copied Montserrat 700 italic'],
    ['e08d3b01f0f250bb8618a14e1bacc5a5ff12ead175f372a8286f1990c1b9921e', 'Dormant copied Montserrat italic'],
    ['59d09721ef5d6a8a6aa8cf8100a1eaa2ef1644bd196fc1a788ad31e16a505734', 'Dormant copied Montserrat regular'],
]);

const THIRD_PARTY_BRAND_ASSETS = new Map([
    ['onyx/img/apple-touch-icon.png', 'Pixelfed branding'],
    ['onyx/img/favicon-16.png', 'Pixelfed branding'],
    ['onyx/img/favicon-32.png', 'Pixelfed branding'],
    ['onyx/img/favicon-pf.png', 'Pixelfed branding'],
    ['onyx/img/favicon.ico', 'Pixelfed branding'],
    ['onyx/img/logo-pf-black.png', 'Pixelfed branding'],
    ['onyx/img/logo-pf-white.png', 'Pixelfed branding'],
    ['onyx/img/logo-pf-whiteondark-trans.png', 'Pixelfed branding'],
    ['onyx/img/logo-trans-mastodon.png', 'Mastodon branding'],
    ['onyx/img/logo-trans-pixelfed.png', 'Pixelfed branding'],
    ['onyx/img/logo-trans-vernissage.png', 'Vernissage branding'],
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

function proposalForAsset(slug, relative) {
    const key = `${slug}/${relative}`.toLowerCase();
    if (/^screenshot(?:-|\.)/i.test(path.basename(relative))) {
        return {
            classification: 'screenshot',
            source: 'SnapSmack skin/package screenshot',
            rights_holder: 'ASK',
            licence: 'ASK',
            status: 'ASK',
            note: 'Packager-facing screenshot; confirm the captured photographs and site branding may be redistributed.',
        };
    }
    if (THIRD_PARTY_BRAND_ASSETS.has(key)) {
        return {
            classification: 'third-party',
            source: THIRD_PARTY_BRAND_ASSETS.get(key),
            rights_holder: 'third-party brand owner',
            licence: 'ASK',
            status: 'ASK',
            note: 'Brand identity is evident from the filename and skin copy; redistribution terms are not recorded.',
        };
    }
    return {
        classification: 'unknown',
        source: 'ASK',
        rights_holder: 'ASK',
        licence: 'ASK',
        status: 'ASK',
        note: 'No repository evidence proves whether this is Sean-owned, third-party, or generated.',
    };
}

function inventorySkin(skinDir) {
    const slug = path.basename(skinDir);
    const files = walk(skinDir);
    const manifestFile = path.join(skinDir, 'manifest.json');
    const manifestPresent = fs.existsSync(manifestFile);
    let manifest = {};
    let manifestError = null;
    if (manifestPresent) {
        try {
            manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
        } catch (error) {
            manifestError = error.message;
        }
    }
    const textFiles = files.filter(file => TEXT_EXTENSIONS.has(path.extname(file).toLowerCase()));
    const text = textFiles.map(file => ({
        file,
        body: fs.readFileSync(file, 'utf8').toLowerCase(),
    }));
    const provenance = provenanceText(manifest, files);
    const forbiddenFiles = files
        .map(file => ({
            file: rel(file, skinDir),
            sha256: sha256(file),
        }))
        .filter(file => FORBIDDEN_HASHES.has(file.sha256))
        .map(file => ({
            ...file,
            reason: FORBIDDEN_HASHES.get(file.sha256),
        }));

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
                proposal: proposalForAsset(slug, relative),
            };
        });

    return {
        skin: slug,
        version: String(manifest.version || ''),
        manifest_present: manifestPresent,
        manifest_error: manifestError,
        locked_report_only: LOCKED.has(slug),
        forbidden_files: forbiddenFiles,
        assets,
        verdict: manifestPresent
            && manifestError === null
            && forbiddenFiles.length === 0
            && assets.every(asset => asset.verdict === 'PASS') ? 'PASS' : 'FAIL',
    };
}

function markdown(report) {
    const out = [];
    out.push('# SS-1379 skin asset inventory', '');
    out.push(`Scanned ${report.skins.length} skin directories (${report.summary.manifests} manifests) and ${report.summary.assets} binary assets.`, '');
    out.push('| Skin | Manifest | Locked | Assets | Referenced | Provenance | Font licences | Forbidden | Verdict |');
    out.push('|---|---:|---:|---:|---:|---:|---:|---:|---|');
    for (const skin of report.skins) {
        const referenced = skin.assets.filter(a => a.referenced).length;
        const recorded = skin.assets.filter(a => a.provenance_recorded).length;
        const fonts = skin.assets.filter(a => a.font_licence_present !== null);
        const licences = fonts.filter(a => a.font_licence_present).length;
        out.push(`| ${skin.skin} | ${skin.manifest_present ? 'yes' : 'NO'} | ${skin.locked_report_only ? 'yes' : 'no'} | ${skin.assets.length} | ${referenced} | ${recorded} | ${licences}/${fonts.length} | ${skin.forbidden_files.length} | ${skin.verdict} |`);
    }
    out.push('', '## Findings', '');
    for (const skin of report.skins) {
        if (!skin.manifest_present) out.push(`- ${skin.skin}: manifest.json is missing`);
        if (skin.manifest_error) out.push(`- ${skin.skin}/manifest.json: invalid JSON (${skin.manifest_error})`);
        for (const file of skin.forbidden_files) {
            out.push(`- ${skin.skin}/${file.file}: forbidden bytes (${file.reason})`);
        }
        for (const asset of skin.assets.filter(a => a.verdict !== 'PASS')) {
            out.push(`- ${skin.skin}/${asset.asset}: ${asset.findings.join('; ')}`);
        }
    }
    out.push('', '## Proposal summary', '');
    for (const [classification, count] of Object.entries(report.proposal.summary)) {
        out.push(`- ${classification}: ${count}`);
    }
    out.push('', 'Every proposed record remains ASK until Sean confirms ownership and redistribution terms. Screenshots are classified by function, not treated as automatically licensed.', '');
    out.push('## ASK list', '');
    for (const item of report.proposal.ask_list) {
        out.push(`- ${item.skin}/${item.asset}: ${item.classification}; ${item.note}`);
    }
    return out.join('\n') + '\n';
}

function proposalReport(skins) {
    const records = skins.map(skin => ({
        skin: skin.skin,
        manifest_present: skin.manifest_present,
        records: skin.assets.map(asset => ({
            asset: asset.asset,
            sha256: asset.sha256,
            ...asset.proposal,
        })),
    }));
    const flat = records.flatMap(skin => skin.records.map(record => ({
        skin: skin.skin,
        ...record,
    })));
    const summary = {};
    for (const record of flat) {
        summary[record.classification] = (summary[record.classification] || 0) + 1;
    }
    return {
        schema: 1,
        status: 'proposal-only',
        rule: 'Unknown ownership or redistribution terms remain ASK; no manifest is changed by this report.',
        summary,
        skins: records,
        ask_list: flat.filter(record => record.status === 'ASK'),
        _eof: '===== SNAPSMACK EOF =====',
    };
}

const skinDirs = fs.readdirSync(SKINS, { withFileTypes: true })
    .filter(entry => entry.isDirectory())
    .map(entry => path.join(SKINS, entry.name))
    .sort();
const skins = skinDirs.map(inventorySkin);
const allAssets = skins.flatMap(skin => skin.assets);
const proposal = proposalReport(skins);
const report = {
    generated_at: new Date().toISOString(),
    root: ROOT,
    forbidden_hashes: Object.fromEntries(FORBIDDEN_HASHES),
    summary: {
        skins: skins.length,
        manifests: skins.filter(skin => skin.manifest_present).length,
        missing_manifests: skins.filter(skin => !skin.manifest_present).length,
        assets: allAssets.length,
        referenced: allAssets.filter(asset => asset.referenced).length,
        provenance_recorded: allAssets.filter(asset => asset.provenance_recorded).length,
        forbidden: skins.reduce((count, skin) => count + skin.forbidden_files.length, 0),
        asset_failures: allAssets.filter(asset => asset.verdict !== 'PASS').length,
        skin_failures: skins.filter(skin => skin.verdict !== 'PASS').length,
    },
    proposal,
    skins,
};

if (process.argv.includes('--proposal')) process.stdout.write(JSON.stringify(proposal, null, 2) + '\n');
else if (process.argv.includes('--markdown')) process.stdout.write(markdown(report));
else process.stdout.write(JSON.stringify(report, null, 2) + '\n');

// ===== SNAPSMACK EOF =====
