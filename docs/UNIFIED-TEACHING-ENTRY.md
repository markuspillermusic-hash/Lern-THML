# Unterricht starten – Klasse und Live-Steuerung

Stand: 07.09.2026. Ziel: eine Unterrichtssteuerung, nicht konkurrierende Klassen- und Raumverwaltungen. Oberfläche und Startablauf sind im gemeinsamen Paket implementiert und lokal im Browser geprüft. Die produktive Integration in den Ethik-Build ist noch offen.

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

### Implementierter Stand

`TeachingStart.php` verbindet eine geprüfte Klassenzuweisung mit dem vorhandenen Live-Kern. Eine Dateisperre pro Lehrkraft/Lernweg/Klasse und ein Wiederholungsnachweis für temporäre Starts verhindern normale Doppelstarts und Duplikate bei verlorenen HTTP-Antworten. Bereits abgeschlossene Verläufe werden nicht still wieder geöffnet; mehrere passende aktive Verläufe erfordern eine Auswahl. Eine Wiederaufnahme ändert keine Speicherfrist. Temporäre Räume laufen zunächst acht Stunden, klassenbezogene sechs Wochen. Eine nachträgliche Änderung bleibt in der vorhandenen Steuerung möglich.

`teaching-entry.js` liefert die Klassenwahl und Start-/Fortsetzen-Aktion. Die bisherige Raumvorbereitung ist unter „Weitere Optionen“ erreichbar. Schülerstände werden mit der aktuellen Zuweisung geöffnet; persönliche Links enthalten die Zuweisungskennung. Temporäre Links tragen `teilnahme=ohne-klasse`, sodass ein eventuell angemeldetes Schülerkonto nicht unbemerkt zur persönlichen Speicherung führt. Der Klassenbetrieb zeigt keinen zusätzlichen großen Raumcode und erzwingt keine Beitrittsfolie; QR/Link bleiben bei Bedarf verfügbar.

Nachweise: 115 gemeinsame PHP-Prüfungen, 42 bestehende PHP-Prüfungen und sieben Adaptertests. Browser mit synthetischer Lehrkraft: Klasse starten/fortsetzen (derselbe Raum), Schülerübersicht mit genau einer Testperson, temporär starten/fortsetzen (derselbe zweite Raum), Rückkehr zur Klasse, persönliche und anonyme Linkvarianten. Helle und dunkle Darstellung visuell geprüft. Noch offen: schmaler Viewport, vollständiger Schüler→Mitschrift→Beamer-Durchlauf mit der neuen Startoberfläche, tatsächlicher Parallelklick von zwei Browsern, finaler Produktivbuild und Live-Abnahme.

### Einbauvertrag für den nächsten Build

- `TeachingStart.php`, `Rooms.php`, `LearningWork.php`, `Maintenance.php` und die aktualisierte `public/zugang/work.php` zusammen übernehmen.
- `live-teaching-hook.php` bleibt außerhalb des Webroots. Die Modul-API bindet ihn nach dem Parsen von JSON, `$action` und Lehrerauthentifizierung ein. Sie stellt ihre bestehenden Funktionen `create_room`, `read_room`, `public_state`, `require_teacher`, `platform_teacher` und `respond` bereit. Der Hook führt nur `start_teaching` aus und prüft zusätzlich den CSRF-Nachweis aus der gemeinsamen Sitzung.
- `teaching-entry.js` nach `work-renderer.js`, vor `learning-sync.js` laden. `RELIGION_CLASSROOM_CONFIG.teachingEntry=true` erst setzen, wenn der Hook und der neue Klassenraumkern im selben Build vorhanden sind. Bei ausgeschaltetem Merkmal bleibt der bisherige Einstieg bestehen.
- Vor Veröffentlichung Paketversionen und Manifest gemeinsam anheben (geplant: classroom 1.6.0, learning-sync 1.1.0, teacher-platform 2.1.0) sowie Cacheversion ändern. Der aktuelle Produktivstand ist weiterhin der im Freigabenachweis dokumentierte Stand.
- Die lokale QA-Fixture `tests/serve-platform.php` bindet den Hook in eine temporäre Kopie der Modul-API ein und verändert weder Ethik-Quellen noch Produktionsdaten. `/qa/teaching/` lädt die neuen Pakete direkt; dieses Testverzeichnis wird nicht veröffentlicht.

Der Startablauf schützt vor üblichen Wiederholungen. Ein harter Prozessabbruch zwischen physischer Raumerzeugung und dem Schreiben des Wiederholungsnachweises kann einen unzugeordneten Raum hinterlassen; er hat keine persönlichen Mitschriften und läuft regulär ab. Dafür wird kein bestehender Raum oder Arbeitsstand gelöscht. Vor dem Rollout ist der Fehlerpfad gezielt zu prüfen.

## Persönliche Anmeldung: noch offen

Der Betreiber ist im zentralen Lehrerportal angemeldet. Chrome blockiert den Prüfungsapp-Callback mit `ERR_BLOCKED_BY_CLIENT`; die normale App-Startseite lädt, der Callback beantwortet unabhängige unauthentifizierte HTTP-Anfragen mit einem kontrollierten 400-Fehler. Das beweist noch keinen erfolgreichen persönlichen Anmelderundlauf. Die konkrete Browserregel/Erweiterung ist nicht ermittelt; die Erweiterungsverwaltung ist für die Browserautomation gesperrt. Keine Sicherheitsregeln deaktiviert, keine Callback-Ausweichroute angelegt, keine Konten automatisch zusammengeführt.
