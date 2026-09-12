<?php
/**
 * SNAPSMACK.CA - Shared desktop-tool page layout.
 *
 * The requiring page supplies the usual metadata plus:
 *   $tool_name      — display name (e.g. 'SMACK YOUR BATCH UP')
 *   $tool_short     — one sentence: what it does for your SITE
 *   $tool_platform  — 'Windows / Linux'
 *   $tool_status    — 'Shipping' | 'Closed beta' | 'In the workshop'
 *   $tool_group     — 'make' | 'publish' | 'get-in' | 'keep'  (hub grouping)
 *   $tool_body      — HTML: the long-form description (already escaped)
 *   $tool_shots     — array of [src, alt, caption] (optional)
 *   $tool_facts     — array of [label, value] (optional)
 *   $tool_news      — wotcha.php anchor (optional)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$nav_active = $nav_active ?? 'goods-tools';
$tool_shots = $tool_shots ?? [];
$tool_facts = $tool_facts ?? [];
$page_css = ($page_css ?? '') . <<<'CSS'
.tool-header { padding: 64px 0 40px; border-bottom: 1px solid var(--border); }
.tool-header .platform { display: inline-block; margin-right: 10px; color: var(--mid-grey); font: 700 .72rem/1.2 'Courier New', monospace; text-transform: uppercase; letter-spacing: .08em; }
.tool-header .status { display: inline-block; padding: 5px 9px; background: var(--black); color: var(--white); font: 700 .68rem/1 Arial, sans-serif; text-transform: uppercase; }
.tool-header .status--beta { background: var(--red); }
.tool-header h1 { margin: 12px 0 14px; }
.tool-header .lede { max-width: 780px; color: var(--dark-grey); }
.tool-crumb { margin-bottom: 14px; }
.tool-crumb a { color: var(--red); font: 900 .72rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: .04em; }
.tool-body { max-width: 820px; padding: 48px 0 24px; }
.tool-body h2 { color: var(--black); font-size: 1.35rem; margin: 34px 0 12px; }
.tool-body h2:first-child { margin-top: 0; }
.tool-body ul { margin: 0 0 1.4em 1.4em; }
.tool-body li { margin-bottom: .5em; }
.tool-shots { display: grid; gap: 40px; padding: 24px 0 64px; }
.tool-shot { margin: 0; border: 1px solid var(--border); background: var(--black); }
.tool-shot img { display: block; width: 100%; }
.tool-shot figcaption { padding: 10px 14px; color: #aaa; background: var(--black); font: .7rem/1.4 'Courier New', monospace; }
.tool-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1px; margin: 0 0 40px; background: var(--border); border: 1px solid var(--border); }
.tool-fact { padding: 16px 18px; background: var(--white); }
.tool-fact .label { display: block; color: var(--mid-grey); font: 700 .64rem/1.2 'Courier New', monospace; letter-spacing: .08em; text-transform: uppercase; }
.tool-fact .value { display: block; margin-top: 4px; color: var(--black); font: 900 .95rem/1.3 Arial Black, Arial, sans-serif; }
.tool-next { padding: 48px 0 72px; border-top: 8px solid var(--black); background: #f4f1eb; }
.tool-next .goods-nav { margin-top: 20px; }
CSS;

$_status_cls = (stripos($tool_status, 'beta') !== false) ? ' status--beta' : '';

require_once __DIR__ . '/header.php';
?>
<main>
    <header class="tool-header">
        <div class="wrap">
            <p class="tool-crumb"><a href="tools.php">&larr; BOX O&rsquo; TRICKS! &middot; the desktop suite</a></p>
            <p><span class="platform"><?php echo htmlspecialchars($tool_platform); ?></span><span class="status<?php echo $_status_cls; ?>"><?php echo htmlspecialchars($tool_status); ?></span></p>
            <h1><?php echo htmlspecialchars($tool_name); ?></h1>
            <p class="lede"><?php echo $tool_short; ?></p>
        </div>
    </header>

    <div class="wrap">
        <div class="tool-body">
<?php if ($tool_facts): ?>
            <div class="tool-facts">
<?php foreach ($tool_facts as [$label, $value]): ?>
                <div class="tool-fact"><span class="label"><?php echo htmlspecialchars($label); ?></span><span class="value"><?php echo $value; ?></span></div>
<?php endforeach; ?>
            </div>
<?php endif; ?>
<?php echo $tool_body; ?>
<?php if (!empty($tool_news)): ?>
            <p><a href="wotcha.php#<?php echo htmlspecialchars($tool_news); ?>"><strong>Read the WOTCHA announcement &rarr;</strong></a></p>
<?php endif; ?>
        </div>
<?php if ($tool_shots): ?>
        <div class="tool-shots">
<?php foreach ($tool_shots as [$src, $alt, $cap]): ?>
            <figure class="tool-shot">
                <img src="img/<?php echo htmlspecialchars($src); ?>" alt="<?php echo htmlspecialchars($alt); ?>" loading="lazy">
                <figcaption><?php echo $cap; ?></figcaption>
            </figure>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>

    <section class="tool-next">
        <div class="wrap">
            <p class="site-discovery-kicker">Keep going</p>
            <h2>The rest of the box.</h2>
            <nav class="goods-nav" aria-label="The Goods">
                <a href="tools.php"><strong>BOX O' TRICKS!</strong><span>Every desktop tool, grouped by job.</span></a>
                <a href="features.php"><strong>THE GOODS!</strong><span>What the website itself does.</span></a>
                <a href="brass-tacks.php"><strong>BRASS TACKS!</strong><span>The FAQ, including the desktop tools section.</span></a>
            </nav>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
