from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any


SEMVER = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$")
SLUG = re.compile(r"^[a-z0-9][a-z0-9._-]{2,99}$")
WEB_PATH = re.compile(r"^/[^?#]*$")


def load_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def find_schema(script: Path) -> Path | None:
    candidates = [
        script.parent.parent / "schemas" / "module-manifest.schema.json",
        script.parent.parent / "assets" / "module-manifest.schema.json",
    ]
    return next((path for path in candidates if path.is_file()), None)


def nested(data: dict[str, Any], *keys: str) -> Any:
    value: Any = data
    for key in keys:
        if not isinstance(value, dict) or key not in value:
            return None
        value = value[key]
    return value


def manual_checks(data: Any, project_root: Path | None, built: bool) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    if not isinstance(data, dict):
        return ["Manifestwurzel muss ein JSON-Objekt sein."], warnings

    required = ["schemaVersion", "module", "curriculum", "versions", "routes", "build", "classroom", "presentation", "feedback", "privacy", "rights", "qa"]
    for key in required:
        if key not in data:
            errors.append(f"Pflichtfeld fehlt: {key}")

    if data.get("schemaVersion") != "1.0.0":
        errors.append("schemaVersion muss 1.0.0 sein.")

    module_id = nested(data, "module", "id")
    module_slug = nested(data, "module", "slug")
    for label, value in (("module.id", module_id), ("module.slug", module_slug)):
        if not isinstance(value, str) or not SLUG.fullmatch(value):
            errors.append(f"{label} ist keine stabile kleingeschriebene ID.")

    tracks = nested(data, "curriculum", "tracks")
    if not isinstance(tracks, list) or not tracks or len(tracks) != len(set(map(str, tracks))):
        errors.append("curriculum.tracks muss mindestens eine eindeutige Niveaubezeichnung enthalten.")

    versions = data.get("versions") if isinstance(data.get("versions"), dict) else {}
    for key in ("content", "classroomCore", "presentationCore", "teacherPlatform", "moduleAdapter"):
        value = versions.get(key)
        if not isinstance(value, str) or not SEMVER.fullmatch(value):
            errors.append(f"versions.{key} muss eine SemVer-Version sein.")

    routes = data.get("routes") if isinstance(data.get("routes"), dict) else {}
    for key in ("student", "teacher", "beamer"):
        value = routes.get(key)
        if not isinstance(value, str) or not WEB_PATH.fullmatch(value):
            errors.append(f"routes.{key} muss ein relativer Webpfad mit führendem / sein.")
    role_routes = [routes.get(key) for key in ("student", "teacher", "beamer")]
    if all(isinstance(value, str) for value in role_routes) and len(set(role_routes)) != 3:
        errors.append("Schüler-, Lehrer- und Beamerroute müssen verschieden sein.")

    classroom = data.get("classroom") if isinstance(data.get("classroom"), dict) else {}
    if classroom.get("enabled") is True:
        if classroom.get("persistentStorage") is not True:
            errors.append("Aktive Klassenräume benötigen persistente Speicherung außerhalb eines Tempordners.")
        if not routes.get("liveApi"):
            errors.append("Aktiver Klassenraum benötigt routes.liveApi.")
        lifetime = classroom.get("roomLifetime") if isinstance(classroom.get("roomLifetime"), dict) else {}
        default_days, maximum_days = lifetime.get("defaultDays"), lifetime.get("maximumDays")
        if not isinstance(default_days, int) or not isinstance(maximum_days, int) or not 1 <= default_days <= maximum_days <= 365:
            errors.append("classroom.roomLifetime muss 1 ≤ defaultDays ≤ maximumDays ≤ 365 erfüllen.")

    presentation = data.get("presentation") if isinstance(data.get("presentation"), dict) else {}
    if presentation.get("enabled") is True:
        if presentation.get("hideNextSection") is not True:
            errors.append("Die Beameransicht muss den nächsten Hauptabschnitt ausblenden.")
        if presentation.get("smoothFollow") is not True:
            errors.append("Die Beameransicht muss geglättetes semantisches Folgen verwenden.")
        stages = presentation.get("releaseStages")
        if not isinstance(stages, list) or len(stages) < 2 or len(stages) != len(set(map(str, stages))):
            errors.append("presentation.releaseStages benötigt mindestens zwei eindeutige Stufen.")

    feedback = data.get("feedback") if isinstance(data.get("feedback"), dict) else {}
    tasks = feedback.get("tasks")
    if not isinstance(tasks, list):
        errors.append("feedback.tasks muss eine Liste sein.")
        tasks = []
    if feedback.get("enabled") is True:
        if not feedback.get("endpoint") or not feedback.get("registry") or not tasks:
            errors.append("Aktives KI-Feedback benötigt Endpoint, serverseitige Registry und mindestens eine Aufgabe.")
        if feedback.get("storesStudentAnswers") is not False:
            errors.append("KI-Feedback darf Schülerantworten nicht serverseitig speichern.")
    task_ids: list[str] = []
    for index, task in enumerate(tasks):
        if not isinstance(task, dict):
            errors.append(f"feedback.tasks[{index}] muss ein Objekt sein.")
            continue
        task_id = task.get("id")
        if not isinstance(task_id, str) or not SLUG.fullmatch(task_id):
            errors.append(f"feedback.tasks[{index}].id ist ungültig.")
        else:
            task_ids.append(task_id)
        if task.get("afb") not in ("I", "II", "III"):
            errors.append(f"feedback.tasks[{index}].afb muss I, II oder III sein.")
        if task.get("track") not in (tracks or []):
            errors.append(f"feedback.tasks[{index}].track ist nicht in curriculum.tracks registriert.")
    if len(task_ids) != len(set(task_ids)):
        errors.append("Feedback-Aufgaben-IDs müssen eindeutig sein.")

    privacy = data.get("privacy") if isinstance(data.get("privacy"), dict) else {}
    if privacy.get("localStudentWork") is not True or privacy.get("serverStoresStudentAnswers") is not False:
        errors.append("Private Lerntexte müssen lokal bleiben und dürfen nicht serverseitig persistiert werden.")
    notice = privacy.get("noticeUrl")
    if not isinstance(notice, str) or not WEB_PATH.fullmatch(notice):
        errors.append("privacy.noticeUrl muss eine interne, dauerhaft erreichbare Route sein.")

    rights = data.get("rights") if isinstance(data.get("rights"), dict) else {}
    status = rights.get("publicationStatus")
    if status not in ("cleared", "restricted", "pending"):
        errors.append("rights.publicationStatus muss cleared, restricted oder pending sein.")
    elif status == "pending":
        warnings.append("Rechteprüfung ist offen: keine unbeschränkte öffentliche Veröffentlichung.")

    qa = data.get("qa") if isinstance(data.get("qa"), dict) else {}
    required_checks = set(qa.get("requiredChecks") or [])
    expected = {"manifest", "learningHtml", "viewBundle", "rights"}
    if classroom.get("enabled") is True:
        expected |= {"classroomContract", "liveBrowser", "hashes", "backupRestore"}
    missing_checks = sorted(expected - required_checks)
    if missing_checks:
        errors.append("qa.requiredChecks fehlen: " + ", ".join(missing_checks))

    if project_root:
        build = data.get("build") if isinstance(data.get("build"), dict) else {}
        local_fields = ["source"] + (["student", "teacher", "beamer", "liveApi"] if built else [])
        for key in local_fields:
            value = build.get(key)
            if value and not (project_root / str(value)).is_file():
                errors.append(f"Builddatei fehlt: {key} → {project_root / str(value)}")
        inventory = rights.get("inventory")
        if inventory and not (project_root / str(inventory)).is_file():
            errors.append(f"Rechteinventar fehlt: {project_root / str(inventory)}")

    return errors, warnings


