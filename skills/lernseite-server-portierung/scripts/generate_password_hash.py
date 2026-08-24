from __future__ import annotations

import argparse
import getpass
import hashlib
import json
import secrets


def hash_password(password: str, salt: bytes, iterations: int) -> str:
    return hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, iterations).hex()


def main() -> int:
    parser = argparse.ArgumentParser(description="Erzeugt Salt und PBKDF2-Hash ohne Klartextdatei.")
    parser.add_argument("--iterations", type=int, default=310_000)
    parser.add_argument("--format", choices=("json", "php"), default="json")
    args = parser.parse_args()
    if args.iterations < 200_000:
        parser.error("Mindestens 200000 Iterationen verwenden.")

    password = getpass.getpass("Passwort: ")
    confirmation = getpass.getpass("Wiederholen: ")
    if password != confirmation:
        raise SystemExit("Passwörter stimmen nicht überein.")
    if len(password) < 12:
        raise SystemExit("Passwort muss mindestens 12 Zeichen lang sein.")

    salt = secrets.token_bytes(16)
    result = {
        "salt_hex": salt.hex(),
        "hash_hex": hash_password(password, salt, args.iterations),
        "iterations": args.iterations,
        "auth_version": secrets.token_hex(8),
    }
    password = ""
    confirmation = ""
    if args.format == "php":
        print(f"const PASSWORD_SALT = '{result['salt_hex']}';")
        print(f"const PASSWORD_HASH = '{result['hash_hex']}';")
        print(f"const PASSWORD_ITERATIONS = {result['iterations']};")
        print(f"const AUTH_VERSION = '{result['auth_version']}';")
    else:
        print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
