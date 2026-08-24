# Architektur und Ansichten

## Zielbaum

Ein bewährtes Muster für eine bestehende Seite `einheit.html`:

```text
bereich/
├── einheit.html                         öffentliche Schüleransicht
├── datenschutz.html                     technische Speicherinformation
├── medien/                              vorhandener gemeinsamer Medienordner
└── einheit/
    ├── live.css
    ├── live.js
    ├── beamer/index.html
    ├── lehrer/index.php
    └── api/live.php
```

Bestehende Namenskonventionen vor diesem Muster priorisieren. Keine neue Bereichsstartseite erzeugen, wenn der Auftrag nur eine Unterseite betrifft.

## Schüleransicht

- Unter der bisherigen URL ersetzen, wenn ausdrücklich beauftragt.
- Alle `data-rolle="lehrer"`-Elemente im Build entfernen.
- Lokale Mitschrift, Selbstcheck, Import/Export und Teilnahme an Live-Abstimmungen anbieten.
- Kein Login verlangen, solange keine personenbezogene Synchronisation erfolgt.
- Nutzt die Seite eine Schrittnummerierung für die Beamerkopplung, muss diese die Trennung in getrennte Dateien überstehen. Entweder vor dem Entfernen der Lehrkraftblöcke nummerieren und die Nummern fest ins Markup schreiben, oder die Nummerierung so ableiten, dass sie in allen Ansichten identisch ist. Wird erst nach dem Entfernen nummeriert, verschieben sich die Nummern und die Fernsteuerung springt an die falsche Stelle.
- Bei geführtem Unterricht direkt am Seitenanfang einen Raumbeitritt anbieten und spätere Abschnitte anhand der serverseitigen Freigabestufe sperren. Ohne Raum einen bewusst benannten Selbstlernmodus zulassen, wenn fachlich gewünscht.
- Aktive Live-Aktivitäten am jeweiligen fachlichen Anker rendern; ein schwebender Launcher ist nur Ergänzung.

## Lehreransicht

- Separates serverseitiges Dokument ausliefern.
- Login vor der HTML-Ausgabe prüfen.
- Unterrichtsskript, Lösungen, Vorbereitungswerkzeuge und Live-Dashboard enthalten.
- Nur eine Hürde für Unterrichtsmaterial versprechen, keine Schülerdaten-Sicherheitszone.
- Vorbereitung einklappbar halten und sichtbar in didaktische Auswahl, technische Raumvorbereitung und konkrete Durchführung gliedern.
- Mehrere vorbereitete Räume auf dem Lehrgerät verwalten; Räume und Aktivitäten nicht vermischen.

## Beameransicht

- Ohne Passwort zugänglich machen, wenn sie keine geschützten Inhalte enthält.
- Lehrkraftinhalte physisch entfernen.
- Eingabefelder und lokale Mitschrift ausblenden; ruhige Kopfzeile und große Ergebnisdarstellung verwenden.
- Raumcode manuell oder über lokalen Browserzustand verbinden.
- Bei Raumbeitritt und jeder aktiven Aktivität Code und QR groß zeigen. Ergebnisdarstellungen auf typische Beamerformate einpassen und nicht unter einer festen Steuerleiste abschneiden.
- Medien-, Timer- und Schaubildzustände von der Lehreransicht übernehmen; keine zweite hörbare Videoquelle starten.

## Entwurf versus Produktion

Eine einzelne Autoren-HTML darf alle Rollen enthalten. Der Produktionsbuild muss daraus getrennte Dateien erzeugen. Nicht direkt die Autorenquelle so verändern, dass weitere Bearbeitung unmöglich wird.

Voll- und Kompaktmodus dürfen ebenfalls aus dieser Quelle entstehen. Alternative DOM-Bäume in inerten `<template>`-Elementen halten, beim Start genau einen Modus instanziieren und danach aktive IDs sowie Ereignisbindungen im Browser prüfen.

## Zustandsgrenzen

- **Lernstand:** lokal pro Schülerbrowser, exportierbar und versioniert.
- **Unterrichtsraum:** serverseitig, anonym, befristet und pro Raumdatei gesperrt.
- **Lehrer–Beamer-Steuerung:** lokal zwischen Fenstern; keine sensiblen Daten und keine Abhängigkeit vom `window.open`-Handle.
- **Vorbereitung:** lokal auf dem Lehrgerät und nur notwendige Konfiguration im geöffneten Raum.

Ein Raumcode verbindet alle gemeinsamen Zustände einer Stunde. Einen zusätzlichen Abstimmungs- oder Timercode vermeiden.

## URL-Konfiguration

Routen in einem kleinen Konfigurationsblock setzen:

```html
<script>
window.LERNSEITE_VIEW = "student";
window.LERNSEITE_LIVE_API = "/bereich/einheit/api/live.php";
window.LERNSEITE_BEAMER_URL = "/bereich/einheit/beamer/";
window.LERNSEITE_POLLS = [/* projektspezifische Fragen */];
</script>
```

Für Beamer und Lehrer relative Assetpfade neu berechnen. Absolute API-Routen bevorzugen, wenn verschachtelte Ansichten sonst uneindeutig werden.

Statische Assets und ersetzte Medien versionieren. Beim Ermitteln lokaler Dateinamen Query und Fragment entfernen, damit etwa `video.mp4?v=2` weiterhin auf `video.mp4` im Medienmanifest zeigt.
