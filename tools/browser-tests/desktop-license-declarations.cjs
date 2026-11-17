// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
// Source and dependency declaration gate for the SnapSmack desktop tools.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const splPath = path.join(root, 'licenses/SNAPSMACK-LICENSE.txt');
const excludedDirectories = new Set([
  '.git', '.pytest_cache', '.venv', '__pycache__', 'node_modules', 'target', 'vendor',
]);
const declarationNames = new Set(['package.json', 'Cargo.toml', 'pyproject.toml']);
const forbiddenDeclaration = /\b(?:GNU\s+)?(?:A?GPL)(?:[-\s]?v?\.?3(?:\.0)?|\b)/i;
const forbiddenDependency = /\b(?:AGPL|GPL)(?:-|\s|v|\b)/i;
const forbiddenPythonPackages = /(^|["'\s])(PyQt[56])(?=$|[<>=!~;\s,"'])/im;
const falseFirstPartyClaim = /(?:\bis\s+GPL\b|[·:]\s*GPL(?:[-\s]?v?\.?\d)?\b|license\s*[=:]\s*["']?GPL)/i;

function isOperabilityAuditHtml(relative, name) {
  if (path.extname(name).toLowerCase() !== '.html') return false;
  return relative.split(path.sep).includes('opaudits');
}

function isDeclarationCandidate(absolute, name) {
  const relative = path.relative(root, absolute);
  if (!declarationNames.has(name) && path.extname(name).toLowerCase() !== '.html') return false;
  return !isOperabilityAuditHtml(relative, name);
}

function declarationFailure(absolute, name, source) {
  if (!isDeclarationCandidate(absolute, name)) return null;
  const relative = path.relative(root, absolute);
  return forbiddenDeclaration.test(source) ? relative : null;
}

function walk(directory, visitor) {
  for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
    if (entry.isDirectory() && excludedDirectories.has(entry.name)) continue;
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(absolute, visitor);
    else visitor(absolute, entry.name);
  }
}

const spl = fs.readFileSync(splPath, 'utf8');
assert.match(spl, /^\s*SMACK PUBLIC LICENSE \(SPL\)\s*$/m, 'authoritative SPL title missing');
assert.match(spl, /^\s*Version 2\.4\s*$/m, 'authoritative SPL version is not 2.4');

const historicalAuditText = '<p>The former first-party declaration said GPL-3.0.</p>';
const auditRegressionPath = path.join(root, 'projects/snapsmack-ca/opaudits/032.html');
const publicClaimRegressionPath = path.join(root, 'projects/snapsmack-ca/licensing.html');
assert.equal(declarationFailure(auditRegressionPath, '032.html', historicalAuditText), null,
  'operability audit evidence must be allowed to quote a former declaration');
assert.equal(
  declarationFailure(publicClaimRegressionPath, 'licensing.html', historicalAuditText),
  path.relative(root, publicClaimRegressionPath),
  'the same wording on a public product page must still fail');

const declarationFailures = [];
for (const subtree of ['tools', 'projects']) {
  walk(path.join(root, subtree), (absolute, name) => {
    if (!isDeclarationCandidate(absolute, name)) return;
    const source = fs.readFileSync(absolute, 'utf8');
    const failure = declarationFailure(absolute, name, source);
    if (failure !== null) declarationFailures.push(failure);
  });
}
assert.deepEqual(declarationFailures, [],
  `first-party GPL/AGPL declarations found: ${declarationFailures.join(', ')}`);

const firstPartyClaimPaths = [
  'smack-help.php',
  'tools/_build/build-spec-080.js',
  'projects/snapsmack-ca/hairy-muff.php',
];
const claimFailures = firstPartyClaimPaths.filter((relative) => {
  const absolute = path.join(root, relative);
  return fs.existsSync(absolute) && falseFirstPartyClaim.test(fs.readFileSync(absolute, 'utf8'));
});
assert.deepEqual(claimFailures, [],
  `false first-party GPL claims found: ${claimFailures.join(', ')}`);

const requiredDeclarations = new Map([
  ['tools/oh-snap/package.json', 'LicenseRef-SPL-2.4'],
  ['tools/oh-snap/src-tauri/Cargo.toml', 'license = "LicenseRef-SPL-2.4"'],
  ['tools/gyss/src-tauri/Cargo.toml', 'license = "LicenseRef-SPL-2.4"'],
  ['tools/oh-snap/src/index.html', 'SMACK PUBLIC LICENSE (SPL) 2.4'],
  ['tools/linux/oh-snap/web/index.html', 'SMACK PUBLIC LICENSE (SPL) 2.4'],
]);
for (const [relative, expected] of requiredDeclarations) {
  const absolute = path.join(root, relative);
  assert.ok(fs.readFileSync(absolute, 'utf8').includes(expected),
    `${relative} must declare ${expected}`);
}
const ohSnapPackage = JSON.parse(fs.readFileSync(path.join(root, 'tools/oh-snap/package.json'), 'utf8'));
const ohSnapLock = JSON.parse(fs.readFileSync(path.join(root, 'tools/oh-snap/package-lock.json'), 'utf8'));
assert.equal(ohSnapPackage.license, 'LicenseRef-SPL-2.4');
assert.equal(ohSnapLock.packages[''].license, 'LicenseRef-SPL-2.4');

const dependencyFailures = [];
walk(path.join(root, 'tools'), (absolute, name) => {
  if (name !== 'package-lock.json') return;
  const lock = JSON.parse(fs.readFileSync(absolute, 'utf8'));
  for (const [packagePath, metadata] of Object.entries(lock.packages || {})) {
    if (!packagePath || !metadata.license) continue;
    if (forbiddenDependency.test(metadata.license)) {
      dependencyFailures.push(`${path.relative(root, absolute)}:${packagePath}:${metadata.license}`);
    }
  }
});
for (const subtree of ['tools', 'projects']) {
  walk(path.join(root, subtree), (absolute, name) => {
    if (!/^requirements.*\.txt$/i.test(name) && name !== 'pyproject.toml') return;
    const source = fs.readFileSync(absolute, 'utf8');
    const match = source.match(forbiddenPythonPackages);
    if (match) dependencyFailures.push(`${path.relative(root, absolute)}:${match[2]}`);
  });
}
assert.deepEqual(dependencyFailures, [],
  `GPL/AGPL dependency declarations require review: ${dependencyFailures.join(', ')}`);

console.log('PASS desktop first-party SPL declarations and declared dependency licence gate.');
// ===== SNAPSMACK EOF =====
