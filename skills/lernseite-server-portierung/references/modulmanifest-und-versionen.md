# Modulmanifest und Versionen

Vor einer produktiven Portierung muss das Modul seinen Vertrag in
`module-manifest.json` deklarieren: Modul-ID, Lehrplan/Niveaus, Schüler-, Lehrer-
und Beamerroute, Live-/Feedbackendpunkt, lokale Buildartefakte, Klassenraumdauer,
Freigabestufen, Beamer-Spiegelung, Feedback-Aufgaben, Datenschutzroute,
Rechteinventar und QA-Gates.

`scripts/validate_module_manifest.py` zuerst gegen die Quelle und nach dem Build
mit `--built` ausführen. Offene Rechte blockieren das öffentliche Release.

Gemeinsame Pakete nach SemVer binden. Klassenraum- oder Präsentationsdateien
nicht still überschreiben: neue Paketversion, Changelog/Testnachweis,
Cache-Busting und kontrollierter Rollout. Vorhandene Module dürfen auf ihrer
geprüften Version bleiben, bis sie bewusst migriert werden.

Das öffentliche Repository enthält ausschließlich geheimnisfreien Code,
Beispiele, Schema und Skills. Produktionskonfiguration, Schlüssel, Datenbanken,
Raumdateien, Backups, private QA-Aufnahmen und ungeklärte Unterrichtsmaterialien
bleiben außerhalb.
