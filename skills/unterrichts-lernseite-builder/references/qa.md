# QA

## Automatische Gates

1. Manifest gegen Schema und semantische Regeln prüfen.
2. Rollen-Build erzeugen und Schüler/Beamer auf Lehrerblöcke kontrollieren.
3. HTML-Struktur, doppelte IDs, Sprungziele, Eingabelabel und lokale
   Speicherschlüssel prüfen.
4. Runtime-Hashes mit der deklarierten Paketversion vergleichen.
5. Secretscan und Rechteinventar ausführen.
6. PHP/JavaScript/Python syntaktisch prüfen.

## Visuelle Prüfung

Mindestens 360×800, 768×1024, 1366×768 und 1920×1080. Prüfen: kein horizontaler
Überlauf, Textbreite, Kartenabstände, Sticky-Header, konstante Toolbar,
aufklappbare Inhalte, Hell/Dunkel, Kontrast, Fokus, Medienzuschnitt, Lightbox,
Druck und Beamergrenze.

## Funktionsprüfung in getrennten Kontexten

- Lehrer meldet sich an, legt Raum mit Kalenderdatum an und öffnet Beamer.
- Schüler tritt per QR/Link/Code bei; zweiter Schüler ebenso.
- Freigabe ändert Schüleransicht ohne unzulässigen Vorgriff.
- Abstimmung, Ergebnisstrategie und moderierte Kartenwand funktionieren.
- Theme, Details, Stepper/Zeitstrahl, Bild öffnen/schließen, Audio/Video und Timer
  spiegeln bidirektional wie vorgesehen.
- Beamer folgt geglättet und wahrt die Abschnittsgrenze.
- Raum wird auf zweitem Lehrergerät übernommen, verlängert und beendet.
- KI-Button ist ohne Schlüssel deaktiviert; mit erlaubtem Schlüssel liefert nur
  registrierte Aufgabe Feedback; Antworttext wird nicht persistiert.
- Zwei Räume und zwei Lehrkräfte vermischen keine Zustände.

## Betriebsprüfung

Nginx-/PHP-Konfiguration testen, öffentliche Header prüfen, Backups außerhalb
des Webroots erzeugen, Integrität prüfen und Restore in isolierter Umgebung
durchspielen. Eine Seite gilt nicht als vollständig getestet, wenn nur lokales
DOM oder ein einzelner Tab geprüft wurde.