def main() -> int:
    parser = argparse.ArgumentParser(description="Validiert das LernHTML-Modulmanifest und seinen Veröffentlichungsvertrag.")
    parser.add_argument("manifest", type=Path)
    parser.add_argument("--schema", type=Path)
    parser.add_argument("--project-root", type=Path)
    parser.add_argument("--built", action="store_true", help="Zusätzlich erzeugte Rollen- und API-Dateien prüfen.")
    parser.add_argument("--allow-pending-rights", action="store_true", help="Offene Rechte nur als Warnung behandeln.")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    manifest = args.manifest.resolve()
    if not manifest.is_file():
        print(f"FEHLER: Manifest fehlt: {manifest}")
        return 1
    try:
        data = load_json(manifest)
    except (OSError, json.JSONDecodeError) as exc:
        print(f"FEHLER: Manifest kann nicht gelesen werden: {exc}")
        return 1

    errors, warnings = manual_checks(data, args.project_root.resolve() if args.project_root else None, args.built)
    schema = args.schema.resolve() if args.schema else find_schema(Path(__file__).resolve())
    schema_checked = False
    if schema and schema.is_file():
        try:
            import jsonschema  # type: ignore

            jsonschema.Draft202012Validator(load_json(schema)).validate(data)
            schema_checked = True
        except ModuleNotFoundError:
            warnings.append("Python-Paket jsonschema fehlt; semantische Kernprüfung wurde trotzdem ausgeführt.")
        except Exception as exc:  # jsonschema exposes several validation exception types
            errors.append(f"JSON-Schema: {exc}")
    else:
        warnings.append("JSON-Schema nicht gefunden; semantische Kernprüfung wurde trotzdem ausgeführt.")

    if nested(data, "rights", "publicationStatus") == "pending" and not args.allow_pending_rights:
        errors.append("Öffentliche Freigabe blockiert: rights.publicationStatus ist pending.")

    report = {
        "manifest": str(manifest),
        "module": nested(data, "module", "slug"),
        "schema_checked": schema_checked,
        "warnings": warnings,
        "errors": errors,
        "ok": not errors,
    }
    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print("Modulmanifest: " + ("BESTANDEN" if report["ok"] else "NICHT BESTANDEN"))
        for warning in warnings:
            print("WARNUNG: " + warning)
        for error in errors:
            print("FEHLER: " + error)
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
