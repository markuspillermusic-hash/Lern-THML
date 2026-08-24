from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path


SECRET_PATTERNS = {
    "OpenAI/API-Schlüssel": re.compile(r"\bsk-[A-Za-z0-9_-]{20,}\b"),
    "Private Key": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    "Passwortzuweisung": re.compile(r"(?i)(?:password|passwd|api[_-]?key)\s*[:=]\s*['\"][^'\"]{8,}['\"]"),
}
TEXT_SUFFIXES = {".html", ".css", ".js", ".json", ".md", ".php", ".py", ".sh", ".yml", ".yaml", ".txt"}


def run(command: list[str], cwd: Path) -> None:
    print("+", " ".join(command))
    completed = subprocess.run(command, cwd=cwd)
    if completed.returncode:
        raise SystemExit(completed.returncode)


def secret_scan(root: Path) -> list[str]:
    findings: list[str] = []
    for path in root.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES or ".git" in path.parts or "dist" in path.parts:
            continue
        try:
            content = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for label, pattern in SECRET_PATTERNS.items():
            if pattern.search(content):
                findings.append(f"{path.relative_to(root)}: möglicher Fund ({label})")
    return findings


def role_contract(module: Path, manifest: dict) -> list[str]:
    problems: list[str] = []
    build = manifest["build"]
    files = {role: module / build[role] for role in ("student", "teacher", "beamer")}
    contents = {role: path.read_text(encoding="utf-8") for role, path in files.items() if path.is_file()}
    if len(contents) != 3:
        return ["Nicht alle drei Rollenartefakte existieren."]
    if 'data-rolle="lehrer"' in contents["student"] or 'data-rolle="lehrer"' in contents["beamer"]:
        problems.append("Lehrerblock ist in Schüler- oder Beamerartefakt enthalten.")
    for role, html in contents.items():
        if f'window.RELIGION_VIEW="{role}"' not in html:
            problems.append(f"{role}: Rollenkennung fehlt.")
        if "classroom-core.js" not in html or "presentation-core.js" not in html:
            problems.append(f"{role}: gemeinsame Laufzeit fehlt.")
    if "<textarea" in contents["beamer"]:
        problems.append("Beamer enthält ein Schüler-Textfeld.")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description="Lokales Freigabegate für ein LernHTML-Modul.")
    parser.add_argument("module", type=Path)
    parser.add_argument("--repo-root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--allow-pending-rights", action="store_true")
    args = parser.parse_args()
    module, repo = args.module.resolve(), args.repo_root.resolve()
    manifest_path = module / "module-manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    run([sys.executable, str(module / "tools" / "build_views.py"), "--project-root", str(module), "--runtime-root", str(repo / "packages" / "classroom-v1")], repo)
    command = [sys.executable, str(repo / "tools" / "validate_module_manifest.py"), str(manifest_path), "--project-root", str(module), "--built"]
    if args.allow_pending_rights: command.append("--allow-pending-rights")
    run(command, repo)
    problems = role_contract(module, manifest) + secret_scan(module)
    if problems:
        for problem in problems: print("FEHLER:", problem)
        return 1
    print("Release-Kernprüfung: BESTANDEN")
    print("Hinweis: echter Mehrbrowser-, Accessibility-, Backup- und Restore-Test bleibt zusätzlich erforderlich.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
