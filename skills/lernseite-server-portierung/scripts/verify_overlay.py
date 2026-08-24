from __future__ import annotations

import argparse
import fnmatch
import hashlib
import json
from pathlib import Path


def digest(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            hasher.update(block)
    return hasher.hexdigest()


def files(root: Path) -> dict[str, Path]:
    return {path.relative_to(root).as_posix(): path for path in root.rglob("*") if path.is_file()}


def allowed(relative: str, patterns: list[str]) -> bool:
    return any(fnmatch.fnmatch(relative, pattern.replace("\\", "/")) for pattern in patterns)


def main() -> int:
    parser = argparse.ArgumentParser(description="Prüft Deployment-Overlay und unveränderten Bestand per SHA-256.")
    parser.add_argument("overlay", type=Path)
    parser.add_argument("target", type=Path)
    parser.add_argument("--backup", type=Path)
    parser.add_argument("--allow-changed", action="append", default=[])
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    overlay = args.overlay.resolve()
    target = args.target.resolve()
    if not overlay.is_dir() or not target.is_dir():
        raise SystemExit("Overlay und Ziel müssen vorhandene Verzeichnisse sein.")

    overlay_mismatch: list[str] = []
    for relative, source in files(overlay).items():
        destination = target / relative
        if not destination.is_file():
            overlay_mismatch.append(relative + " (fehlt)")
        elif digest(source) != digest(destination):
            overlay_mismatch.append(relative + " (Hash)")

    protected_changes: list[str] = []
    backup_count = 0
    if args.backup:
        backup = args.backup.resolve()
        if not backup.is_dir():
            raise SystemExit("Backupverzeichnis fehlt.")
        for relative, old_file in files(backup).items():
            if allowed(relative, args.allow_changed):
                continue
            backup_count += 1
            current = target / relative
            if not current.is_file():
                protected_changes.append(relative + " (fehlt)")
            elif digest(old_file) != digest(current):
                protected_changes.append(relative + " (geändert)")

    report = {
        "overlay_files": len(files(overlay)),
        "overlay_mismatch": overlay_mismatch,
        "protected_files_checked": backup_count,
        "protected_changes": protected_changes,
        "ok": not overlay_mismatch and not protected_changes,
    }
    if args.json:
        print(json.dumps(report, indent=2, ensure_ascii=False))
    else:
        print(f"Overlaydateien: {report['overlay_files']}")
        print(f"Geschützte Bestandsdateien geprüft: {backup_count}")
        for item in overlay_mismatch:
            print("OVERLAY-FEHLER: " + item)
        for item in protected_changes:
            print("BESTANDSÄNDERUNG: " + item)
        print("ERGEBNIS: " + ("BESTANDEN" if report["ok"] else "NICHT BESTANDEN"))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
