/** SNAPSMACK_EOF_HEADER — generated output must retain its EOF marker. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const toolRoot = path.resolve(here, '..');
const repoRoot = path.resolve(toolRoot, '..', '..');
const inventory = JSON.parse(fs.readFileSync(path.join(repoRoot, 'assets', 'ASSET-INVENTORY.json'), 'utf8'));
if (String(inventory.schema_version || '').split('.')[0] !== '1') throw new Error('Unsupported ASSET-INVENTORY schema');

const variables = {
  BACKGROUNDS: { label: 'Backgrounds', vars: {
    '--bg-page': { label: 'Page Background', type: 'color', default: '#101010' },
    '--bg-secondary': { label: 'Secondary Background', type: 'color', default: '#191919' },
    '--bg-chrome': { label: 'Chrome', type: 'color', default: '#242424' },
  } },
  TEXT: { label: 'Text', vars: {
    '--text-bright': { label: 'Heading Text', type: 'color', default: '#ffffff' },
    '--text-primary': { label: 'Body Text', type: 'color', default: '#d0d0d0' },
    '--text-dim': { label: 'Dim Text', type: 'color', default: '#8a8a8a' },
    '--text-link': { label: 'Link Text', type: 'color', default: '#eeeeee' },
  } },
  LAYOUT: { label: 'Layout', vars: {
    '--border-primary': { label: 'Border', type: 'color', default: '#343434' },
    '--grid-gap': { label: 'Grid Gap', type: 'range', default: '12', min: '0', max: '48', step: '1', unit: 'px' },
    '--content-width': { label: 'Content Width', type: 'range', default: '900', min: '480', max: '1500', step: '10', unit: 'px' },
  } },
  TYPOGRAPHY: { label: 'Typography', vars: {
    '--content-lh': { label: 'Line Height', type: 'range', default: '1.6', min: '1', max: '2.4', step: '0.1' },
    '--title-size': { label: 'Title Size', type: 'range', default: '32', min: '18', max: '84', step: '1', unit: 'px' },
  } },
};

const shellCss = `:root{--bg-page:#101010;--bg-secondary:#191919;--bg-chrome:#242424;--text-bright:#fff;--text-primary:#d0d0d0;--text-dim:#8a8a8a;--text-link:#eee;--border-primary:#343434;--grid-gap:12px;--content-lh:1.6;--title-size:32px;--content-width:900px}body{margin:0;background:var(--bg-page);color:var(--text-primary);font-family:Arial,sans-serif}a{color:var(--text-link);text-decoration:none}#header{background:var(--bg-chrome);border-bottom:1px solid var(--border-primary);padding:22px 5vw}.inside{max-width:1280px;margin:auto}.logo-area{font-size:28px;font-weight:700;color:var(--text-bright)}nav ul{display:flex;gap:22px;list-style:none;padding:0}.post-image-wrap{max-width:1100px;margin:36px auto}.post-image{width:100%;height:auto}.static-transmission,#infobox,.longform{max-width:var(--content-width);margin:24px auto;line-height:var(--content-lh)}.photo-title-footer,.static-page-title,.longform h1{font-size:var(--title-size);color:var(--text-bright)}#browse-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--grid-gap);max-width:1100px;margin:36px auto}.thumb-link img{width:100%;aspect-ratio:1;object-fit:cover}.gram-strip{display:grid;grid-template-columns:repeat(2,1fr);gap:var(--grid-gap);max-width:1100px;margin:36px auto}.gram-strip img{width:100%;height:100%;object-fit:cover}.fedi-card{max-width:720px;margin:32px auto;padding:24px;background:var(--bg-secondary);border:1px solid var(--border-primary)}#system-footer{padding:36px 5vw;color:var(--text-dim)}@media(max-width:600px){#browse-grid,.gram-strip{grid-template-columns:repeat(2,1fr);margin:16px}.post-image-wrap,.static-transmission,#infobox,.longform,.fedi-card{margin:16px}.site-title-text{font-size:22px}}`;
const hooks = ['#header','.site-title-text','.nav-menu','#scroll-stage','#system-footer','#sig-text'];
const mode = (label, installMode, profile, extraHooks) => ({ label, install_mode: installMode, profile, variables, shell_css: shellCss, required_hooks: [...hooks, ...extraHooks] });
const modes = {
  SMACKONEOUT: mode('SMACKONEOUT — single-photo blog','1.0','photoblog',['#photobox','#infobox','.static-transmission']),
  GRAMOFSMACK: mode('GRAMOFSMACK — carousel/grid blog','2.0','gram',['#browse-grid','.gram-strip']),
  SMACKTALK: mode('SMACKTALK — long-form blog','3.0','longform',['.longform','.static-page-title']),
  FEDISTRUCTURE: mode('FEDISTRUCTURE — hub/spoke 4.0','4.0','fedistructure',['.fedi-card','#browse-grid']),
};
const contract = { contract_schema:'1.0', generated_at:new Date().toISOString(), source_inventory_schema:inventory.schema_version, package_lane:'SHAREABLE', modes, asset_inventory:inventory, project_schema:2, package_rules:{forbidden_extensions:['php','phtml','phar','htaccess','exe','dll','bat','cmd','ps1','sh'],forbidden_names:['.htaccess'],max_file_bytes:5000000,max_package_bytes:25000000} };
const outDir = path.join(toolRoot,'src','data');
fs.mkdirSync(outDir,{recursive:true});
fs.writeFileSync(path.join(outDir,'skin-kit-contract.js'),`/** SNAPSMACK_EOF_HEADER — generated contract. */\nwindow.OH_SNAP_CONTRACT = ${JSON.stringify(contract)};\n// ===== SNAPSMACK EOF =====\n`,'utf8');
console.log(`OH SNAP contract: ${Object.keys(modes).length} modes, ${inventory.javascript?.length || 0} JS assets, ${inventory.fonts?.length || 0} fonts.`);
// ===== SNAPSMACK EOF =====
