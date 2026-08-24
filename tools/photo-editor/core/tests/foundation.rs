// Packet 01 acceptance tests.
// SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use serde_json::json;
use snapsmack_photo_core::{
    error_code, file_is_heic, handle_sync, validate_job, Request, SidecarState,
};
use std::fs;
use std::path::PathBuf;
use std::time::{SystemTime, UNIX_EPOCH};

fn fixture_root() -> PathBuf {
    let stamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_nanos();
    let root = std::env::temp_dir().join(format!(
        "snapsmack-photo-core-{}-{stamp}",
        std::process::id()
    ));
    fs::create_dir_all(&root).unwrap();
    root
}

#[test]
fn hello_negotiates_only_contract_v1() {
    let root = fixture_root();
    let state = SidecarState::new(vec![root.clone()]).unwrap();
    let (ok, _) = handle_sync(
        &state,
        Request {
            id: json!(1),
            method: "hello".into(),
            params: json!({"contract_versions":[1]}),
        },
    );
    assert!(ok.ok);
    let (bad, _) = handle_sync(
        &state,
        Request {
            id: json!(2),
            method: "hello".into(),
            params: json!({"contract_versions":[99]}),
        },
    );
    assert_eq!(bad.error.unwrap().code, error_code::CONTRACT_UNSUPPORTED);
    fs::remove_dir_all(root).unwrap();
}

#[test]
fn job_validation_rejects_escape_and_heic_name_or_signature() {
    let root = fixture_root();
    let outside = fixture_root();
    let jpg = root.join("photo.jpg");
    fs::write(&jpg, b"not decoded in packet one").unwrap();
    let named = root.join("photo.heic");
    fs::write(&named, b"x").unwrap();
    let disguised = root.join("renamed.jpg");
    fs::write(
        &disguised,
        [
            0, 0, 0, 24, b'f', b't', b'y', b'p', b'h', b'e', b'i', b'c', 0, 0, 0, 0, b'm', b'i',
            b'f', b'1',
        ],
    )
    .unwrap();
    assert!(file_is_heic(&named));
    assert!(file_is_heic(&disguised));

    let state = SidecarState::new(vec![root.clone()]).unwrap();
    let job = |input: &std::path::Path, output: &std::path::Path| {
        json!({
            "contract_version":1,
            "mode":"edit-copy",
            "input_path":input,
            "permitted_output_directory":output,
            "calling_app":"coldsnap",
            "return_token":"01234567890123456789012345678901"
        })
    };
    assert!(validate_job(&state, &job(&jpg, &root)).is_ok());
    let mut bad_caller = job(&jpg, &root);
    bad_caller["calling_app"] = json!("unknown-tool");
    assert_eq!(
        validate_job(&state, &bad_caller).unwrap_err().code,
        error_code::BAD_REQUEST
    );
    assert_eq!(
        validate_job(&state, &job(&jpg, &outside)).unwrap_err().code,
        error_code::PATH_NOT_APPROVED
    );
    assert_eq!(
        validate_job(&state, &job(&named, &root)).unwrap_err().code,
        error_code::HEIC_UNSUPPORTED
    );
    assert_eq!(
        validate_job(&state, &job(&disguised, &root))
            .unwrap_err()
            .code,
        error_code::HEIC_UNSUPPORTED
    );
    fs::remove_dir_all(root).unwrap();
    fs::remove_dir_all(outside).unwrap();
}

#[test]
fn cancellation_state_is_explicit_and_clearable() {
    let root = fixture_root();
    let state = SidecarState::new(vec![root.clone()]).unwrap();
    assert!(!state.is_cancelled("job-1"));
    state.cancel("job-1");
    assert!(state.is_cancelled("job-1"));
    state.clear_cancelled("job-1");
    assert!(!state.is_cancelled("job-1"));
    fs::remove_dir_all(root).unwrap();
}

// ===== SNAPSMACK EOF =====
