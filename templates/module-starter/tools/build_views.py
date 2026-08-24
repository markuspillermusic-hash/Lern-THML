from __future__ import annotations

import argparse
import json
import re
import shutil
from pathlib import Path


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'").replace("\r", "").replace("\n", "") + "'"


def role_config(manifest: dict, view: str) -> str:
    routes = manifest["routes"]
    stages = manifest["presentation"]["releaseStages"]
    payload = {
        "view": view,
        "moduleId": manifest["module"]["id"],
        "moduleLabel": manifest["module"]["title"],
        "api": routes["liveApi"],
        "feedbackEndpoint": routes["feedbackApi"],
        "studentUrl": routes["student"],
        "teacherUrl": routes["teacher"],
        "beamerUrl": routes["beamer"],
        "releaseStages": stages,
        "presentation": {"hideNextSection": True, "smoothFollow": True},
    }
    return "window.RELIGION_VIEW=" + json.dumps(view) + ";window.RELIGION_CLASSROOM_CONFIG=" + json.dumps(payload, ensure_ascii=False) + ";"


def transform(source: str, manifest: dict, view: str) -> str:
    html = source.replace("/*__ROLE_CONFIG__*/", role_config(manifest, view))
    html = html.replace("<body>", f'<body class="{view}-view">', 1)
    if view == "student":
        html = re.sub(r'<details[^>]*data-rolle="lehrer"[\s\S]*?</details>', "", html)
    elif view == "beamer":
        html = re.sub(r'<details[^>]*data-rolle="lehrer"[\s\S]*?</details>', "", html)
        html = re.sub(r'<label>[\s\S]*?<textarea[\s\S]*?</textarea>[\s\S]*?</label>', "", html)
        html = re.sub(r'<div[^>]*data-feedback-task[^>]*>\s*</div>', "", html)
    return html


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--project-root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--runtime-root", type=Path)
    parser.add_argument("--platform-bootstrap", default="/srv/teacher-platform-v1/app/bootstrap.php")
    args = parser.parse_args()
    root = args.project_root.resolve()
    manifest = json.loads((root / "module-manifest.json").read_text(encoding="utf-8"))
    source = (root / manifest["build"]["source"]).read_text(encoding="utf-8")
    output = root / manifest["build"]["output"]
    if args.runtime_root:
        runtime = args.runtime_root.resolve()
    else:
        candidates = [candidate / "packages" / "classroom-v1" for candidate in (root, *root.parents)]
        runtime = next((candidate for candidate in candidates if candidate.is_dir()), Path())
        if not runtime.is_dir():
            raise SystemExit("Gemeinsame Laufzeit nicht gefunden; --runtime-root angeben.")
    targets = {
        "student": root / manifest["build"]["student"],
        "teacher": root / manifest["build"]["teacher"],
        "beamer": root / manifest["build"]["beamer"],
    }
    for view, target in targets.items():
        target.parent.mkdir(parents=True, exist_ok=True)
        html = transform(source, manifest, view)
        relative_assets = "../assets/" if view == "teacher" else "assets/"
        if view == "teacher":
            html = html.replace('href="assets/', f'href="{relative_assets}').replace('src="assets/', f'src="{relative_assets}')
        target.write_text(html, encoding="utf-8", newline="\n")
    assets = output / "assets"
    assets.mkdir(parents=True, exist_ok=True)
    for own in (root / "assets").glob("*"):
        if own.is_file(): shutil.copy2(own, assets / own.name)
    for name in ("classroom-core.js", "presentation-core.js", "classroom.css", "feedback-client.js", "feedback.css", "qrcode-generator.js"):
        shutil.copy2(runtime / name, assets / name)
    api = root / manifest["build"]["liveApi"]
    api.parent.mkdir(parents=True, exist_ok=True)
    template = (root / "server" / "live-api.template.php").read_text(encoding="utf-8")
    replacements = {
        "__SESSION_NAME__": php_string(re.sub(r"[^a-z0-9_]", "_", manifest["module"]["slug"]) + "_teacher"),
        "__MODULE_SLUG__": php_string(manifest["module"]["slug"]),
        "__COOKIE_PATH__": php_string(manifest["routes"]["student"]),
        "__DATA_DIRECTORY__": php_string("/var/lib/teacher-platform/modules/" + manifest["module"]["slug"] + "/rooms"),
        "__PLATFORM_BOOTSTRAP__": php_string(args.platform_bootstrap),
        "__RELEASE_STAGES__": ", ".join(php_string(stage) for stage in manifest["presentation"]["releaseStages"]),
    }
    for token, value in replacements.items(): template = template.replace(token, value)
    api.write_text(template, encoding="utf-8", newline="\n")
    print(f"Rollen-Build erzeugt: {output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
