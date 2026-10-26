"""SNAP SLAPPER / LEWK AGAIN CLI over the real editor engine. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import json
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    sub.add_parser("lewks", help="List built-in LEWKs")
    p = sub.add_parser("lewk-validate"); p.add_argument("--input", required=True)
    p = sub.add_parser("lewk-save"); p.add_argument("--input", required=True); C.output_options(p)
    p = sub.add_parser("project-create"); p.add_argument("--image", required=True); C.output_options(p)
    for name in ("project-inspect", "project-edit", "apply-lewk", "render", "export"):
        p = sub.add_parser(name); p.add_argument("--project", required=True)
        p.add_argument("--trust-external-source", action="store_true")
        if name != "project-inspect":
            C.output_options(p)
        if name == "project-edit":
            p.add_argument("--input", required=True, help="JSON adjustment dictionary")
        if name == "apply-lewk":
            group = p.add_mutually_exclusive_group(required=True)
            group.add_argument("--builtin"); group.add_argument("--recipe")
            p.add_argument("--strength", type=int, default=100)
        if name == "render":
            p.add_argument("--size", type=int, default=1600)
        if name == "export":
            p.add_argument("--strip-gps", action="store_true")


def safe_lewk(value):
    import lewk_again
    if "layers" in value:
        layers = value["layers"]
        if not isinstance(layers, list) or any(not isinstance(x, dict) or x.get("type") not in {"adjustment", "filter"} for x in layers):
            raise ValueError("Assistant LEWKs currently support adjustment/filter layers only")
        if sum(x["type"] == "adjustment" for x in layers) != 1:
            raise ValueError("Expected exactly one adjustment layer")
        if any(x.get("mask") for x in layers):
            raise ValueError("Assistant LEWK masks are not supported")
        if any(x.get("opacity", 1) != 1 or x.get("blend", "normal") != "normal" or x.get("visible", True) is not True or x.get("styles") for x in layers):
            raise ValueError("This assistant recipe has layer properties the current LEWK importer cannot preserve")
        value = {"name": value.get("name"), "description": value.get("description", ""),
                 "explanation": value.get("explanation", []),
                 "adjustments": next(x["adjustments"] for x in layers if x["type"] == "adjustment"),
                 "filters": [{"type": x["filter_type"], "name": x.get("name"), "settings": x.get("settings", {})}
                             for x in layers if x["type"] == "filter"]}
    if set(value) - {"name", "description", "explanation", "adjustments", "filters"}:
        raise ValueError("Unknown assistant recipe field")
    return lewk_again.validate_response(json.dumps(value, allow_nan=False), "assistant", "external-assistant", "CLI import")


def execute(args):
    if args.command == "lewks":
        import built_in_lewks
        return {"items": built_in_lewks.all_lewks()}
    if args.command in {"lewk-validate", "lewk-save"}:
        recipe = safe_lewk(C.object_json(args.input))
        if args.command == "lewk-save":
            import snap_device_auth
            snap_device_auth.require_full("Saving LEWKs")
            C.atomic_json(C.output_path(args, args.input), recipe)
        return recipe
    import editor_engine as engine
    if args.command == "project-create":
        import snap_device_auth
        snap_device_auth.require_full("Creating editing projects")
        doc = engine.EditorDocument(args.image)
        path = C.output_path(args, args.image)
        doc.save_project(path)
        return {"project": path}
    doc = engine.EditorDocument.load_project(args.project, trust_external_source=args.trust_external_source)
    if args.command == "project-inspect":
        return doc.project_value()
    path = C.output_path(args, args.project, doc.source_path, getattr(doc, "recorded_source_path", None),
                         getattr(doc, "browse_source_path", None), getattr(args, "input", None), getattr(args, "recipe", None))
    if args.command in {"project-edit", "apply-lewk"}:
        import snap_device_auth
        snap_device_auth.require_full("Editing projects")
        if args.command == "project-edit":
            import lewk_again
            changes = C.object_json(args.input)
            normalized = lewk_again._safe_adjustments(changes)
            doc.adjustments.update({key: normalized[key] for key in changes})
            doc.record("CLI adjustments")
        else:
            if args.builtin:
                import built_in_lewks
                if not 0 <= args.strength <= 100:
                    raise ValueError("--strength must be between 0 and 100")
                recipe = built_in_lewks.recipe(args.builtin, args.strength)
            else:
                if args.strength != 100:
                    raise ValueError("--strength applies only to built-in LEWKs")
                recipe = safe_lewk(C.object_json(args.recipe))
            import texture_assets
            for layer in recipe["layers"]:
                if layer.get("type") == "image":
                    resolved = texture_assets.resolve(layer["asset_ref"])
                    if not resolved:
                        raise ValueError("Required texture is not installed; no automatic download was attempted")
                    layer["path"] = resolved
            doc.stack_layers(recipe["layers"])
        doc.save_project(path)
    elif args.command == "render":
        if not 64 <= args.size <= 4096:
            raise ValueError("Preview size must be between 64 and 4096")
        if Path(path).suffix.lower() != ".png":
            raise ValueError("Render output must be PNG")
        doc.render((args.size, args.size)).save(path, format="PNG")
    else:
        doc.export(path, strip_gps=args.strip_gps)
    return {"output": path, "layers": len(doc.layers)}


def main(argv=None):
    return C.run("slapper", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
