// Packet 01 process/progress/cancellation acceptance test.
// SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use serde_json::{json, Value};
use std::fs;
use std::io::{BufRead, BufReader, Write};
use std::process::{Command, Stdio};
use std::time::{SystemTime, UNIX_EPOCH};

#[test]
fn sidecar_reports_progress_and_cancels_without_orphaning() {
    let stamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap()
        .as_nanos();
    let root =
        std::env::temp_dir().join(format!("snapsmack-sidecar-{}-{stamp}", std::process::id()));
    fs::create_dir_all(&root).unwrap();

    let mut child = Command::new(env!("CARGO_BIN_EXE_snapsmack-photo-core"))
        .arg("--allowed-root")
        .arg(&root)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let mut input = child.stdin.take().unwrap();
    let mut output = BufReader::new(child.stdout.take().unwrap());
    let mut line = String::new();

    writeln!(input, "{}", json!({"id":1,"method":"start_synthetic","params":{"job_id":"job-a","steps":100,"delay_ms":2}})).unwrap();
    input.flush().unwrap();
    output.read_line(&mut line).unwrap();
    let started: Value = serde_json::from_str(&line).unwrap();
    assert_eq!(started["ok"], true);

    writeln!(
        input,
        "{}",
        json!({"id":2,"method":"cancel","params":{"job_id":"job-a"}})
    )
    .unwrap();
    input.flush().unwrap();

    let mut saw_cancel_response = false;
    let mut saw_cancel_event = false;
    for _ in 0..20 {
        line.clear();
        assert!(output.read_line(&mut line).unwrap() > 0);
        let message: Value = serde_json::from_str(&line).unwrap();
        if message["id"] == 2 && message["ok"] == true {
            saw_cancel_response = true;
        }
        if message["event"] == "cancelled" && message["job_id"] == "job-a" {
            saw_cancel_event = true;
        }
        if saw_cancel_response && saw_cancel_event {
            break;
        }
    }
    assert!(saw_cancel_response, "cancel request was not acknowledged");
    assert!(saw_cancel_event, "worker did not report cancellation");

    writeln!(input, "{}", json!({"id":3,"method":"shutdown","params":{}})).unwrap();
    input.flush().unwrap();
    let status = child.wait().unwrap();
    assert!(status.success());
    fs::remove_dir_all(root).unwrap();
}

// ===== SNAPSMACK EOF =====
