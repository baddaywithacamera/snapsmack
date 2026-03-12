<?php
/**
 * SNAPSMACK - Release Signing Public Key
 * Alpha v0.7.2
 *
 * Contains the Ed25519 public key used to verify the authenticity of update
 * packages downloaded from the release server. The corresponding private key
 * is held offline by the release maintainer and never touches a web server.
 *
 * HOW SIGNING WORKS:
 * 1. Developer builds a release zip and generates a SHA-256 checksum.
 * 2. Developer signs the checksum with the Ed25519 private key (sodium_crypto_sign_detached).
 * 3. The hex-encoded signature is published alongside the release metadata.
 * 4. This file provides the public half so the updater can verify authenticity
 *    before extracting anything.
 *
 * TO GENERATE A KEYPAIR (run once, offline):
 *   $keypair = sodium_crypto_sign_keypair();
 *   $secret  = sodium_bin2hex(sodium_crypto_sign_secretkey($keypair));
 *   $public  = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
 *   // Store $secret OFFLINE. Paste $public below.
 *
 * TO SIGN A RELEASE:
 *   $checksum  = hash_file('sha256', 'snapsmack-0.8.zip');
 *   $secret    = sodium_hex2bin('YOUR_SECRET_KEY_HEX');
 *   $signature = sodium_bin2hex(sodium_crypto_sign_detached($checksum, $secret));
 *   // Publish $signature in the release manifest JSON.
 */

// Ed25519 public key for release signature verification
define('SNAPSMACK_RELEASE_PUBKEY', '4b397509c45a995c2a5c098582a7a547892d6a7bc91b2ecc60e79c9e776c53d3');

// Set to true once a real key is installed and all releases are being signed.
// When false, signature verification is logged but not enforced.
define('SNAPSMACK_SIGNING_ENFORCED', true);
