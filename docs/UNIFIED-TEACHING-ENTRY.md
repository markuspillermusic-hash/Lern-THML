# Unterricht starten – Klasse und Live-Steuerung

Stand: 07.09.2026. Ziel: eine Unterrichtssteuerung, nicht konkurrierende Klassen- und Raumverwaltungen. Die Oberfläche ist noch umzusetzen; die unten dokumentierten Zustandskorrekturen sind separat getestet.

## Einstieg

Lernweg öffnen → Klasse auswählen oder ausdrücklich „Ohne Klasse“ → Unterricht starten/fortsetzen. Das Auswählen allein startet keine Aktivität und veröffentlicht keine Schülerlösung.

- Mit Klasse wird die Unterrichtszuweisung für diesen Lernweg und die verantwortliche Lehrkraft fortgesetzt. Personen und Mitschriften bleiben dauerhaft dieser Zuweisung zugeordnet. Ein passender Live-Raum wird im Hintergrund verbunden; kein manuelles Kopieren eines zweiten Codes.
- Ohne Klasse wird beim Start ein temporärer Live-Raum erzeugt. QR/Code ermöglichen die anonyme Teilnahme. Es erfolgt keine automatische persönliche Speicherung, auch nicht allein deshalb, weil im Browser noch ein Schülerkonto angemeldet ist.
- Fehlende Berechtigungen, abgelaufene Anmeldung oder ein Serverfehler dürfen niemals still auf „Ohne Klasse“ umschalten.
- Weitere Räume und Spezialfälle bleiben unter „Weitere Optionen“ verfügbar. Bestehende Räume werden nicht automatisch zusammengelegt, beendet oder gelöscht.

## Daten- und Zugriffsgrenzen

Klasse = Mitgliedschaften und Rechte. Unterrichtszuweisung = Lernweg, verantwortliche Lehrkraft und persönliche Mitschriften. Live-Raum = gemeinsame Freigaben, Timer, anonyme Abstimmung und Beamersteuerung. Diese Ebenen bleiben intern getrennt, auch wenn die Oberfläche nur einen Startvorgang zeigt.

Mehrere Lehrkräfte oder Lerngruppen dürfen nicht auf denselben globalen Klassenraumzustand geschrieben werden. Wiederholtes Starten desselben Unterrichts und doppelte Klicks müssen idempotent sein. Bei mehreren bestehenden passenden Zuweisungen ist eine Auswahl nötig, keine automatische Zusammenführung.

„Live-Unterricht beenden“, „Bearbeitung abschließen“, „Klasse archivieren“ und „Mitschriften löschen“ sind unterschiedliche Vorgänge. Nur der letzte löscht Inhalte und erfordert die bestehende ausdrückliche Bestätigung. Wiederaufnahme darf nicht heimlich die Aufbewahrungsfrist verlängern.

## Bereits korrigierte Grundlagen

- Schüleradapter übernimmt geänderte Raumzuweisung und Materialzugang während der Sitzung.
- Entzogene Zuweisung blendet persönliche Einträge aus; ungesendete Einträge bleiben getrennt lokal erhalten.
- Archivierte Klasse wird im Adapter schreibgeschützt angezeigt; serverseitiger Schreibschutz bleibt maßgeblich.
- Beendete Live-Verbindung wird nicht erneut angeboten; Mitschriften bleiben davon unberührt.
- Nachweis: sechs Tests des echten JavaScript-Adapters und 97 PHP-Prüfungen. Noch nicht produktiv ausgerollt.

## Nächste Implementierung und Abnahme

1. Einen serverseitig geprüften Start-/Fortsetzungsablauf mit Parallelklickschutz implementieren; vorhandene Raum-API verwenden, keinen zweiten Live-Kern bauen.
2. Klassenwahl und Startknopf direkt am vorhandenen Lehrer-Einstieg integrieren; Schülerstände mit aktueller Zuweisung öffnen. Alte Steuerelemente nicht nur zusätzlich daneben stehen lassen.
3. Den anonymen und persönlichen Schüler-Einstieg eindeutig unterscheiden. Der persönliche Link wählt die zugewiesene Arbeit; es gibt keinen zusätzlichen manuellen Raumbeitritt.
4. Mit synthetischen Konten zwei Lehrkräfte, zwei Klassen, Wiederaufnahme, Raumende, Konto-/Klassenwechsel, fehlende Rechte und verlorene Netzwerkantworten im Browser prüfen.
5. Ethik-Build mit dessen parallel bearbeiteter Autorenquelle koordinieren. Aktuell lokal Inhalt 1.11.0 / Cache v20, produktiv 1.10.0 / v19; keine ungeprüfte Veröffentlichung der lokalen Inhaltsänderungen.

## Persönliche Anmeldung: noch offen

Der Betreiber ist im zentralen Lehrerportal angemeldet. Chrome blockiert den Prüfungsapp-Callback mit `ERR_BLOCKED_BY_CLIENT`; die normale App-Startseite lädt, der Callback beantwortet unabhängige unauthentifizierte HTTP-Anfragen mit einem kontrollierten 400-Fehler. Das beweist noch keinen erfolgreichen persönlichen Anmelderundlauf. Die konkrete Browserregel/Erweiterung ist nicht ermittelt; die Erweiterungsverwaltung ist für die Browserautomation gesperrt. Keine Sicherheitsregeln deaktiviert, keine Callback-Ausweichroute angelegt, keine Konten automatisch zusammengeführt.
