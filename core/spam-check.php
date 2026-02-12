<?php
/**
 * SnapSmack - Akismet Spam Filter
 * Version: 1.0
 */

function is_spam($author, $email, $body, $pdo) {
    // 1. Fetch the key from the DB
    $stmt = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'akismet_key'");
    $akismet_key = $stmt->fetchColumn();

    // If no key is set, we fail 'safe' (let the comment through) 
    // or you can change this to return true if you want total lockdown.
    if (!$akismet_key) return false;

    $blog_url = 'https://baddaywithacamera.ca';
    
    $query = http_build_query([
        'blog' => $blog_url,
        'user_ip' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
        'comment_type' => 'comment',
        'comment_author' => $author,
        'comment_author_email' => $email,
        'comment_content' => $body
    ]);

    $host = "$akismet_key.rest.akismet.com";
    $path = "/1.1/comment-check";
    
    $request = "POST $path HTTP/1.0\r\n";
    $request .= "Host: $host\r\n";
    $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $request .= "Content-Length: " . strlen($query) . "\r\n";
    $request .= "User-Agent: SnapSmack/4.3\r\n\r\n";
    $request .= $query;

    $response = "";
    $fs = fsockopen($host, 80);
    if ($fs) {
        fwrite($fs, $request);
        while (!feof($fs)) $response .= fgets($fs, 1160);
        fclose($fs);
        
        $response = explode("\r\n\r\n", $response, 2);
        // Akismet returns "true" if it is spam
        return (trim($response[1] ?? '') == 'true');
    }
    return false; 
}