from __future__ import annotations

import argparse
import html
import json
import re
import shutil
from pathlib import Path


SIMPLE_NAME = re.compile(r"^[A-Za-z][A-Za-z0-9_]{2,63}$")
HEX_16 = re.compile(r"^[0-9a-fA-F]{32}$")
HEX_32 = re.compile(r"^[0-9a-fA-F]{64}$")
SAFE_SERVER_VALUE = re.compile(r"^[A-Za-z0-9_./:\-]+$")
SAFE_AUTH_VERSION = re.compile(r"^[A-Za-z0-9._-]{8,64}$")


def require_string(config: dict[str, object], name: str) -> str:
    value = config.get(name)
    if not isinstance(value, str) or not value:
        raise ValueError(f"{name} muss eine nichtleere Zeichenkette sein.")
    if any(character in value for character in ("\r", "\n", "\x00", "{{", "}}")):
        raise ValueError(f"{name} enthält unzulässige Steuerzeichen oder Platzhalter.")
    return value


def require_int(config: dict[str, object], name: str, minimum: int, maximum: int) -> int:
    value = config.get(name)
    if not isinstance(value, int) or isinstance(value, bool) or not minimum <= value <= maximum:
        raise ValueError(f"{name} muss zwischen {minimum} und {maximum} liegen.")
    return value


def php_single(value: str, name: str) -> str:
    if "'" in value or "\\" in value:
        raise ValueError(f"{name} darf weder Apostroph noch Backslash enthalten.")
    return value


def load_values(path: Path) -> dict[str, str]:
    raw = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise ValueError("Die Konfiguration muss ein JSON-Objekt sein.")
    if "password" in raw:
        raise ValueError("Kein Klartextpasswort in der Konfiguration speichern.")

    session_name = require_string(raw, "session_name")
    teacher_key = require_string(raw, "teacher_session_key")
    csrf_key = require_string(raw, "csrf_session_key")
    if not all(SIMPLE_NAME.fullmatch(value) for value in (session_name, teacher_key, csrf_key)):
        raise ValueError("Sessionname und Sessionkeys dürfen nur Buchstaben, Ziffern und Unterstriche enthalten.")

    salt = require_string(raw, "password_salt_hex")
    password_hash = require_string(raw, "password_hash_hex")
    if not HEX_16.fullmatch(salt) or not HEX_32.fullmatch(password_hash):
        raise ValueError("Salt und Hash mit generate_password_hash.py erzeugen und hexadezimal eintragen.")
    auth_version = require_string(raw, "auth_version")
    if not SAFE_AUTH_VERSION.fullmatch(auth_version):
        raise ValueError("auth_version muss 8 bis 64 sichere Zeichen enthalten und bei jeder Passwortrotation wechseln.")

    cookie_path = php_single(require_string(raw, "cookie_path"), "cookie_path")
    base_path = require_string(raw, "base_path").rstrip("/")
    if not cookie_path.startswith("/") or not cookie_path.endswith("/") or not base_path.startswith("/"):
        raise ValueError("cookie_path muss mit / beginnen und enden; base_path muss mit / beginnen.")
    if not SAFE_SERVER_VALUE.fullmatch(base_path):
        raise ValueError("base_path enthält unzulässige Zeichen.")

    data_directory = php_single(require_string(raw, "data_directory"), "data_directory")
    document_root = require_string(raw, "document_root").rstrip("/")
    fastcgi_pass = require_string(raw, "fastcgi_pass")
    if not data_directory.startswith("/") or not document_root.startswith("/"):
        raise ValueError("data_directory und document_root müssen absolute Linux-Pfade sein.")
    if not SAFE_SERVER_VALUE.fullmatch(document_root) or not SAFE_SERVER_VALUE.fullmatch(fastcgi_pass):
        raise ValueError("document_root oder fastcgi_pass enthält unzulässige Zeichen.")

    return {
        "SESSION_NAME": session_name,
        "TEACHER_SESSION_KEY": teacher_key,
        "CSRF_SESSION_KEY": csrf_key,
        "COOKIE_PATH": cookie_path,
        "PASSWORD_SALT_HEX": salt.lower(),
        "PASSWORD_HASH_HEX": password_hash.lower(),
        "PASSWORD_ITERATIONS": str(require_int(raw, "password_iterations", 200_000, 2_000_000)),
        "AUTH_VERSION": auth_version,
        "LOGIN_TITLE": html.escape(require_string(raw, "login_title"), quote=True),
        "LOGIN_DESCRIPTION": html.escape(require_string(raw, "login_description"), quote=True),
        "PUBLIC_URL": html.escape(require_string(raw, "public_url"), quote=True),
        "DATA_DIRECTORY": data_directory,
        "ROOM_TTL": str(require_int(raw, "room_ttl", 300, 7_776_000)),
        "MAX_HISTORY": str(require_int(raw, "max_history", 1, 100)),
        "BASE_PATH": base_path,
        "BASE_PATH_REGEX": re.escape(base_path),
        "DOCUMENT_ROOT": document_root,
        "FASTCGI_PASS": fastcgi_pass,
    }


def render(template: Path, values: dict[str, str]) -> str:
    text = template.read_text(encoding="utf-8")
    for name, value in values.items():
        text = text.replace("{{" + name + "}}", value)
    unresolved = sorted(set(re.findall(r"{{([A-Z0-9_]+)}}", text)))
    if unresolved:
        raise ValueError(f"Nicht ersetzte Platzhalter in {template.name}: {', '.join(unresolved)}")
    return text


def write_new(path: Path, content: str, force: bool) -> None:
    if path.exists() and not force:
        raise FileExistsError(f"{path} existiert bereits; --force nur im Buildordner verwenden.")
    path.write_text(content, encoding="utf-8", newline="\n")


def main() -> int:
    parser = argparse.ArgumentParser(description="Rendert generische Lernseiten-Servervorlagen in einen Buildordner.")
    parser.add_argument("config", type=Path, help="JSON-Konfiguration ohne Klartextpasswort")
    parser.add_argument("output", type=Path, help="Neuer oder ausdrücklich freigegebener Buildordner")
    parser.add_argument("--force", action="store_true", help="Nur generierte Dateien im Buildordner überschreiben")
    args = parser.parse_args()

    assets = Path(__file__).resolve().parent.parent / "assets"
    values = load_values(args.config.resolve())
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)

    rendered = {
        "teacher-auth-prefix.php": "teacher-auth-prefix.php.tpl",
        "live.php": "live-api.php.tpl",
        "nginx-locations.conf": "nginx-locations.conf.tpl",
    }
    for target_name, template_name in rendered.items():
        write_new(output / target_name, render(assets / template_name, values), args.force)
    for name in ("php-runtime-probe.php", "live-client.js", "live.css"):
        target = output / name
        if target.exists() and not args.force:
            raise FileExistsError(f"{target} existiert bereits; --force nur im Buildordner verwenden.")
        shutil.copy2(assets / name, target)

    print(f"Servermodul erzeugt: {output}")
    print("Klartextpasswort: nicht verarbeitet")
    print("Nächster Schritt: Lehrer-HTML an den Auth-Prefix anhängen und alle Dateien im Staging prüfen.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
