use base64::Engine;
use std::path::Path;
use aes::Aes128;
use cbc::cipher::{block_padding::Pkcs7, BlockDecryptMut, KeyIvInit};
use hmac::{Hmac, Mac};
use sha2::Sha256;

fn site_key(site: &str) -> Result<String, String> {
    let mut host = site.trim().to_lowercase();
    if let Some(pos) = host.find("://") { host = host[pos + 3..].to_string(); }
    host = host.split(&['/', '?', '#'][..]).next().unwrap_or("").to_string();
    if let Some(pos) = host.rfind('@') { host = host[pos + 1..].to_string(); }
    if let Some(pos) = host.rfind(':') {
        if host[pos + 1..].chars().all(|c| c.is_ascii_digit()) { host.truncate(pos); }
    }
    let mut out = String::new(); let mut dash = false;
    for ch in host.chars() {
        if ch.is_ascii_alphanumeric() || ch == '.' || ch == '-' {
            if ch == '-' && dash { continue; } dash = ch == '-'; out.push(ch);
        } else if !dash { out.push('-'); dash = true; }
    }
    let clean = out.trim_matches(&['.', '-'][..]).to_string();
    if clean.is_empty() { Err("Site URL has no usable host".into()) } else { Ok(clean) }
}

#[cfg(windows)]
fn native_read(site: &str, kind: &str) -> Result<String, String> {
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::Security::Credentials::{CredFree, CredReadW, CREDENTIALW, CRED_TYPE_GENERIC};
    let target = format!("SnapSmack.Shared:{}:{}", site_key(site)?, kind);
    let wide: Vec<u16> = std::ffi::OsStr::new(&target).encode_wide().chain(Some(0)).collect();
    let mut ptr: *mut CREDENTIALW = std::ptr::null_mut();
    if unsafe { CredReadW(wide.as_ptr(), CRED_TYPE_GENERIC, 0, &mut ptr) } == 0 { return Err("No protected native credential".into()); }
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
    unsafe { LocalFree(output.pbData.cast()); } Ok(result)
}

#[cfg(not(windows))]
fn unprotect(_: &[u8], _: &[u8]) -> Result<Vec<u8>, String> { Err("Protected credential bridge unavailable".into()) }

fn decrypt(key: &[u8], token: &str) -> Result<String, String> {
    let raw = base64::engine::general_purpose::URL_SAFE.decode(key).map_err(|_| "Invalid vault key".to_string())?;
    let data = base64::engine::general_purpose::URL_SAFE.decode(token).map_err(|_| "Invalid credential".to_string())?;
    if raw.len() != 32 || data.len() < 73 || data[0] != 0x80 { return Err("Invalid credential".into()); }
    let end = data.len() - 32;
    let mut mac = Hmac::<Sha256>::new_from_slice(&raw[..16]).map_err(|_| "Invalid vault key".to_string())?;
    mac.update(&data[..end]); mac.verify_slice(&data[end..]).map_err(|_| "Credential authentication failed".to_string())?;
    let plain = cbc::Decryptor::<Aes128>::new_from_slices(&raw[16..], &data[9..25]).map_err(|_| "Invalid cipher".to_string())?
        .decrypt_padded_vec_mut::<Pkcs7>(&data[25..end]).map_err(|_| "Credential decryption failed".to_string())?;
    String::from_utf8(plain).map_err(|_| "Credential is not UTF-8".into())
}

pub fn read(root: &Path, site: &str, kind: &str) -> Result<String, String> {
    if let Ok(value) = native_read(site, kind) { return Ok(value); }
    let auth = root.join("shared_library").join("auth");
    let store: serde_json::Value = serde_json::from_slice(&std::fs::read(auth.join("shared_creds.json")).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
    let name = format!("site:{}:api_key_{}", site_key(site)?, kind);
    let blob = store.get(name).and_then(|v| v.as_str()).ok_or_else(|| format!("Discover has no {} key for this site", kind))?;
    if let Some(token) = blob.strip_prefix("enc1:") {
        let sealed = base64::engine::general_purpose::STANDARD.decode(std::fs::read(auth.join("vault.machine")).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
        return decrypt(&unprotect(&sealed, b"SnapSmack:SnapSmackShared")?, token);
    }
    if let Some(value) = blob.strip_prefix("b64:") {
        return String::from_utf8(base64::engine::general_purpose::STANDARD.decode(value).map_err(|e| e.to_string())?).map_err(|_| "Credential is not UTF-8".into());
    }
    Err("Refused legacy plaintext shared credential".into())
}

#[cfg(test)] mod tests { use super::{decrypt, native_read}; #[test] fn python_token() {
    assert_eq!(decrypt(b"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "gAAAAABqnEltOKPDYIMCIrFA2Z-siYFhU4zSHmcTj4NMd3rP68lY3H9NMqRN2zLf3jPFrS1br-egb4GQp_yCKXz79SQ0R5D-Pg==").unwrap(), "bridge-test");
}
#[cfg(windows)] #[test] fn native_fixture_when_requested() {
    if std::env::var("SNAPSMACK_NATIVE_BRIDGE_TEST").is_ok() {
        assert_eq!(native_read("https://native-bridge.invalid", "test").unwrap(), "secret-value");
    }
}}
