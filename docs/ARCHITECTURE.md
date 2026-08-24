# Architektur

## Quellen der Wahrheit

| Ebene | Quelle | Enthält |
|---|---|---|
| Inhalt | `src/author.html` eines Moduls | Texte, Aufgaben, Medien, semantische Präsentationsanker |
| Vertrag | `module-manifest.json` | Routen, Versionen, Niveaus, Feedbackaufgaben, Rechte- und QA-Status |
| Browserlaufzeit | `packages/classroom-v1/` | Räume, Abstimmungen, Kartenwand, Timer, Freigabe und Beamersteuerung |
| Serverplattform | geschützte Installation | Konten, Organisationen, Schlüssel, Quoten, Raumregister und Feedback-Gateway |
| Arbeitsweise | `skills/` | Didaktische und technische Entscheidungsregeln |

Lernseiten kopieren keine veränderliche Klassenraumlogik in neue Varianten.
Module binden eine konkrete Paketversion ein und deklarieren sie im Manifest.

## Rollen

- **Schüler:** Aufgaben bearbeiten, lokal speichern, Raum beitreten, nur
  freigegebene Inhalte sehen, optional KI-Feedback abrufen.
- **Lehrer:** Konto-geschützt Räume anlegen/verlängern/beenden, Inhalte
  freigeben, Live-Aktivitäten steuern und den Beamer führen.
- **Beamer:** rein präsentierende Ansicht ohne Schüler-Eingabefelder oder
  Erwartungshorizonte; zeigt beim Klassenraum-Manager immer Beitrittslink, Code
  und lokal erzeugten QR-Code.

## Präsentationsvertrag

Jeder sinnvolle Halt trägt eine stabile semantische ID, etwa
`data-beamer-anchor="analyse"`. Die Lehreransicht sendet nicht rohe
Scrollkoordinaten, sondern Ziel-ID plus relativen Offset. Eine Hysterese und
kurze Beruhigungszeit verhindern Hin-und-her-Springen. Gespiegelt werden
Hell/Dunkel, Details, Karten/Stepper, Medienstatus, Bilddialog und Timer.

Die Beameransicht begrenzt den sichtbaren Bereich am nächsten noch nicht
freigegebenen Hauptabschnitt. Diese Grenze ist eine Sicherheitsfunktion, kein
reines CSS-Detail.

## KI-Feedback

Der Browser sendet Modul-ID, registrierte Aufgaben-ID, Niveau und Schülertext an
das Feedback-Gateway. Dort werden Konto, Raum, Freigabe, Quote und Registry
geprüft. Operator, AFB, Materialgrundlage, Kriterien und Grenzen stammen nur aus
der serverseitigen Registry. Der Anbieter-Schlüssel wird erst dort entschlüsselt.
Die Antwort geht an den Browser zurück; der Text wird nicht in Raumdatei oder
Plattformdatenbank geschrieben.

## Versionsregel

- Patch: Fehlerkorrektur ohne Vertragsänderung.
- Minor: abwärtskompatibles Feature.
- Major: inkompatible Markup-, API- oder Zustandsänderung.

Ein Modul-Release dokumentiert die verwendeten Versionen im Manifest und
verwendet Cache-Busting über `build.assetVersion`.
