# Persönliche Lernstände · 1.0.1 (Integrationsstand)

Gemeinsamer, optionaler Adapter für vorhandene LernHTMLs. Anonyme Klassenräume bleiben davon unabhängig. Ohne Konto oder Zuweisung bleibt die Mitschrift lokal nutzbar.

## Modulvertrag

`RELIGION_LEARNING_STATE` stellt `exportState()`, `importState(payload)` und `setStorageScope(scope)` bereit. Änderungen melden `religion-learning-state-change`; Begriffskarten melden zusätzlich `religion-concept-map-change`. Personen- und Zuweisungskennung bilden den lokalen Speichernamensraum. Ein Wechsel darf keine Antworten aus dem vorigen Namensraum übernehmen.

Ladereihenfolge: lokale Lernwerkzeuge/Begriffskarten, Moduladapter, `work-renderer.js`, `learning-sync.js`; dazu `learning-sync.css`. Rollen und Modul-Slug stammen aus `RELIGION_CLASSROOM_CONFIG`. Der Build erzeugt mit `tools/build_contract.py` ein serverseitiges Register der erlaubten Antwortfelder, Materialien und Begriffskarten. Der PHP-Server übernimmt keine unbekannten Antwortfelder.

## Verhalten

- Autospeicherung mit sichtbarem Empfänger und lokalem Ausfallschutz.
- Versionsprüfung vor dem Schreiben; Konfliktdialog statt stiller Überschreibung.
- Höchstens drei Servervorversionen, gezielt wiederherstellbar.
- Zugewiesener Raum und geschützter Materialzugang werden nach Berechtigungsprüfung übernommen.
- Änderungen des zugewiesenen Raums und Materialzugangs werden bei der Sitzungsprüfung übernommen, ohne persönliche Antworten neu zu laden.
- Archivierte Klassen bleiben lesbar; neue Eingaben werden nur lokal gespeichert. Nach Reaktivierung können ausstehende Änderungen wieder synchronisiert werden.
- Entzogene Klassenzuweisung blendet persönliche Einträge aus und trennt den Live-Zugang. Noch nicht übertragene Einträge bleiben im bisherigen lokalen Personen-/Unterrichtsbereich erhalten, werden aber nicht mehr gesendet.
- Das Ende des Live-Raums löscht keine Mitschriften. Der Server gibt abgelaufene oder beendete Raumverbindungen nicht mehr als aktive Verbindung aus.
- Lehrkräfte sehen nur zugewiesene Klassen; Administration kann alle verwalten.
- Beamer erhält genau den bewusst ausgewählten Ausschnitt, standardmäßig ohne Namen. Keine Klassenliste oder vollständige Mitschrift im öffentlichen Raumzustand.
- Darstellung von Antworten und Begriffskarten erfolgt als Text/DOM ohne Ausführung fremder Inhalte.

Serverendpunkt `/zugang/work.php` erfordert teacher-platform 2.0.0 und Manifest 1.1.0 mit expliziter persönlicher Datenhaltung. Bestehende Manifest-1.0-Module bleiben lokal. Die erstmalige technische Integration des Ethikmoduls ist kein automatischer Rollout auf alle Lernseiten.

## Tests

`node --test packages/learning-sync-v1/tests/sync.test.cjs` prüft echte Adapterlogik einschließlich verspäteter Antworten, Zuweisungswechsel und abgelaufener Konten. PHP-Tests prüfen zusätzlich Berechtigungen, Verschlüsselung, Versionskonflikte und Beamerfreigabe.

Stand 07.09.2026: sechs Adaptertests und 97 gemeinsame PHP-Prüfungen bestanden. Diese Ergänzungen sind im Repository vorbereitet, noch nicht im veröffentlichten Ethik-Build enthalten. Beim nächsten koordinierten Build `versions.learningSync` auf `1.0.1` setzen, die Cacheversion erhöhen und die zugehörige `LearningWork.php` gemeinsam veröffentlichen. Die parallel bearbeitete Ethik-Autorenquelle wurde hierfür nicht verändert.

Zusätzlicher Entwicklungsstand: Der gemeinsame Unterrichtseinstieg ist inzwischen ebenfalls implementiert. Für den kombinierten Rollout ist deshalb learning-sync **1.1.0** vorgesehen (anstelle des isolierten Korrekturstands 1.0.1). Sieben Adaptertests und 115 gemeinsame PHP-Prüfungen bestehen; Einbauvertrag und noch offene Browser-/Produktivabnahme stehen in [Unterricht starten](../../docs/UNIFIED-TEACHING-ENTRY.md). `teaching-entry.js` und der private `live-teaching-hook.php` müssen zusammen mit dem aktualisierten Klassenraumkern und Plattformkern eingebunden werden.
