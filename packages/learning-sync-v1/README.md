# Persönliche Lernstände · 1.0.0

Gemeinsamer, optionaler Adapter für vorhandene LernHTMLs. Anonyme Klassenräume bleiben davon unabhängig. Ohne Konto oder Zuweisung bleibt die Mitschrift lokal nutzbar.

## Modulvertrag

`RELIGION_LEARNING_STATE` stellt `exportState()`, `importState(payload)` und `setStorageScope(scope)` bereit. Änderungen melden `religion-learning-state-change`; Begriffskarten melden zusätzlich `religion-concept-map-change`. Personen- und Zuweisungskennung bilden den lokalen Speichernamensraum. Ein Wechsel darf keine Antworten aus dem vorigen Namensraum übernehmen.

Ladereihenfolge: lokale Lernwerkzeuge/Begriffskarten, Moduladapter, `work-renderer.js`, `learning-sync.js`; dazu `learning-sync.css`. Rollen und Modul-Slug stammen aus `RELIGION_CLASSROOM_CONFIG`. Der Build erzeugt mit `tools/build_contract.py` ein serverseitiges Register der erlaubten Antwortfelder, Materialien und Begriffskarten. Der PHP-Server übernimmt keine unbekannten Antwortfelder.

## Verhalten

- Autospeicherung mit sichtbarem Empfänger und lokalem Ausfallschutz.
- Versionsprüfung vor dem Schreiben; Konfliktdialog statt stiller Überschreibung.
- Höchstens drei Servervorversionen, gezielt wiederherstellbar.
- Zugewiesener Raum und geschützter Materialzugang werden nach Berechtigungsprüfung übernommen.
- Lehrkräfte sehen nur zugewiesene Klassen; Administration kann alle verwalten.
- Beamer erhält genau den bewusst ausgewählten Ausschnitt, standardmäßig ohne Namen. Keine Klassenliste oder vollständige Mitschrift im öffentlichen Raumzustand.
- Darstellung von Antworten und Begriffskarten erfolgt als Text/DOM ohne Ausführung fremder Inhalte.

Serverendpunkt `/zugang/work.php` erfordert teacher-platform 2.0.0 und Manifest 1.1.0 mit expliziter persönlicher Datenhaltung. Bestehende Manifest-1.0-Module bleiben lokal. Die erstmalige technische Integration des Ethikmoduls ist kein automatischer Rollout auf alle Lernseiten.

## Tests

`node --test packages/learning-sync-v1/tests/sync.test.cjs` prüft echte Adapterlogik einschließlich verspäteter Antworten, Zuweisungswechsel und abgelaufener Konten. PHP-Tests prüfen zusätzlich Berechtigungen, Verschlüsselung, Versionskonflikte und Beamerfreigabe.
