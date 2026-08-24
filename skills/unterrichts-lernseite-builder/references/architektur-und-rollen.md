# Architektur und Rollen

## Eine Quelle, drei Artefakte

Inhalt nur in einer Autorenquelle pflegen. Lehrerblöcke mit
`data-rolle="lehrer"`, Präsentationshalte mit stabilen IDs und Hauptphasen mit
Freigabestufen markieren. Der Build erzeugt:

- Schüler: Aufgaben, lokale Eingaben, Raumbeitritt, nur freigegebene Phasen;
- Lehrer: alle Inhalte, Erwartungshorizonte, Raum- und Präsentationssteuerung;
- Beamer: präsentierbare Materialien ohne Eingaben und vertrauliche Inhalte.

Lehrerinhalt muss aus öffentlichen Artefakten physisch entfernt werden.

## Modulmanifest

Vor dem Ausbau ein `module-manifest.json` nach dem mitgelieferten Schema führen.
Es registriert Modul-ID, Lehrplan, Niveaus, Versionen, Rollenrouten,
Klassenraumfunktionen, Freigabestufen, KI-Aufgaben, Datenschutz, Rechteinventar
und notwendige QA. `scripts/validate_module_manifest.py` vor jedem Release
ausführen. Offener Rechtestatus blockiert die öffentliche Freigabe.

## Lokale Schülerarbeit

Textfelder, Zeichnungen, Auswahl und Selbstchecks standardmäßig lokal speichern.
Speicherschlüssel aus Modul-ID, Inhaltsversion und Feld-ID bilden. Export und
Import als versionierte portable Datei anbieten. Gerätewechsel ohne Export darf
nicht als synchron behauptet werden. Raumzustand enthält keine freien privaten
Lerntexte.

## Komponenten

Native HTML-Elemente bevorzugen. Jede Zusatzkomponente braucht Tastaturbedienung,
Fokuszustand, ARIA-Name, mobilen Umbruch und sinnvolle Druckdarstellung. Keine
verschachtelten Karten oder unnötigen Leerflächen. Textbreite typischerweise auf
65–80 Zeichen begrenzen, während Medien und Tabellen die Inhaltsbreite nutzen
dürfen.
