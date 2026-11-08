'use strict';
/**
 * The infinite-scroll engine prefers a core-prepared data-next-url. (SS-1185/NH)
 *
 * A skin whose route carries filters (archive ?category=&page=, hashtag) cannot
 * be paged by appending p=N to a base -- that drops the filter and strands posts
 * past the first batch (Muse's no-paging finding). Core prepares the exact next
 * URL; the skin hands it over as data-next-url on the sentinel. The engine must
 * fetch THAT url verbatim and carry the fetched batch's own data-next-url for the
 * next step, while leaving the historic p=N path untouched for every other skin.
 *
 * Source-structure check (comments stripped -- a comment is not code). The real
 * >24-item rendered paging is measured on an install by Codex (PM ruling); this
 * locks the engine logic so it cannot silently regress.
 */
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-tag-infinite.js'), 'utf8');
const code = src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:])\/\/[^\r\n]*/g, '$1 ');

let pass = 0, fail = 0;
function cx(ok, what) { ok ? (pass++, console.log('  ok    ' + what)) : (fail++, console.log('  FAIL  ' + what)); }

cx(/var\s+nextUrl\s*=\s*sentinel\.dataset\.nextUrl/.test(code),
   'the engine reads the sentinel data-next-url');
cx(/var\s+url\s*=\s*nextUrl\s*\?\s*nextUrl\s*:/.test(code),
   'it PREFERS data-next-url over the p=N construction when present');
cx(/nextUrl\s*=\s*newSentinel\.dataset\.nextUrl/.test(code),
   'it carries the fetched batch\'s own data-next-url for the next step');
cx(/base\s*\+\s*\(base\.indexOf\('\?'\)[\s\S]{0,40}'p=' \+ nextPg/.test(code),
   'the feed p=N fallback is intact for skins without a prepared url');
cx(/'\?tag=' \+ encodeURIComponent\(tag\) \+ '&p=' \+ nextPg/.test(code),
   'the hashtag p=N fallback is intact');
cx(/querySelector\('#' \+ P \+ '-sentinel'\)/.test(code) && /obs\.disconnect\(\)/.test(code),
   'it still stops when the fetched batch carries no sentinel');

console.log('\n' + pass + ' checks, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
