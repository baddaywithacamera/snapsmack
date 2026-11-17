<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);
/**
 * CONTROL-SELECTOR CHECKER.
 *
 * Every owner-facing skin control that drives CSS declares a selector and a
 * property. The control is DEAD if that selector matches nothing the skin's
 * markup actually emits. MUSE's 2026-11-06 note named the gap the old control
 * scan missed: a selector EXISTING is not a selector that MATCHES.
 *
 * This resolves each control's selector against the real emitted markup --
 * the skin layout.php plus ONLY the core components that layout calls (the
 * file-backed core/<name>-component.php, or the inline branch of that name in
 * core/skin-render-helpers.php) plus shared footer -- by real DOM/XPath, so a
 * descendant trap like '.logo-area a' (an <a> inside .logo-area, which does
 * not exist) resolves to DEAD. CSS custom-property controls (:root { --x })
 * are judged by var() CONSUMPTION, not markup. A class absent from the DOM but
 * present in the skin's JS is JS-EMIT (placement unverified), not DEAD.
 *
 * Statuses: DEAD / OVERRIDDEN (style.css hardcodes the property on the matched
 * element) / JS-EMIT / WIRED.
 *
 * KNOWN LIMITS (hand-verify DEAD before acting): selector lists are matched as
 * a whole, so a dead limb inside a live list is not isolated; a class assembled
 * at runtime in a PHP ternary (no '<') is invisible to static extraction and
 * can read DEAD when live; markup from paths outside layout.php can do the same.
 *
 * Callable: snapsmack_scan_control_selectors($root) returns rows. Run directly
 * for a printed report.
 */

