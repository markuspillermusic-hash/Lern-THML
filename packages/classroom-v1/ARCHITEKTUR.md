# Klassenraumkern v1.3.1 – Architekturentscheidung

Stand: 24. August 2026

## Ziel

Alle produktiven LernHTMLs verwenden dieselbe technische und visuelle Grundlogik für Lehrer-, Schüler- und Beameransicht. Fachspezifische Interaktionen bleiben in kleinen Seitenadaptern. Der Kern darf weder die Offline-Nutzbarkeit der Lernseiten noch die physische Trennung vertraulicher Lehrerhinweise aufheben. Die in Version 1 noch vorhandenen globalen Namen mit Präfix `RELIGION_` sind ausschließlich eine Abwärtskompatibilitätsschicht; fachlicher Code darf sich nicht auf Religionsinhalte verlassen.

## Gewählte Lösung: gemeinsamer Quellkern, lokale Auslieferung je Modul

Der Quellcode liegt einmal in `packages/classroom-v1`. Jeder Build kopiert die geprüfte Version in den Ordner der jeweiligen Lernseite. Dadurch entstehen keine Laufzeitabhängigkeit von einer fremden zentralen URL und kein gemeinsamer Ausfallpunkt. Cache-Version, API-Pfad, Raumablage, Freigabestufen und Aktivitäten werden pro Lernseite konfiguriert, nicht durch nachträgliche String-Ersetzungen im JavaScript erzeugt.

Die Lösung besteht aus drei Ebenen:

1. **Klassenraumkern:** Raum anlegen oder wieder öffnen, QR-Code und Links, Gerätewechsel, anonyme Abstimmungen, Ergebnissichtbarkeit, Korrektur einer Stimme, Quiz, Timer, Abschnittsfreigabe, Export sowie klar benannte Leer-, Lade- und Fehlerzustände.
2. **Präsentationskern:** Beamer verbinden, letzten Zustand wiederherstellen, genau einen aktuellen Abschnitt beziehungsweise Präsentationshalt zeigen, Medien- und Bildbefehle übertragen und den Beamerstatus melden. Befehle werden lokal sofort und zusätzlich in Sendereihenfolge im Raumzustand gespeichert. Jeder Befehl trägt einen vollständigen kompakten Präsentations-Snapshot.
3. **Seitenadapter:** didaktisch besondere Interaktionen wie Physiksimulationen, Podcast- und YouTube-Steuerung, Zeitleisten, schrittweise Schaubilder oder moderierte Freitextsammlungen. Adapter verwenden das gemeinsame Befehlsprotokoll und dürfen keine zweite Raumverwaltung aufbauen.

Produktive Mehrlehrer-Installationen ergänzen eine vierte, getrennte Ebene: **zentrale Lehrerplattform** für persönliche Konten, Organisationen, Raumbesitz, verschlüsselte persönliche beziehungsweise schulische API-Schlüssel, Kontingente, Zugriffsanfragen und Audit. Der Klassenraumkern bleibt davon technisch entkoppelt und erhält nur deklarative `moduleSlug`, Feedback-Endpunkt und boolesche Raumfreigaben.

## Warum nicht eine zentrale Laufzeitdatei auf dem Server?

Eine einzige URL für alle Lernseiten wäre bequem zu aktualisieren, hätte aber drei Nachteile: Offlinefassungen würden unvollständig, ein Fehler träfe alle Lernseiten gleichzeitig und veraltete Browser-Caches könnten nicht eindeutig einer konkreten Lernseitenversion zugeordnet werden. Die lokale Kopie je Modul ist deshalb absichtlich Teil des Builds; die Quelle bleibt trotzdem zentral und damit wartbar.

## Verbindliche Rollen

- **Schüleransicht:** keine Lehrerhinweise im ausgelieferten DOM, lokale private Eingaben, ein Raumcode für die ganze Stunde, nur freigegebene Abschnitte, anonyme Gruppenaggregate.
- **Lehreransicht:** serverseitig geschützt, Raumverwaltung unmittelbar am Seitenanfang, Aktivitäten erst an der didaktisch passenden Stelle, Beamer-, Freigabe- und Timersteuerung, Exporte ohne Einzelprofile.
- **Beameransicht:** keine Eingabefelder und keine Lehrerhinweise, keine Vorschau auf den nächsten Abschnitt, großer Raum-/QR-Zustand, aktuelle Aktivität und freigegebene Aggregate, vollständige Bedienbarkeit der projizierten Medien und Interaktionen.

## Gemeinsames Präsentationsprotokoll

Jeder Befehl besitzt mindestens `type`, `moduleId`, `room`, `sentAt` und `nonce`. Die API validiert den Raumcode, bindet den gespeicherten Befehl nochmals an den angefragten Raum und liefert ihn unverändert an getrennte Geräte aus. Ein raumgebundener Beamer verwirft ältere oder raumlose Befehle. Generische Befehlstypen sind:

- `goto`: Abschnitt oder Präsentationshalt fokussieren;
- `join`: Raumcode und QR-Code als Vollbild zeigen;
- `theme`, `dim`, `zoom`: Darstellung steuern;
- `media`: lokales Video oder Audio abspielen, pausieren, stoppen oder positionieren;
- `youtube`: freigegebenes YouTube-Element am Beamer laden und fokussieren;
- `image`: Bild vergrößern oder die Vergrößerung schließen;
- `stepper`: Zeitleiste, Schaubild oder andere Schrittfolge auf einen definierten Schritt setzen;
- `interaction`: fachseitige Interaktion mit einer begrenzten, validierten Nutzlast synchronisieren.

