from __future__ import annotations

import argparse
import re
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description="Prüft Struktur und lokale Verweise eines Codex-Skills.")
    parser.add_argument("skill", type=Path)
    args = parser.parse_args()
    root = args.skill.resolve()
    main_file = root / "SKILL.md"
    errors: list[str] = []
    if not main_file.is_file():
        print("FEHLER: SKILL.md fehlt.")
        return 1
    text = main_file.read_text(encoding="utf-8")
    if not text.startswith("---\n") or "\n---\n" not in text[4:]:
        errors.append("YAML-Frontmatter fehlt oder ist nicht abgeschlossen.")
    else:
        front = text.split("\n---\n", 1)[0][4:]
        fields = {}
        for line in front.splitlines():
            if ":" in line:
                key, value = line.split(":", 1)
                fields[key.strip()] = value.strip()
        expected = root.name
        if fields.get("name") != expected:
            errors.append(f"name muss dem Ordnernamen entsprechen: {expected}")
        if len(fields.get("description", "")) < 40:
            errors.append("description ist zu kurz oder fehlt.")
        unknown = set(fields) - {"name", "description"}
        if unknown:
            errors.append("Unbekannte Frontmatter-Felder: " + ", ".join(sorted(unknown)))
    for target in re.findall(r"\[[^\]]+\]\(([^)]+)\)", text):
        if "://" in target or target.startswith("#"):
            continue
        path = (root / target.split("#", 1)[0]).resolve()
        if not path.is_file():
            errors.append(f"Verweisziel fehlt: {target}")
    for error in errors:
        print("FEHLER:", error)
    if errors:
        return 1
    print(f"Skill: BESTANDEN ({root.name})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
