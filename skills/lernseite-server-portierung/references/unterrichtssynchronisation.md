# Unterrichtssynchronisation

## Zustände getrennt halten

| Zustand | Speicherort | Beispiele |
|---|---|---|
| persönlicher Lernstand | Browser der lernenden Person | Texte, Handschrift, private Begründung, Selbstcheck |
| gemeinsamer Unterrichtsraum | Server, anonym und befristet | Raumcode, Freigabestufe, aktuelle Aktivität, Summen, Timer |
| Beamersteuerung am Lehrgerät | lokaler Browserkanal | aktueller Schritt, Fokus, Verdunkeln, Medienbefehl |
| Vorbereitung der Lehrkraft | Lehrgerät und bei Bedarf Raumkonfiguration | Sozialform, Modus, neutrale Raumbezeichnung, externe Unterrichtslinks |

Keine dieser Ebenen als Ersatz für eine andere missbrauchen. Besonders keine privaten Schülertexte in den Raumzustand kopieren.

## Ein Code für die Stunde

- Einen kryptografisch zufälligen sechsstelligen Stundencode für Ablaufkonfiguration, Abstimmungen, Timer und Freigaben verwenden.
- Schülerlink und QR-Code enthalten denselben Code als URL-Parameter. Zu Beginn der Schülerseite trotzdem ein deutliches manuelles Codefeld anbieten.
- Code und QR an jeder aktiven Abstimmungsstelle in der Beameransicht groß anzeigen, damit später Hinzukommende beitreten können. Solange die geschützte Lehreransicht im Klassenraum-Manager steht, ist die Beitrittsansicht am Beamer der verbindliche Präsentationszustand; automatisches Folgen darf sie nicht verdrängen.
- Räume für kurze Einzelstunden konfigurierbar kurz halten; für mehrwöchige Lernsequenzen standardmäßig 42 Tage vorsehen. Beim Anlegen und für den aktiven Raum ein Kalenderfeld anbieten, sodass die Lehrkraft die Laufzeit innerhalb einer serverseitigen Mindest-/Höchstfrist verlängern oder verkürzen kann. Ablaufzeit sichtbar anzeigen, ein bewusstes Raumende anbieten und abgelaufene Dateien kontrolliert löschen.
- Auf dem Lehrgerät mehrere vorbereitete Räume lokal merken. Optionales Lehrerkürzel nur zur Wiedererkennung nutzen; keine Personen- oder Schülernamen verlangen.
- Die Raumöffnung wählt noch keine Frage. Aktivitäten erst in ihrem fachlichen Abschnitt starten.

## Schrittmodell und Beamer

1. Vor dem Entfernen der Lehrkraftblöcke stabile Schritt-IDs in der gemeinsamen Autorenquelle vergeben.
2. Nur schülersichtbare, aktuell sichtbare Schritte senden; nicht gewählte Sozialformvarianten überspringen.
3. Lehrer- und Beameransicht über einen lokalen zustandslosen Kanal koppeln: eindeutige Befehls-ID, `storage`-Ereignis plus Polling und regelmäßiger Beamer-Herzschlag.
4. Automatisches Folgen pausierbar machen. Zusätzlich „Hier zeigen“, vor/zurück, Abschnittssprung, Fokus, Verdunkeln und Beamer-Schriftgröße anbieten.
5. Feste Steuerleisten so responsiv bauen, dass keine Taste außerhalb des Viewports liegt.

Pixelgenaues Scrollen zwischen unterschiedlich langen Ansichten vermeiden. Ein Schrittwechsel ist eine semantische Zustandsänderung, keine übertragene Scrollposition.

## Schülerabschnitte freigeben

- Der Raumzustand enthält eine stabile Freigabestufe. Die Schülerseite zeigt den aktuellen Abschnitt und alle vorherigen; spätere Abschnitte bleiben gesperrt.
- Eine ausdrückliche semantische Zuordnung wie `data-release-stage` verwenden. Freigaben nicht unmittelbar aus einer rohen Schrittnummer, DOM-Reihenfolge oder Scrollposition ableiten, weil Lehrer-, Schüler- und Beameransicht unterschiedlich viele Elemente enthalten.
- Freigaben im normalen Ablauf nur vorwärts erweitern. Rücksetzen als bewusste, bestätigte Sonderaktion behandeln.
- Den Fortschritt vom tatsächlich erreichten Beamer-/Lehrerschritt ableiten und mit der Abschnittsreihenfolge abgleichen. Beim Scrollen einen stabilen Inhaltsanker nahe dem oberen Lesebereich auswerten; ein direkter Navigationssprung setzt dagegen ausdrücklich die Freigabestufe seines Zielabschnitts.
- Fachlich zusammengehörige Medienfolgen wie Hörauftrag, Transkript, Audio, Cue-Karten und Sicherungsaufgabe derselben Freigabestufe zuordnen. Einen Test über alle Grenzen ausführen, um Vor- oder Nachlauf um einen Abschnitt zu erkennen.
- Ohne Raum einen klar benannten Selbstlernmodus zulassen, wenn die Seite auch unabhängig vom Unterricht nutzbar sein soll.
- Gesperrte Navigation erklärt knapp, warum ein Abschnitt noch nicht zugänglich ist.

## Inline-Aktivitäten