function scs_strip_php(string $s): string {
    return (string)preg_replace('/<\?(php|=)?.*?\?>/s', ' ', $s);
}
function scs_component_markup(string $name, string $srh): string {
    $at = strpos($srh, "\$name === '".$name."'");
    if ($at === false) return '';
    $rest = substr($srh, $at + 5);
    $end = preg_match('/elseif \(\$name ===/', $rest, $m, PREG_OFFSET_CAPTURE)
         ? $at + 5 + $m[0][1] : min($at + 6000, strlen($srh));
    $slice = substr($srh, $at, $end - $at);
    $out = '';
    if (preg_match_all("/'([^']*<[^']*)'/s", $slice, $mm)) $out .= implode(' ', $mm[1]);
    if (preg_match_all('/"([^"]*<[^"]*)"/s', $slice, $mm)) $out .= ' ' . implode(' ', $mm[1]);
    return $out;
}
function scs_dom(string $html): DOMXPath {
    $cls = '';
    if (preg_match_all('/<body[^>]*class="([^"]*)"/i', $html, $bm)) $cls = implode(' ', $bm[1]);
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<!DOCTYPE html><html><body class="'.htmlspecialchars($cls, ENT_QUOTES).'">'.$html.'</body></html>');
    libxml_clear_errors();
    return new DOMXPath($d);
}
function scs_css_to_xpath(string $sel): ?string {
    $sel = trim($sel); if ($sel === '') return null;
    $parts = preg_split('/\s*(>)\s*|\s+/', $sel, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    $xp = ''; $axis = '//';
    foreach ($parts as $p) {
        if ($p === '>') { $axis = '/'; continue; }
        if (!preg_match('/^([a-zA-Z][\w-]*)?((?:[.#][\w-]+)*)$/', $p, $m)) return null;
        $tag = $m[1] !== '' ? $m[1] : '*'; $preds = '';
        if (!empty($m[2])) {
            preg_match_all('/([.#])([\w-]+)/', $m[2], $mm, PREG_SET_ORDER);
            foreach ($mm as $o) $preds .= $o[1] === '.'
                ? "[contains(concat(' ',normalize-space(@class),' '),' ".$o[2]." ')]"
                : "[@id='".$o[2]."']";
        }
        $xp .= $axis.$tag.$preds; $axis = '//';
    }
    return $xp;
}
function scs_matches(DOMXPath $x, string $list): bool {
    foreach (explode(',', $list) as $alt) {
        $xp = scs_css_to_xpath($alt); if ($xp === null) continue;
        $r = @$x->query($xp); if ($r !== false && $r->length > 0) return true;
    }
    return false;
}
function scs_rightmost_class(string $list): ?string {
    foreach (explode(',', $list) as $alt) {
        $bits = preg_split('/\s*>\s*|\s+/', trim($alt), -1, PREG_SPLIT_NO_EMPTY);
        $last = end($bits);
        if ($last && preg_match('/\.([\w-]+)(?![\w-])/', $last, $m)) return $m[1];
    }
    return null;
}

/** @return array<int,array{skin:string,control:string,selector:string,property:string,status:string}> */
function snapsmack_scan_control_selectors(string $root): array {
    $shared = is_file($root.'/core/footer-scripts.php') ? scs_strip_php((string)file_get_contents($root.'/core/footer-scripts.php')) : '';
    $srh = @file_get_contents($root.'/core/skin-render-helpers.php') ?: '';
    $rows = [];
    foreach (glob($root.'/skins/*/manifest.json') ?: [] as $mf) {
        $slug = basename(dirname($mf));
        $m = json_decode((string)file_get_contents($mf), true);
        if (!is_array($m)) continue;
        $opts = $m['options'] ?? $m;
        $layout = @file_get_contents($root."/skins/$slug/layout.php") ?: '';
        $css = @file_get_contents($root."/skins/$slug/style.css") ?: '';
        $cssAll = $css; foreach (glob($root."/skins/$slug/*.css") ?: [] as $cf2) $cssAll .= "\n".(string)file_get_contents($cf2);
        $markup = scs_strip_php($layout);
        preg_match_all("/snap_render_component\(\s*'([^']+)'/", $layout, $cm);
        foreach (array_unique($cm[1] ?? []) as $cname) {
            $cf = $root."/core/".$cname."-component.php";
            if (is_file($cf)) $markup .= "\n".scs_strip_php((string)file_get_contents($cf));
            else $markup .= "\n".scs_component_markup($cname, $srh);
        }
        $markup .= "\n".$shared;
        $x = scs_dom($markup);
        $jsBlob = '';
        foreach (array_merge(glob($root."/skins/$slug/*.js") ?: [], glob($root."/skins/$slug/**/*.js") ?: [], glob($root."/assets/js/*.js") ?: []) as $j)
            $jsBlob .= "\n".(string)file_get_contents($j);
        foreach ((is_array($opts) ? $opts : []) as $key => $o) {
            if (!is_array($o)) continue;
            $prop = (string)($o['property'] ?? ''); $sel = (string)($o['selector'] ?? '');
            if ($prop === '' || $sel === '') continue;
            if (strncmp($prop, '--', 2) === 0 || $sel === ':root' || $sel === 'html') {
                if (strncmp($prop, '--', 2) === 0)
                    $status = strpos($cssAll, 'var('.$prop) !== false ? 'WIRED' : 'DEAD';
                else $status = 'WIRED';
            } elseif (scs_matches($x, $sel)) {
                $rc = scs_rightmost_class($sel);
                $status = ($rc && preg_match('/\.'.preg_quote($rc, '/').'\b[^{}]*\{[^{}]*'.preg_quote($prop, '/').'\s*:/s', $css)) ? 'OVERRIDDEN' : 'WIRED';
            } else {
                $rc = scs_rightmost_class($sel);
                $status = ($rc && strpos($jsBlob, $rc) !== false) ? 'JS-EMIT' : 'DEAD';
            }
            $rows[] = ['skin'=>$slug,'control'=>$key,'selector'=>$sel,'property'=>$prop,'status'=>$status];
        }
    }
    usort($rows, fn($a,$b)=>[$a['status'],$a['skin'],$a['control']]<=>[$b['status'],$b['skin'],$b['control']]);
    return $rows;
}

// CLI report when run directly.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = getenv('SS_ROOT') ?: dirname(__DIR__);
    $rows = snapsmack_scan_control_selectors($root);
    $c = ['DEAD'=>0,'OVERRIDDEN'=>0,'JS-EMIT'=>0,'WIRED'=>0];
    foreach ($rows as $r) {
        $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
        if ($r['status'] !== 'WIRED')
            printf("%-10s %-22s %-26s %s {%s}\n", $r['status'], $r['skin'], $r['control'], $r['selector'], $r['property']);
    }
    printf("\nDEAD=%d  OVERRIDDEN=%d  JS-EMIT=%d  WIRED=%d\n", $c['DEAD'], $c['OVERRIDDEN'], $c['JS-EMIT'], $c['WIRED']);
}
// ===== SNAPSMACK EOF =====
