//! SnapSmack Photo Editor JSON-lines sidecar.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use serde_json::{json, Value};
use snapsmack_photo_core::{error_code, handle_sync, Request, Response, RpcError, SidecarState};
use std::env;
use std::io::{self, BufRead, Write};
use std::path::PathBuf;
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::Duration;

fn emit(output: &Arc<Mutex<io::Stdout>>, value: &impl serde::Serialize) {
    let Ok(line) = serde_json::to_string(value) else {
        return;
    };
    let mut out = output.lock().expect("stdout mutex poisoned");
    let _ = writeln!(out, "{line}");
    let _ = out.flush();
}

fn roots_from_args() -> Vec<PathBuf> {
    let mut args = env::args().skip(1);
    let mut roots = Vec::new();
    while let Some(arg) = args.next() {
        if arg == "--allowed-root" {
            if let Some(value) = args.next() {
                roots.push(PathBuf::from(value));
            }
        }
    }
    roots
}

fn main() {
    let state = match SidecarState::new(roots_from_args()) {
        Ok(value) => value,
        Err(error) => {
            eprintln!("{}: {}", error.code, error.message);
            std::process::exit(2);
        }
    };
    let output = Arc::new(Mutex::new(io::stdout()));
    for line in io::stdin().lock().lines() {
        let Ok(line) = line else {
            break;
        };
        let request: Request = match serde_json::from_str(&line) {
            Ok(value) => value,
            Err(error) => {
                emit(
                    &output,
                    &Response::failure(
                        Value::Null,
                        RpcError::new(error_code::BAD_REQUEST, error.to_string()),
                    ),
                );
                continue;
            }
        };

        if request.method == "start_synthetic" {
            let id = request.id;
            let job_id = request
                .params
                .get("job_id")
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_owned();
            let steps = request
                .params
                .get("steps")
                .and_then(Value::as_u64)
                .unwrap_or(20)
                .clamp(1, 1000);
            let delay = request
                .params
                .get("delay_ms")
                .and_then(Value::as_u64)
                .unwrap_or(5)
                .min(1000);
            if job_id.is_empty() {
                emit(
                    &output,
                    &Response::failure(
                        id,
                        RpcError::new(error_code::BAD_REQUEST, "missing job_id"),
                    ),
                );
                continue;
            }
            state.clear_cancelled(&job_id);
            emit(
                &output,
                &Response::success(id, json!({"job_id": job_id, "started": true})),
            );
            let worker_state = state.clone();
            let worker_output = Arc::clone(&output);
            thread::spawn(move || {
                for step in 1..=steps {
                    if worker_state.is_cancelled(&job_id) {
                        emit(
                            &worker_output,
                            &json!({"event":"cancelled", "job_id":job_id}),
                        );
                        worker_state.clear_cancelled(&job_id);
                        return;
                    }
                    thread::sleep(Duration::from_millis(delay));
                    emit(
                        &worker_output,
                        &json!({"event":"progress", "job_id":job_id, "completed":step, "total":steps}),
                    );
                }
                emit(
                    &worker_output,
                    &json!({"event":"completed", "job_id":job_id}),
                );
            });
            continue;
        }

        let (response, shutdown) = handle_sync(&state, request);
        emit(&output, &response);
        if shutdown {
            break;
        }
    }
}

// ===== SNAPSMACK EOF =====
