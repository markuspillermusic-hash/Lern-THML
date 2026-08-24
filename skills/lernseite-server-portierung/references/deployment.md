# Backup, Overlay und Abnahme

## Preflight

- Modulmanifest validieren; Paketversionen, Rollenrouten, Rechteinventar und QA-Gates gegen den vorgesehenen Release abgleichen.
- Exakten Zielroot auflösen und mit dem vereinbarten Pfad vergleichen.
- Vollständige Sicherung des betroffenen Bereichs in einen getrennten Backupordner kopieren.
- Overlay außerhalb des Zielroots bauen.
- Liste aller überschriebenen und neu angelegten Dateien anzeigen.
- Existierende Mediendateien per SHA-256 vergleichen. Bei gleichem Namen und anderem Inhalt stoppen.
- Bei einer ausdrücklich gewünschten Medienersetzung denselben stabilen Zielpfad nur nach Quell- und Serverbackup überschreiben und alle HTML-Verweise mit einer neuen Cache-Version versehen.
- Unerwartete Dateien im vorgesehenen neuen Unterordner als Konflikt behandeln.

## Deployment

- Jede freigegebene Rootdatei einzeln kopieren.
- Neue Unterordner explizit anlegen.
- Medien nur ergänzen, wenn sie fehlen; identische Medien nicht unnötig überschreiben.
- Keine rekursive Spiegelung, Bereinigung oder Löschung des Bereichsroots verwenden.
- Nach dem Kopieren Overlaydateien gegen Ziel hashen.
- Backupdateien außerhalb der erlaubten Änderungsliste gegen den aktuellen Bestand hashen.

Beispiel:

```powershell
python scripts/verify_overlay.py "D:\build\site-overlay" "\\server\share\site\bereich" `
  --backup "\\server\share\_backups\bereich-vorher" `
  --allow-changed "einheit.html" `
  --allow-changed "datenschutz.html" `
  --allow-changed "einheit/**"
```

## Cache

- Statische CSS-/JS-Dateien mit einer Buildversion referenzieren, etwa `live.js?v=20260805-1`.
- Öffentliche Datei über die Domain erneut herunterladen und Hash mit dem Serverziel vergleichen.
- `cf-cache-status`, `Age`, `Cache-Control`, `ETag` und `Last-Modified` prüfen.
- Bei altem CDN-Asset nicht behaupten, das Deployment sei fertig; Version erhöhen oder autorisiert purgen.
- Auch große lokale Medien versionieren. Im Build-/Medienmanifest Query und Fragment vom physischen Dateinamen trennen.

## Testraum-Hygiene

- Für Browser-QA ausschließlich neu erzeugte, eindeutig mit `QA` oder `TEST` bezeichnete Räume verwenden. Code, neutrale Bezeichnung, Erstellzeit und Ablaufzeit unmittelbar protokollieren.
- Vorhandene Räume weder umbenennen noch verändern. Vor jeder schreibenden Aktion den exakten Testcode und seine Metadaten erneut prüfen.
- Lehrer-, Schüler- und Beamerrolle möglichst in getrennten Browserkontexten testen. Wenn ein Browserprofil verwendet wird, beachten, dass die Tabs `localStorage` teilen; den Schülerraum über den URL-Parameter festlegen und Testzustände klar namensräumlich trennen.
- Testräume über eine unterstützte, lehrerauthentifizierte Aktion gezielt löschen. Die Bereinigung nicht von einem fragilen Browser-Bestätigungsdialog abhängig machen.
- Nach dem Löschen den Status des exakten Codes erneut abrufen und den erwarteten Nicht-vorhanden-/404-Zustand dokumentieren.

## Abnahme

- Schüler-, Beamer-, Lehrer- und Datenschutzroute: HTTP 200.
- API: kontrollierte Methoden- und Authentifizierungsfehler.
- Lehrerlogin: korrektes und falsches persönliches Konto, Logout, Sessionerneuerung, Kontosperre und Auth-Version prüfen.
- Nach Passwortänderung oder Kontosperre darf eine alte Sitzung weder Lehrerseite noch privilegierte API-Aktion weiter autorisieren.
- Keine Lehrkraftinhalte oder Klartextpasswörter in öffentlicher Datei.
- Live-Raum: Erstellen, Stimmen, Verbergen, Freigeben, Verlauf, Export und Löschen.
- Zwei getrennte Lehrersessions gleichzeitig prüfen.
- Raumbeitritt per QR und Code, abschnittsweise Freigabe, Beamerfolge, Timer, Antwortänderung und gebündelten Klassencheck prüfen.
- Lokale Videos per Metadaten und Range-Request prüfen; Wiedergabe am Beamer ohne gleichzeitigen Ton am Lehrergerät starten.
- Übersicht, Zeitstrahl und andere geschützte Nachbarseiten live testen.
- Testräume und temporäre Probedateien abschließend entfernen beziehungsweise überschreiben.
- Wartungslauf, konsistentes Backup, Integritätsprüfung und isolierten Restore dokumentieren.

Die Live-Abnahme in einem echten Browser automatisieren, wenn mehrere Ansichten zusammenwirken. Entscheidend sind geladene öffentliche URLs und API-Antworten, nicht nur lokale Builddateien. Für Schlüsselzustände Screenshots sichern und tatsächlich ansehen. QA-Ausgaben außerhalb des Webroots speichern und nach der Freigabe gemäß dokumentierter Frist bereinigen.