- Im Autorenmarkup an jeder Einsatzstelle einen stabilen Aktivitätsanker mit Template-ID vorsehen.
- Lehreransicht: Frage, Ergebnisstrategie, Start, Freigabe, Beenden sowie Code/QR an diesem Anker anzeigen.
- Schüleransicht: am gleichen Anker entweder Wartezustand oder die aktive Antwortoberfläche anzeigen. Ein globaler Launcher darf nur zusätzlicher Notzugang sein.
- Beameransicht: Frage, großer Code/QR und je nach gewählter Strategie zunächst Wartezustand oder Ergebnis vollständig in den Viewport einpassen.
- Beim Start zwischen `store` (zunächst nur sammeln) und `beamer` (direkt zeigen) wählen. Erwartungshorizont und richtige Antwort vor Freigabe niemals öffentlich mitsenden.
- Eine Auswahl bis zum Schließen korrigierbar machen. Der Client sendet die vorherige Auswahl, der Server zieht sie unter Dateisperre ab und addiert die neue; negative Zähler verhindern.
- Private Kurzbegründungen nur lokal speichern. Der Export des Lernstands darf darauf später Bezug nehmen.

## Moderierte gemeinsame Kartenwand

- Eine gemeinsame Kartenwand nutzt denselben Raumzustand, aber einen eigenen stabilen Wandbezeichner und konfigurierte Kategorien.
- Öffnen/Schließen, Freigeben/Verwerfen und Leeren sind privilegierte Lehreraktionen. Einreichen ist öffentlich nur bei geöffneter Wand möglich.
- Öffentliche Zustände enthalten ausschließlich freigegebene Karten. Wartende Karten werden nur in der geschützten Lehrerantwort ausgeliefert.
- Kurze anonyme Texte und Gesamtzahl serverseitig begrenzen. Keine Absender-, Geräte- oder Profilkennung ergänzen; beim Raumende alles löschen.

## Gemeinsamer Timer

Ein Timerzustand enthält mindestens `label`, `duration`, `remaining`, `running`, `startedAt` und `endsAt`.

- Serverzeit als Autorität verwenden; Clients berechnen die Anzeige aus `endsAt`.
- Aktionen: starten, pausieren, fortsetzen, Zeit addieren und zurücksetzen/ausblenden.
- Lehrkraft, Beamer und Lernende zeigen denselben kompakten Timer. Bei einem zeitbegrenzten Klassencheck schließt der Server die Aktivität spätestens bei Ablauf, auch wenn kein Browser gerade aktualisiert.

## Synchronisierte Medien

- Medien über stabile IDs statt DOM-Reihenfolge adressieren.
- Befehle mindestens für Start von vorn, Fortsetzen, Pause und optional Zeitposition vorsehen. „Von vorn“ muss vor dem Abspielen zuverlässig auf Zeitposition `0` setzen; es darf nicht als gewöhnliches Fortsetzen implementiert sein.
- Jeden logischen Medienbefehl mit einer eindeutigen Befehlskennung übertragen. Wiederholungsversuche desselben Befehls behalten diese Kennung; der Empfänger führt sie nach der ersten Verarbeitung nicht erneut aus.
- In einer geführten Stunde nur die Beamerinstanz hörbar abspielen. Lehreransicht zeigt Steuerknöpfe; eine lokale Vorschau bleibt stumm oder stoppt vor dem Beamerstart.
- Alle Öffnen-Aktionen auf denselben benannten Beamer-Fensterkontext richten. Falls dennoch mehrere Beamer-Tabs existieren, über Herzschlag und kurze Besitzer-Lease genau eine aktive Audioinstanz bestimmen; alle anderen pausieren sich.
- Beamerfenster früh durch eine Nutzeraktion öffnen und dort eine einmalige sichtbare Audiofreigabe anbieten, damit spätere Wiedergabebefehle nicht an Autoplay-Regeln scheitern. Fehlerzustand, aktiven Besitzer und aktuelles Medium zurückmelden.

## Gebündelter Klassencheck

- `start_quiz` überträgt Titel, alle Fragen, Antwortoptionen, richtige Indizes/Impulse, Ergebnisstrategie und gemeinsame Dauer.
- Öffentliche Zustände enthalten vor Freigabe weder Zähler noch richtige Indizes oder Impulse.
- Schüler beantworten alle Aufgaben in einer Oberfläche, sehen einen Fortschrittszähler und senden genau eine Antwortliste. Vor Ablauf darf eine vollständige Abgabe aktualisiert werden.
- Der Server speichert ausschließlich Zähler pro Frage/Option und die Zahl vollständiger Abgaben, niemals Antwortvektoren oder Gerätekennungen.
- Lehrer- und Beameransicht zeigen eine kompakte Übersicht pro Aufgabe; vollständige Verteilungen und Erwartungshorizonte bleiben in der Lehreransicht aufklappbar.
- Den Live-Klassencheck nicht mit dem lokalen Selbstlern-Check ersetzen: Beide erfüllen unterschiedliche Funktionen.

## Abnahmekriterien

- Raum am Vorabend erzeugen, neu laden und wieder öffnen.
- Zwei Räume in getrennten Lehrersessions parallel führen.
- Beitritt per QR-Link und manuellem Code prüfen.
- Jeden Abschnitt nacheinander sowie per direktem Navigationssprung freigeben; Schüler darf niemals einen Abschnitt voraus sein und erhält zusammengehörige Medienbausteine vollständig.
- Inline-Abstimmung in allen Ansichten am selben fachlichen Ort prüfen, einschließlich Antwortwechsel und Ergebnisstrategie.
- Timeraktionen und Medienstart ohne Audio-Dopplung prüfen. „Von vorn“ aus einer laufenden Position testen, denselben Befehl mehrfach zustellen und zwei Beamerfenster öffnen; hörbar bleiben darf nur eine exakt einmal von vorn gestartete Wiedergabe.
- Mehrteiligen Check vollständig abgeben, aktualisieren, automatisch schließen, freigeben und exportieren.
- Alle Testräume anschließend löschen und 404 beim erneuten Statusabruf erwarten.
