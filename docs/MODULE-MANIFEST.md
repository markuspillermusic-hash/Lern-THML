# Modulmanifest

`module-manifest.json` ist der maschinenlesbare Vertrag eines LernHTML-Moduls.
Es verhindert, dass eine Seite zwar optisch fertig wirkt, aber zentrale
Funktionen, Rechteklärung oder Datenschutz fehlen.

Wesentliche Bereiche:

- `curriculum`: Lehrplan, Stand und Differenzierungsniveaus;
- `versions`: exakt gebundene gemeinsame Komponenten;
- `routes` und `build`: öffentliche Routen und lokale Artefakte;
- `classroom` und `presentation`: Funktions- und Sichtbarkeitsvertrag;
- `feedback.tasks`: registrierte Operator-, AFB- und Niveauzuordnung;
- `privacy` und `rights`: zwingende Veröffentlichungsbedingungen;
- `qa`: Tests, die vor einem Release ausgeführt werden müssen.

Prüfung während der Entwicklung:

```powershell
python tools/validate_module_manifest.py templates/module-starter/module-manifest.json --project-root templates/module-starter --allow-pending-rights
```

Prüfung eines gebauten, veröffentlichungsfähigen Moduls:

```powershell
python tools/validate_module_manifest.py pfad/module-manifest.json --project-root pfad --built
```

`rights.publicationStatus: pending` blockiert dabei absichtlich die öffentliche
Freigabe. `--allow-pending-rights` ist nur für lokale Entwicklung gedacht.
