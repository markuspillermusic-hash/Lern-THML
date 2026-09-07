# Gemeinsamer Unterrichtseinstieg – Integrationsprüfung

Stand: 07.09.2026, nachmittags. **Neue Klassen-/Raumsteuerung weiterhin Entwicklung, keine Produktivfreigabe.** Die separat beschriebene CSP-Korrektur ist dagegen veröffentlicht.

## Browserprüfung mit ausschließlich synthetischen Konten

Die lokale PHP-Fixture lädt die vollständige Ethik-Lernseite, ersetzt deren gemeinsame JS-/CSS-Pakete nur in der HTTP-Antwort und bindet den neuen API-Hook in eine temporäre API-Kopie ein. Autorentext und Unterrichtsmaterialien werden dafür nicht verändert. Lehrkraft und Beamer laufen im In-app-Browser, das Schülerkonto in Chrome.

- Klasse starten/fortsetzen verbindet dieselbe Zuweisung und denselben Live-Raum.
- Angemeldeter Testschüler öffnet den Lernweg aus „Mein Unterricht“. Der persönliche Speicherstatus nennt die zuständige Lehrkraft. Kein zusätzlicher Codebeitritt oder „Code wechseln“ im persönlichen Unterricht.
- Lehrerfreigabe des ersten Fallabschnitts erreicht den Schüler. Eine Antwort zur Rechtsfrage/moralischen Frage wird automatisch gespeichert, erscheint in der eingebetteten Klassenliste als ein bearbeitetes Antwortfeld und bleibt nach Neuladen erhalten.
- Auswahl der Testperson zeigt exakt die gespeicherte Antwort. „Am Beamer zeigen“ veröffentlicht nur dieses Feld, standardmäßig ohne Namen. Die separate Beameransicht zeigt den Text leserlich; Schließen der Freigabe entfernt ihn wieder.
- Temporärer Unterricht erhält einen anonymen Link, keine persönliche Zuweisung und zunächst acht Stunden Laufzeit. Fortsetzen nach Neuladen verwendet denselben Raum und Wiederholungsnachweis.
- Parallel offener Klassenbeamer und temporäres Lehrerfenster behalten ihre Räume. Zwei Lehrer-Tabs mit verschiedenen Räumen behalten ihre jeweilige Auswahl auch nach Neuladen. Beamer schreibt in einen eigenen lokalen Raumschlüssel; Lehrer und Beamer bevorzugen zusätzlich den tabbezogenen Sitzungsspeicher. Alte lokale Schlüssel bleiben als Einstieg erhalten.
- Neues Startpanel und Schülerübersicht sind in einem tatsächlichen 390×660-CSS-Pixel-Inhaltsfenster bedienbar. Der Dialog hat einen stehenbleibenden Schließen-Knopf und einen scrollenden Inhalt. Screenshots des Beamertexts und der schmalen Oberfläche wurden angesehen. Das ist kein vollständiger mobiler Audit der übrigen, parallel bearbeiteten Ethik-Seite; dort war weiterhin horizontaler Überlauf außerhalb der neuen Oberfläche sichtbar.

## Gefundene und korrigierte Integrationsfehler

1. Die Ethik-Lehrer-CSP erlaubte YouTube, aber keine eigene eingebettete Schülerübersicht. `frame-src 'self'` ergänzt; weiterhin nur eigene Herkunft und der bereits erlaubte YouTube-Host.
2. Persönliche Schüler bekamen trotz automatischer Verbindung weiterhin ein großes Codeformular. Der Adapter kennzeichnet jetzt persönliche Unterrichtsverbindungen; der Raumkern blendet nur die konkurrierenden Beitrittssteuerungen aus. Abstimmungen bleiben verfügbar. Bei Entzug der Zuweisung wird der persönliche Modus zurückgenommen.
3. Verspätete Statusantworten eines früheren Schülerraums konnten den neu gewählten Raum überschreiben. Der Raumkern verwirft Antworten, deren angefragter Raum nicht mehr aktuell ist.
4. Beamer und Lehrer-Tabs überschrieben beim Polling denselben zuletzt benutzten Raumschlüssel. Rollen-/Tabtrennung ergänzt, ohne alte gespeicherte Räume oder Mitschriften zu löschen.
5. Temporäre Startkontexte gingen beim Neuladen verloren. Ein pro Lehrerkonto/Lernweg getrennter Hinweis im Sitzungsspeicher hält Raum, Ablauf und Wiederholungsnachweis fest. Er startet nie selbst einen Raum; die Serverprüfung bleibt maßgeblich.

