# Anonyme Live-Abstimmungen

## Datenmodell

Pro Raum nur speichern:

- zufälliger sechsstelliger Raumcode
- optionale Raumbezeichnung ohne Namen
- Erstell- und Ablaufzeit; das gewählte Ablaufdatum ist nach Lehrerautorisierung innerhalb einer festen Mindest-/Höchstfrist änderbar
- aktuelle Frage, Antwortoptionen und Summenzähler
- aktuelle Freigabestufe und optionalen gemeinsamen Timer
- begrenzter Verlauf abgeschlossener Summen

Keine Namen, Konten, Klassenlistennummern, Gerätekennungen oder individuellen Antwortdatensätze anlegen. Technische Serverlogs separat bewerten.

## Standardverhalten

- Raumdauer am Unterrichtsvorhaben ausrichten: kurze Einzelstunde wenige Stunden, mehrwöchige Lernsequenz standardmäßig 42 Tage. Ablaufdatum sichtbar machen und bewusstes vorzeitiges Beenden ermöglichen.
- Verlauf auf höchstens 20 Fragen begrenzen.
- Ergebnisse in Schüler- und Beameransicht bis zur Freigabe verbergen.
- Lehrkraft darf live summierte Werte sehen.
- Raumende löscht die Raumdatei unmittelbar.
- CSV und druckbaren HTML-Bericht clientseitig aus Aggregaten erzeugen.
- CSV-Zellen gegen Formelinterpretation in Tabellenprogrammen absichern.

## Raum und Aktivität trennen

- Beim Vorbereiten nur Raum, neutrale Bezeichnung, Ablauf-/Sozialformkonfiguration und optionale Lehrkraftlinks speichern.
- Keine Frage beim Erstellen des Raums verlangen. Die Lehrkraft startet sie erst am fachlich passenden Inline-Anker.
- Einen Raumcode für Abstimmungen, Timer und Abschnittsfreigabe verwenden. Code und QR in Schülerlink, Beamer-Beitritt und aktiver Aktivität konsistent halten.
- Mehrere offene Räume auf dem Lehrgerät lokal merken. Die API muss keinen globalen Raumindex mit allen Lehrkräften veröffentlichen.

## Gemeinsame Kartenwände

- Kartenwandzustände als begrenzte Teilstruktur des Raums speichern: Wand-ID, geöffnet/geschlossen und kurze Karten mit Kategorie, Status und Erstellzeit.
- Öffentliche Einreichungen starten als `pending`; der öffentliche Status filtert sie vollständig aus. Nur die Lehrerantwort enthält die Moderationswarteschlange.
- Ausschließlich konfigurierte Wand- und Kategorie-IDs akzeptieren. Textlänge, Gesamtzahl und Offenstatus serverseitig prüfen.
- Freigeben, Verwerfen, Öffnen/Schließen und Leeren erfordern Lehrerautorisierung und laufen unter demselben Raum-Lock.
- Keine Namen, Absender-IDs oder individuellen Verlaufsprofile hinzufügen. Mit dem Raum werden alle Karten gelöscht.

## Ergebnisstrategie und Antwortänderung

- Für jede Aktivität vor dem Start `store` (zunächst verbergen) oder `beamer` (direkt zeigen) wählen.
- Vor der Freigabe öffentliche `counts`, richtige Indizes und interne Gesprächsimpulse entfernen, nicht nur im CSS verstecken.
- Lernende dürfen ihre Auswahl bis zum Schließen ändern. Vorherige Auswahl nur transient mitsenden; unter exklusivem Lock alten Zähler vermindern und neuen erhöhen.
- Clientmarkierungen in `sessionStorage` sind Komfortzustand, keine Identität. Negative Summen serverseitig verhindern.
- Ohne Gerätekennung kann der Server nicht beweisen, dass eine gemeldete vorherige Auswahl wirklich zu diesem Browser gehörte. Antwortänderung deshalb ausdrücklich nur als niedrigschwellige Unterrichtsfunktion, nie als manipulationssichere Abstimmung behandeln.
- Eine private Begründung im Lernstand des Schülers sichern und niemals zusammen mit der anonymen Stimme hochladen.

## Mehrteiliger Klassencheck

- Alle Fragen und Antwortmöglichkeiten in einem `start_quiz`-Vorgang öffnen und einen gemeinsamen Timer starten.
- Lernende beantworten alles in einer Oberfläche und senden genau eine vollständige Antwortliste. Aktualisierung vor Ablauf zulassen.
- Nur Summen je Frage/Option und Zahl vollständiger Abgaben speichern; keine Antwortvektoren persistieren.
- Nach Ablauf serverseitig schließen. Beamer zeigt nach Freigabe eine kompakte Aufgabenübersicht, Lehreransicht zusätzlich aufklappbare Verteilungen und Erwartungshorizonte.

## Parallelunterricht

Jede Lehrersession erstellt einen eigenen kryptografisch zufälligen Raumcode. Raumzustände in getrennten, gesperrten Dateien führen. Aktualisierungen mit exklusivem File-Lock durchführen. Zwei Lehrerbrowser mit verschiedenen Codes dürfen sich nicht beeinflussen.

## Einmalwahl

Eine clientseitige `sessionStorage`-Markierung kann versehentliche Doppelstimmen im selben Tab verhindern und Antwortänderungen referenzieren. Sie ist keine Manipulationssicherheit und darf nicht für Noten, Prüfungen oder Anwesenheitsnachweise verwendet werden.

## Export

CSV und HTML mindestens mit Raumbezeichnung, Code, Zeit, Frage, Antwort, Stimmen, Anteil und Status versehen. Keine Identitätsfelder ergänzen. Vor Raumende warnen, wenn Ergebnisse noch nicht heruntergeladen wurden.

## Testmatrix

1. Raum A und Raum B aus getrennten Lehrersessions erzeugen.
2. Verschiedene Inline-Fragen starten, Ergebnisstrategien wechseln und Stimmen einschließlich Änderung abgeben.
3. Vor Freigabe öffentliche API ohne `counts` prüfen.
4. Lehreraggregate prüfen.
5. Freigeben und öffentliche Counts prüfen.
6. Neue Frage starten und Verlauf prüfen.
7. Gebündelten Check starten, vollständig abgeben, aktualisieren, ablaufen lassen und freigeben.
8. Ablaufdatum beider Räume getrennt verlängern und verkürzen; ungültige Mindest-/Höchstwerte ablehnen.
9. Karte anonym einreichen, vor Freigabe öffentlich verbergen, freigeben, kategorisiert anzeigen, weitere Karte verwerfen und Wand leeren.
10. Timer und Freigabestufe der Räume getrennt prüfen.
11. Räume auf getrennte Ergebnisse prüfen.
12. Beide Räume löschen und 404 bei erneutem Status erwarten.
