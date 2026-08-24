from __future__ import annotations

import argparse
import hashlib
from pathlib import Path


FILES = ("classroom-core.js", "presentation-core.js", "classroom.css", "feedback-client.js", "feedback.css", "qrcode-generator.js")


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description="Vergleicht gebündelte LernHTML-Laufzeitdateien mit einer Referenzversion.")
    parser.add_argument("reference", type=Path)
    parser.add_argument("bundle", type=Path)
    args = parser.parse_args()
    failed = False
    for name in FILES:
        left, right = args.reference / name, args.bundle / name
        if not left.is_file() or not right.is_file():
            print(f"FEHLT {name}")
            failed = True
        elif digest(left) != digest(right):
            print(f"ABWEICHUNG {name}")
            failed = True
        else:
            print(f"OK {name}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