## Automatisierte Nachweise

- `php packages/teacher-platform-v1/tests/unified-platform.php`: **121** Prüfungen.
- `php packages/teacher-platform-v1/tests/run.php`: **42** bestehende Prüfungen.
- `node --test packages/learning-sync-v1/tests/sync.test.cjs packages/classroom-v1/tests/room-storage.test.cjs`: **11** Tests des echten JavaScript-Codes.
- Zusätzliche Fehlerpfade: gehaltene Unterrichtssperre weist einen zweiten Start ohne Raumerzeugung ab; Wiederholung nach Freigabe derselben Sperre setzt den ursprünglichen Raum fort. Ein injizierter Fehler nach physischer Raumerzeugung führt beim Wiederholen zu genau einer persönlichen Zuweisung und löscht keine früheren Lernstände. Kein tatsächlicher Prozessabbruch-/SIGKILL-Test.

## Separat veröffentlichter CSP-Hotfix

Produktionsbestand bei der Prüfung: Ethik-Inhalt 1.11.1, Cache v21, classroom 1.5.0 / learning-sync 1.0.0 / teacher-platform 2.0.0. Nur `lehrer/index.php` wurde ausgetauscht; Vergleich gegen die aktuelle Serverdatei bestätigte exakt die eine CSP-Änderung. Die anderen **42 Dateien** des Modulbaums wurden gegen die Sicherung gehasht und blieben unverändert. Keine Produktionskonten, Klassen oder Schülerstände angelegt oder verändert.

- Serversicherung: `/websites/_backups/ethik-teacher-frame-20260907-1538` (43 Dateien).
- Lokale Quellsicherung: `D:/KI-Projekte/Archive/Ethik-teacher-frame-fix-20260907-1535`.
- Hash vorher: `6df3282591765379cccaea363e6d043d4aa1afb09e8ffeca0671fc58c9807775`.
- Öffentlicher Lehreraufruf ohne Sitzung bleibt ein kontrollierter Login-Redirect.
- Mit vorhandener echter Lehreranmeldung in Chrome öffnet „Schülerstände öffnen“ jetzt die tatsächliche eingebettete Übersicht, statt eines blockierten Frames. Es wurde nur gelesen.
- Der gleiche CSP-Fix steht in der lokalen Auth-Vorlage und der lokalen Lehrer-Builddatei, damit ein neuer Build ihn erhält.

## Verbleibende Freigabeschritte

Koordinierte Paketversionen, privater Hook und Build-/Manifestintegration; bytegenaues Overlay und Live-Abnahme der neuen Startoberfläche. Noch kein separat nachgewiesener gleichzeitiger Doppelklick aus zwei Browsern und kein vollständiger neuer Begriffsnetz-/Markierungs-Durchlauf. Die bereits bekannten automatisierten Mitschrift-/Zugriffsprüfungen ersetzen diese Browserprüfung nicht.

Die persönliche Prüfungsapp-Verknüpfung bleibt wegen des zuvor reproduzierten Chrome-Fehlers `ERR_BLOCKED_BY_CLIENT` am Callback ungeprüft. Keine Schutzregel deaktiviert, keine Ersatzroute zur Umgehung angelegt und keine Konten automatisch zusammengeführt.
