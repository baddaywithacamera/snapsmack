<?php
// Run with php -n: fake transport, no network or email is sent.
foreach (['CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLINFO_HTTP_CODE'] as $i => $c) define($c, $i + 1);
function curl_init($url) { if ($url !== 'https://api.brevo.com/v3/smtp/email') throw new Exception('Wrong endpoint'); return true; }
function curl_setopt_array($ch, $options) { $GLOBALS['payload'] = json_decode($options[CURLOPT_POSTFIELDS], true); }
function curl_exec($ch) { return '{}'; }
function curl_getinfo($ch, $key) { return $GLOBALS['http']; }
function curl_error($ch) { return ''; }
function curl_close($ch) {}
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
