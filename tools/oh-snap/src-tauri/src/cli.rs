// SNAPSMACK_EOF_HEADER: native headless operations; never start a webview.
use serde_json::{json, Value};
use std::{fs, path::Path};

fn operation(args: &[String]) -> Result<Value, String> {
    let command = args.first().map(String::as_str).unwrap_or("capabilities");
    let option = |name: &str| -> Result<String, String> {
        args.windows(2).find(|pair| pair[0] == name).map(|pair| pair[1].clone())
            .ok_or_else(|| format!("Missing {name}"))
    };
    if command == "capabilities" {
        return Ok(json!({"commands":["capabilities","project-inspect","package-validate","package-export"],
            "paid_ai_fallback":false,"result_file":true,
            "design_cli":"Full design/render CLI: node tools/oh-snap/scripts/cli.cjs --help"}));
    }
    let input = option(if command == "project-inspect" { "--project" } else { "--input" })?;
    if fs::metadata(&input).map_err(|e| e.to_string())?.len() > 25_000_000 {
        return Err("CLI input exceeds 25 MB".into());
    }
    let text = fs::read_to_string(&input).map_err(|e| e.to_string())?;
    if command == "project-inspect" {
        let project: Value = serde_json::from_str(&text).map_err(|e| e.to_string())?;
        if !project.is_object() { return Err("Project must be a JSON object".into()); }
        return Ok(project);
    }
    if !["package-validate", "package-export"].contains(&command) {
        return Err(format!("Unknown CLI command: {command}"));
    }
    let files: Vec<super::PackageFile> = serde_json::from_str(&text).map_err(|e| e.to_string())?;
    super::validate_package_files(&files)?;
    if command == "package-validate" { return Ok(json!({"valid":true,"file_count":files.len()})); }
    let output = option("--output")?;
    if Path::new(&output).exists() { return Err("Output exists; choose a new package path".into()); }
    super::export_shareable_package(output.clone(), files)?;
    Ok(json!({"output":output}))
}

pub fn run(args: &[String]) -> i32 {
    let result = args.windows(2).find(|pair| pair[0] == "--result").map(|pair| pair[1].clone());
    // Refuse result/input aliases before emitting anything to disk.
    if let Some(ref target) = result {
        for pair in args.windows(2).filter(|pair| ["--input", "--project", "--output"].contains(&pair[0].as_str())) {
            let identity = |value: &str| -> std::io::Result<std::path::PathBuf> {
                let absolute = if Path::new(value).is_absolute() { Path::new(value).to_path_buf() }
                    else { std::env::current_dir().unwrap_or_default().join(value) };
                fs::canonicalize(&absolute).or_else(|_| {
                    let parent = absolute.parent().ok_or_else(|| std::io::Error::other("No parent"))?;
                    Ok(fs::canonicalize(parent)?.join(absolute.file_name().unwrap_or_default()))
                })
            };
            let same = target == &pair[1] || match (identity(target), identity(&pair[1])) {
                (Ok(a), Ok(b)) => a == b,
                _ => false,
            };
            if same { eprintln!("--result must differ from all input/output files"); return 2; }
        }
    }
    let (envelope, code) = match operation(args) {
        Ok(data) => (json!({"ok":true,"protocol":1,"app":"ohsnap","data":data}), 0),
        Err(message) => (json!({"ok":false,"protocol":1,"app":"ohsnap","error":{"code":"operation","message":message}}), 2),
    };
    let text = serde_json::to_string_pretty(&envelope).unwrap();
    if let Some(target) = result {
        if let Err(error) = super::save_project(target, text) { eprintln!("{error}"); return 2; }
    } else { println!("{text}"); }
    code
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn capabilities_without_webview() {
        assert_eq!(operation(&["capabilities".into()]).unwrap()["paid_ai_fallback"], false);
        assert!(operation(&["package-export".into()]).is_err());
    }
}
// ===== SNAPSMACK EOF =====
