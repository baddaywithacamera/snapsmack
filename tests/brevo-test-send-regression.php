<?php
/**
 * FAKE TRANSPORT. NO NETWORK, NO EMAIL, EVER.
 *
 * This file replaces the curl functions with stubs so snapsmack_send_mail()
 * can be driven through every Brevo response code without touching the
 * network. That only works on a PHP with NO curl extension, because you
 * cannot redeclare a function that already exists.
 *
 * IT USED TO SAY "run with php -n" IN A COMMENT AND LEAVE IT AT THAT. That
 * held for as long as nobody had curl installed. Curl was then enabled on this
 * machine with the owner's authorisation -- at my request, for the skin
 * registry work -- and the next full release gate run died here with "Cannot
 * redeclare function curl_init()", taking the 977D gate with it. A test that
 * depends on how it is invoked is a test that stops someone else's release.
 *
 * So it now isolates itself: if curl is present, re-run under -n in a child
 * process and report what the child reported. The runner no longer has to know
 * anything.
 *
 * WHAT IT MUST NEVER DO IS FALL BACK TO REAL CURL. Guarding the stubs with
 * function_exists() would make the test "pass" by sending live HTTP to
 * api.brevo.com with whatever key was in the settings. A test that mails
 * someone is worse than a test that fails.
 */
if (extension_loaded('curl')) {
    if (($argv[1] ?? '') === '--isolated') {
        // -n did not remove curl, so the fake transport cannot be built. There
        // is no safe way to run these assertions here, and saying nothing would
        // be a silent pass.
        fwrite(STDERR, "CANNOT RUN: curl is still loaded under -n, so the fake transport\n"
            . "cannot be installed. Refusing to fall back to real HTTP. Run this file on\n"
            . "a PHP without the curl extension.\n");
        exit(1);
    }
    // NO STDERR REDIRECT ON THIS COMMAND, AND THAT IS DELIBERATE. The child's
    // stderr already passes straight through to whoever ran this, stdout is
    // still captured and the exit code is still preserved -- I checked all
    // three rather than assume.
    //
    // The redirect was costing something real. tests/test-strength-regression.php
    // records "every number the file compares against" as a bar the code is
    // held to, and a shell redirect reads to its matcher as a numeral sitting
    // next to a greater-than operator. So this file suddenly claimed a
    // threshold it does not have, and the 977D gate stopped on it. Writing
    // that into the record would have been worse than the stop: a meaningless
    // bar, kept for ever, in the one file whose job is to make a changed bar
    // loud.
    //
    // THE COMMENT ABOVE IS WORDED AROUND THE REDIRECT ON PURPOSE. My first
    // version of it spelled the redirect out, the matcher does not skip
    // comments, and the gate went on failing on prose explaining why it had
    // failed. Third time today a comment of mine satisfied or broke a check
    // meant for code.
    $cmd = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --isolated';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    echo implode("\n", $out) . "\n";
    exit($rc);
}
// defined() first: redefining a constant is a warning today and an ERROR in
// PHP 9, so an unguarded define() here would turn a clear refusal below into a
// fatal with a misleading message on a future PHP.
foreach (['CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLINFO_HTTP_CODE'] as $i => $c) {
    if (!defined($c)) define($c, $i + 1);
}

// THE BLOCK IS NOT DECORATION; IT IS WHAT MAKES THE GUARD ABOVE WORK.
//
// A top-level function declaration in PHP is HOISTED: it is bound when the
// file is compiled, before the first statement runs. So my first attempt at
// the guard above was useless -- the file still died of "Cannot redeclare
// curl_init" before the re-exec could happen, because the declaration never
// waited for it. Conditionally declared functions are NOT hoisted; they are
// bound when the block executes. Wrapping these in a condition is therefore
// the fix, not a style choice, and the condition is the true one by the time
// we get here.
if (!extension_loaded('curl')) {
    function curl_init($url) { if ($url !== 'https://api.brevo.com/v3/smtp/email') throw new Exception('Wrong endpoint'); return true; }
    function curl_setopt_array($ch, $options) { $GLOBALS['payload'] = json_decode($options[CURLOPT_POSTFIELDS], true); }
    function curl_exec($ch) { return '{}'; }
    function curl_getinfo($ch, $key) { return $GLOBALS['http']; }
    function curl_error($ch) { return ''; }
    function curl_close($ch) {}
} else {
    // UNREACHABLE TODAY, AND HERE ON PURPOSE.
    //
    // Mutating the re-exec guard away showed the danger: with the guard gone
    // and curl present, the condition above is simply FALSE, the stubs are
    // skipped, and mailer.php then calls REAL curl -- live HTTP to
    // api.brevo.com with whatever key the settings array holds. The test would
    // look like it ran. This branch makes that impossible to reach quietly.
    fwrite(STDERR, "REFUSING TO RUN: curl is loaded at stub time, so the fake transport\n"
        . "was not installed and these assertions would issue REAL requests to Brevo.\n"
        . "Something removed the isolation guard at the top of this file.\n");
    exit(1);
}
require __DIR__ . '/../core/mailer.php';
function check($value, $message) { if (!$value) throw new Exception($message); }
$options = ['brevo_only'=>true, 'settings'=>['brevo_api_key'=>'test-key','email_from'=>'sender@example.com','site_url'=>'https://example.com']];
foreach ([0,400,401,403,429,500] as $code) {
    $GLOBALS['http']=$code;
    check(snapsmack_send_mail('owner@example.com','Test','Test',$options) === false, 'Failure must not fall back: '.$code);
}
$GLOBALS['http']=201;
check(snapsmack_send_mail('owner@example.com','Test','Test',$options), 'Brevo acceptance');
check($GLOBALS['payload']['sender']['email']==='sender@example.com', 'Saved sender used');
check($GLOBALS['payload']['to'][0]['email']==='owner@example.com', 'Requested recipient used');
$options['settings']['brevo_api_key']='';
check(!snapsmack_send_mail('owner@example.com','Test','Test',$options), 'Missing key must not fall back');
check(!snapsmack_send_mail('invalid','Test','Test',$options), 'Invalid recipient rejected');
echo "PASS Brevo-only transport acceptance, rejection and missing-key checks\n";