Zusätzlich enthält jede Nutzlast `_classroom`: Theme, Fokus, Fortschritt, Beitritts-/Verdunkelungsmodus, Detailszustände, deklarative Controls sowie Audio-/Video-/YouTube- und Bildzustand. Das ist notwendig, weil der Server absichtlich nur den letzten Präsentationszustand hält. Ein schneller nachfolgender Scrollbefehl darf einen Videostart oder das Schließen einer Bildansicht nicht unbemerkt verdrängen. Persistente Schreibvorgänge werden deshalb serialisiert; der jeweils neueste Snapshot bleibt selbstgenügsam und nach einem Beamer-Neuladen rekonstruierbar.

Seitenadapter dürfen zusätzliche Typen definieren. Sie müssen idempotent sein: Derselbe Befehl darf nach einem Neuverbinden erneut angewandt werden, ohne den Zustand zu beschädigen.

## Beamerregel

Vor dem ersten gültigen Präsentationshalt zeigt der Beamer ausschließlich den Beitritts- beziehungsweise Bereitschaftszustand. Nach `goto` werden alle anderen Unterrichtsabschnitte aus Layout, Fokusreihenfolge und assistiver Ausgabe entfernt. Innerhalb des aktiven Abschnitts darf ein Adapter zusätzlich nur den aktuellen Halt sichtbar lassen. Erst ein expliziter nächster Befehl wechselt weiter.

Die Leseposition folgt keinem rohen Fenster-Scrollwert. Der Kern bestimmt eine semantische Leselinie unterhalb angehefteter Lehrer-Navigationen und überträgt Anker plus Fortschritt innerhalb dieses Ankers. Lange Beamerabschnitte scrollen intern mit; der nächste Hauptabschnitt bleibt vollständig ausgeblendet.

## Datenschutz und Persistenz

- Raumcodes enthalten keine Personendaten.
- Auf dem Server liegen nur Raumkonfiguration, Summen, moderierte Freitexte, Timer, Freigabe- und Präsentationszustand.
- Private Lernfelder und individuelle Antworten bleiben lokal im Browser.
- Räume verfallen nach 42 Tagen und können aktiv beendet werden. Damit begleiten sie auch mehrwöchige Sequenzen; das genaue Ablaufdatum bleibt sichtbar.
- Die Raumliste ist nur nach persönlicher Lehrer-Anmeldung abrufbar. Jeder Raum besitzt eine Konto-ID; normale Lehrkräfte sehen nur eigene Räume, Administration kann unterstützen. Dadurch ist die Vorbereitung am PC und das Fortsetzen am Surface ohne erneute Raum- oder Schlüsseleingabe möglich.
- Persönliche Lösungen werden auch bei KI-Feedback nicht im Raumzustand gespeichert. Das Feedback-Gateway verarbeitet den Text transient; technische Nutzungsprotokolle enthalten nur Raum, Aufgabe, Status, Tokenwerte und pseudonyme Zähler.

## Qualitätsgrenzen

Ein Build ist erst veröffentlichungsfähig, wenn Rollenbereinigung, Passwort-/Secret-Scan, API-Lebenszyklus, Stimmenkorrektur, Freigabesperren, Präsentations-Wiederanschluss, Medienbefehle, Tastaturbedienung, horizontale Überläufe sowie 390 × 844, 1024 × 768, 1280 × 720 und 1920 × 1080 geprüft sind. Für Beamerseiten gilt zusätzlich: Der unmittelbar folgende Abschnitt darf in keinem Testbild sichtbar sein; persönliche Antwortfelder dürfen auch zur Laufzeit nicht entstehen. Der Live-Vertragstest muss QR, Theme, Freigabe, Timer, Details, deklarative Controls, Medien, Neuladen und JavaScriptfehler in getrennten Browserkontexten prüfen und alle Testräume beenden.

Seit v1.2.4 gilt zusätzlich: Die feste Lehrerleiste bleibt einzeilig und höhenstabil; ihr Timerfenster wird als nicht abgeschnittenes Overlay gerendert und unabhängig von der großen Raumverwaltung live geprüft. Scrollbefehle werden gedrosselt und zusammengefasst, Ankerwechsel besitzen eine Richtungshysterese, und der Beamer nähert sich fortlaufenden Zielpositionen mit begrenzter Bewegung pro Bildwiederholung und weichem Auslaufen an.

Seit v1.2.5 ist auch die optionale Kopfzeile der Schüleransicht einklappbar; der Zustand wird getrennt von der Lehreransicht gespeichert. Neue Räume bleiben standardmäßig 42 Tage geöffnet. Jede Änderung am gemeinsamen Kern erzwingt eine neue, in allen Lernseiten identische Cache-Version.

Seit v1.3.0 kann ein Modul an die zentrale Lehrerplattform angebunden werden. Die Lehrerroute verwendet dann die zentrale Sitzung, neue Räume werden einem Konto gespiegelt und KI-Feedback wird ausschließlich über serverseitig registrierte Aufgaben und den verschlüsselten Konto-/Organisationstresor freigegeben. Seitenspezifische Passwort- oder Schlüsselverwaltung ist in diesem Modus unzulässig.

Seit Klassenraumkern v1.3.1 und Präsentationskern v1.2.5 sind Standardbezeichnungen fachneutral. Die älteren globalen `RELIGION_*`-Namen bleiben bis zu einer bewusst inkompatiblen Hauptversion erhalten, damit bestehende Lernseiten weiterhin funktionieren.
