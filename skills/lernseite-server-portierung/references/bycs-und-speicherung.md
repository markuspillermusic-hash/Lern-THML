# Lokale Speicherung und ByCS

## Priorität

Der Lernstand darf nicht von einer dauerhaften Netzverbindung oder einem individuellen Serverkonto abhängen. Schülertexte und Handschrift zunächst lokal halten und aktiv exportierbar machen.

## Empfohlenes Modell

1. Automatisch im Browser speichern und sichtbaren Status anzeigen.
2. Einen verständlich benannten „Lernstand herunterladen“-Knopf anbieten und eine versionierte, portable Datei erzeugen; zusätzlich eine lesbare Offline-HTML oder Textfassung ermöglichen.
3. Export wieder importieren können; Formatversion prüfen.
4. ByCS-Aufgabe als Abgabe- und Sicherungsort verwenden.
5. Für Weiterarbeit auf anderem Gerät Datei aus ByCS laden und lokal importieren.

## Grenzen

- `localStorage` ist keine dauerhafte Sicherung; Browserdaten, privater Modus und Gerätewechsel können den Stand löschen.
- ByCS nicht ohne geprüfte Schnittstelle als automatische Synchronisation darstellen.
- Zuordnung möglichst der ByCS-Aufgabe überlassen; keinen Namen im Hefteintrag erzwingen.
- Lehrkraftansicht und anonyme Abstimmung getrennt vom Hefteintrag halten.

## Auswertung abgegebener Lernstände

- Für strukturierte Lernstandsdateien ein ausschließlich lokales Lehrkraftwerkzeug anbieten: mehrere Dateien einlesen, Formatversion prüfen, Vollständigkeit je Abschnitt zusammenfassen und einzelne Antworten lesbar öffnen.
- Keine Lernstände automatisch auf den Live-Server hochladen. Die Zuordnung erfolgt über die Abgabe in ByCS.
- Unbekannte Versionen, leere Felder, Handschriftbilder und Voll-/Kompaktmodus transparent anzeigen; Dateien nicht stillschweigend verwerfen.
- Auswertung nicht als automatische Benotung darstellen. Sie dient der Sichtung und Unterrichtsdiagnose.

## Spätere Integration

SSO, LTI, API oder automatischer Upload erst planen, wenn schulischer Auftrag, technische Dokumentation, Rollen, Datenschutz und Löschfristen vorliegen. Bis dahin ist Download/Upload robuster und transparenter.
