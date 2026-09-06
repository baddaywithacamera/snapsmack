use base64::Engine;
use std::path::Path;
use aes::Aes128;
use cbc::cipher::{block_padding::Pkcs7, BlockDecryptMut, KeyIvInit};
use hmac::{Hmac, Mac};
use sha2::Sha256;

fn site_key(site_url: &str) -> Result<String, String> {
    let parsed = reqwest::Url::parse(site_url)
        .or_else(|_| reqwest::Url::parse(&format!("https://{}", site_url)))
        .map_err(|_| "Invalid site URL".to_string())?;
    let host = parsed.host_str().ok_or_else(|| "Site URL has no host".to_string())?;
    let mut out = String::new();
    let mut dash = false;
    for ch in host.to_lowercase().chars() {
        if ch.is_ascii_alphanumeric() || ch == '.' || ch == '-' {
            if ch == '-' && dash { continue; }
            dash = ch == '-';
            out.push(ch);
        } else if !dash {
            out.push('-'); dash = true;
        }
    }
    let clean = out.trim_matches(&['.', '-'][..]).to_string();
    if clean.is_empty() { Err("Site URL has no usable host".into()) } else { Ok(clean) }
}

#[cfg(windows)]
fn native_read(site_url: &str, key_type: &str) -> Result<String, String> {
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::Security::Credentials::{CredFree, CredReadW, CREDENTIALW, CRED_TYPE_GENERIC};
    let target = format!("SnapSmack.Shared:{}:{}", site_key(site_url)?, key_type);
    let wide: Vec<u16> = std::ffi::OsStr::new(&target).encode_wide().chain(Some(0)).collect();
    let mut ptr: *mut CREDENTIALW = std::ptr::null_mut();
    if unsafe { CredReadW(wide.as_ptr(), CRED_TYPE_GENERIC, 0, &mut ptr) } == 0 {
        return Err("No protected native credential is available".into());
    }
    let bytes = unsafe { std::slice::from_raw_parts((*ptr).CredentialBlob, (*ptr).CredentialBlobSize as usize).to_vec() };
    unsafe { CredFree(ptr.cast()); }
    String::from_utf8(bytes).map_err(|_| "Protected credential is not UTF-8".into())
}

#[cfg(not(windows))]
fn native_read(_: &str, _: &str) -> Result<String, String> { Err("Native credential store unavailable".into()) }

#[cfg(windows)]
fn unprotect(input: &[u8], entropy: &[u8]) -> Result<Vec<u8>, String> {
    use windows_sys::Win32::Security::Cryptography::{CryptUnprotectData, CRYPT_INTEGER_BLOB};
    use windows_sys::Win32::Foundation::LocalFree;
    let mut source = CRYPT_INTEGER_BLOB { cbData: input.len() as u32, pbData: input.as_ptr() as *mut u8 };
    let mut salt = CRYPT_INTEGER_BLOB { cbData: entropy.len() as u32, pbData: entropy.as_ptr() as *mut u8 };
    let mut output = CRYPT_INTEGER_BLOB { cbData: 0, pbData: std::ptr::null_mut() };
    let ok = unsafe { CryptUnprotectData(&mut source, std::ptr::null_mut(), &mut salt,
        std::ptr::null_mut(), std::ptr::null_mut(), 0, &mut output) };
    if ok == 0 { return Err(format!("Windows protected storage: {}", std::io::Error::last_os_error())); }
    let result = unsafe { std::slice::from_raw_parts(output.pbData, output.cbData as usize).to_vec() };
    unsafe { LocalFree(output.pbData.cast()); }
    Ok(result)
}

#[cfg(not(windows))]
fn unprotect(_input: &[u8], _entropy: &[u8]) -> Result<Vec<u8>, String> {
    Err("The shared native credential bridge is not available on this platform".into())
}

pub fn read(root: &Path, site_url: &str, key_type: &str) -> Result<String, String> {
    if let Ok(value) = native_read(site_url, key_type) { return Ok(value); }
    let auth = root.join("shared_library").join("auth");
    let store: serde_json::Value = serde_json::from_slice(
        &std::fs::read(auth.join("shared_creds.json")).map_err(|e| e.to_string())?)
        .map_err(|e| e.to_string())?;
    let field = format!("site:{}:api_key_{}", site_key(site_url)?, key_type);
    let blob = store.get(&field).and_then(|v| v.as_str())
        .ok_or_else(|| format!("Discover has no {} credential for this site", key_type))?;
    if let Some(token) = blob.strip_prefix("enc1:") {
        let sealed = base64::engine::general_purpose::STANDARD.decode(
            std::fs::read(auth.join("vault.machine")).map_err(|e| e.to_string())?
        ).map_err(|e| e.to_string())?;
        let key = unprotect(&sealed, b"SnapSmack:SnapSmackShared")?;
        return decrypt_fernet(&key, token);
    }
    if let Some(value) = blob.strip_prefix("b64:") {
        return String::from_utf8(base64::engine::general_purpose::STANDARD.decode(value).map_err(|e| e.to_string())?)
            .map_err(|_| "Shared credential is not UTF-8".to_string());
    }
    Err("Refused legacy plaintext shared credential".into())
}

fn decrypt_fernet(key: &[u8], token: &str) -> Result<String, String> {
        let raw_key = base64::engine::general_purpose::URL_SAFE.decode(key).map_err(|_| "Invalid shared vault key".to_string())?;
        if raw_key.len() != 32 { return Err("Invalid shared vault key".into()); }
        let decoded = base64::engine::general_purpose::URL_SAFE.decode(token).map_err(|_| "Invalid shared credential".to_string())?;
        if decoded.len() < 1 + 8 + 16 + 16 + 32 || decoded[0] != 0x80 {
            return Err("Invalid shared credential".into());
        }
        let signed_len = decoded.len() - 32;
        let mut mac = Hmac::<Sha256>::new_from_slice(&raw_key[..16]).map_err(|_| "Invalid shared vault key".to_string())?;
        mac.update(&decoded[..signed_len]);
        mac.verify_slice(&decoded[signed_len..]).map_err(|_| "Shared credential authentication failed".to_string())?;
        let iv = &decoded[9..25];
        let plaintext = cbc::Decryptor::<Aes128>::new_from_slices(&raw_key[16..], iv)
            .map_err(|_| "Invalid shared credential cipher".to_string())?
            .decrypt_padded_vec_mut::<Pkcs7>(&decoded[25..signed_len])
            .map_err(|_| "Shared credential could not be decrypted".to_string())?;
        String::from_utf8(plaintext).map_err(|_| "Shared credential is not UTF-8".to_string())
}

#[cfg(test)]
mod tests {
    use super::{decrypt_fernet, native_read};

    #[test]
    fn decrypts_python_fernet_tokens() {
        let key = b"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";
        let token = "gAAAAABqnEltOKPDYIMCIrFA2Z-siYFhU4zSHmcTj4NMd3rP68lY3H9NMqRN2zLf3jPFrS1br-egb4GQp_yCKXz79SQ0R5D-Pg==";
        assert_eq!(decrypt_fernet(key, token).unwrap(), "bridge-test");
    }

    #[cfg(windows)]
    #[test]
    fn reads_python_native_bridge_fixture_when_requested() {
        if std::env::var("SNAPSMACK_NATIVE_BRIDGE_TEST").is_ok() {
            assert_eq!(native_read("https://native-bridge.invalid", "test").unwrap(), "secret-value");
        }
    }
}
