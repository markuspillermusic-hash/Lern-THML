# LernHTML-Kern

Fachübergreifende, versionierte Referenzarchitektur für interaktive LernHTMLs mit Schüler-,
Lehrer- und Beameransicht, mehrwöchigen Klassenräumen und optionalem,
operatorengestütztem KI-Feedback.

Das Repository trennt bewusst vier Ebenen:

1. **Autorenseite:** Inhalt und Aufgaben werden einmal gepflegt.
2. **Modulmanifest:** curriculumbezogene, technische und rechtliche Zusagen.
3. **Gemeinsame Laufzeit:** Klassenraum- und Präsentationslogik werden nicht pro
   Lernseite neu erfunden.
4. **Serverplattform:** Konten, Schlüssel, Räume, Quoten und Feedback bleiben
   serverseitig und außerhalb der HTML-Dateien.

## Schnellstart

```powershell
Copy-Item -Recurse templates/module-starter mein-modul
python mein-modul/tools/build_views.py --project-root mein-modul
python tools/validate_module_manifest.py mein-modul/module-manifest.json --project-root mein-modul --built
```

Der Starter nutzt absichtlich ein selbst erstelltes Musikbeispiel. Er zeigt,
dass die Architektur nicht an ein Fach gebunden ist. Der allgemeine Builder und
die Serverportierung liegen unter `skills/`; Fachprofile wie Religion werden in
eigenen Repositories versioniert.

## Verbindliche Regeln

- Schülertexte bleiben standardmäßig im Browser. Für KI-Feedback werden sie nur
  für den einzelnen Aufruf übertragen und nicht als Raumzustand gespeichert.
- OpenAI-Schlüssel liegen verschlüsselt in Lehrer- oder Organisationskonten,
  niemals in HTML, JavaScript, Raumdateien, URLs oder Browserstorage.
- Der Beamer zeigt nur freigegebene Inhalte und nie versehentlich den nächsten
  Hauptabschnitt.
- Öffentlich freigegebene Module benötigen ein geprüftes Rechteinventar.
- Änderungen an gemeinsamen Paketen erhalten eine neue SemVer-Version und
  werden nicht still in bestehenden Modulen überschrieben.

Weitere Einzelheiten: [Architektur](docs/ARCHITECTURE.md),
[Modulmanifest](docs/MODULE-MANIFEST.md), [Sicherheit](docs/SECURITY.md) und
[Freigabe](docs/RELEASE-CHECKLIST.md).

Die v1-Browser-API verwendet aus Rückwärtskompatibilität teilweise noch globale
Namen mit `RELIGION_`-Präfix. Das ist kein fachlicher Vertrag; neue Module
konfigurieren Fach und Inhalt ausschließlich über Manifest und Adapter.

## Status und Lizenz

Das Repository startet als Pilot. Eine allgemeine Nutzungslizenz ist noch nicht
festgelegt; bis zu einer bewussten Lizenzentscheidung gelten die gesetzlichen
Standardrechte. Die mitgelieferte QR-Bibliothek behält ihre eigene Lizenz in
`packages/classroom-v1/qrcode-generator-LICENSE.txt`.
