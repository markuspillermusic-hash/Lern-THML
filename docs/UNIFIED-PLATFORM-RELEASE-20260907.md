# Gemeinsame Plattform – Freigabenachweis

Stand: 07.09.2026. Dieser Bericht trennt lokale Funktionstests und Produktionsabnahme.

## Lokal implementiert

Zentrale Konten und Produktfreigaben, Organisationsgrenzen, Klassen und stabile Lernendenkennungen; optionaler OIDC-Zugang der Prüfungsapp; ausdrückliche Bestandsverknüpfung mit Vorschau; geschützte, verschlüsselte Mitschriften mit Versionsprüfung; Lehrerübersicht; gezielte Beamerfreigabe; Ethik-Pilot aus kanonischen Paketquellen neu gebaut. Einzelbetrieb bleibt erhalten. Vorarbeiten und ältere Ordner wurden vor dem Umbau gesichert bzw. archiviert, nicht endgültig gelöscht.

## Nachgewiesen

- Vollständige App-Suite: 399 Tests erfolgreich, ein optionaler PHP-Vertragstest nicht in diesem Lauf aktiviert. Dieser echte PHP→Python-OIDC-Vertrag wurde anschließend separat erfolgreich geprüft (fünf Identitätstests, keine ausgelassen).
- PHP: 95 gemeinsame Plattformprüfungen und 42 bestehende Plattformprüfungen erfolgreich, einschließlich Sperren und Reaktivieren über beide Administrationsoberflächen ohne Wiederbelebung alter Sitzungen.
- JavaScript: drei Synchronisations-Race-/Kontowechseltests erfolgreich.
- Ethik: Rollenbuild, semantische Manifestprüfung und vollständige JSON-Schema-Prüfung erfolgreich. Gemeinsamer Klassenraum 1.5.0, Präsentation 1.2.8, Cacheversion `20260907-kr13-1-1-v19`.
- Browser: gemeinsame Anmeldung und Administratoroberfläche geprüft. Frühere Testläufe bestätigten Bestandskontoverknüpfung, Klassenabgleich und die gezielte Textfreigabe Lehrer → Beamer mit synthetischen Konten.

## Produktion

- Prüfungsapp 1.6.0, Revision `3bd2076c9aef`: über `deploy/update.sh` gesichert, aktualisiert und öffentlich über `/api/app-info` bestätigt. Gemeinsame Anmeldung laut `/api/platform/session` aktiviert.
- Prüfungsapp-Backup `/var/backups/pruefungsapp-verified/20260907-115430-vor-update` einschließlich Uploads ohne Überschreiben in `/var/backups/pruefungsapp-verified/restore-test-20260907` wiederhergestellt; Integritätsprüfung erfolgreich.
- Unterrichtsplattform 2.0.0 unter `https://markuspiller.de/zugang/`. Nginx-Prüfung und harmlose PHP-Ausführungsprobe erfolgreich; danach additive Produktionsmigration und dateigenaues Overlay mit Dateivergleichen.
- Konsistentes SQLite-Backup und isolierter Restore samt zweimaliger Migration: `/var/backups/teacher-platform-upgrade/20260907-133300`. Benutzer-, Organisations- und Raumzahlen sowie sämtliche vorhandenen Passwort-Hashes erhalten; Integritäts- und Fremdschlüsselprüfung bestanden. Konfiguration, alte Quellen und betroffene öffentliche Plattformdateien separat in `before-files.tar` gesichert.
- Schulorganisation 1 und Installation `pruefungsapp-jmf` verbunden. Verbindungsschlüssel ausschließlich zwischen den vorhandenen Containern über Proxmox transferiert; App-Konfiguration `/etc/pruefungsapp/shared-platform.json`, root:pruefungsapp, 0640. Keine Schlüssel im Git oder öffentlichen Webroot.
- Ethik: 42 öffentliche Overlaydateien geprüft, alle nicht erlaubten Modulbestandsdateien unverändert. Backup `/websites/_backups/ethik-before-shared-access-20260907`, zusätzlich geschützte Materialien und Feedbackregister gesichert. Öffentlicher Synchronisations-JavaScript-Hash stimmt mit dem Build überein. Bereichsübersicht und benachbarte Ethik-der-Lebensbereiche-Seite weiterhin HTTP 200.
- Browser: produktiver OIDC-Start der Prüfungsapp führt korrekt zur zentralen Anmeldung. Persönliche Abnahme nach Anmeldung des Betreibers noch offen. Bestehende Schülerkonten und Klassen wurden nicht automatisch übernommen; ausdrückliche Bestandszuordnung bleibt ein Administrationsschritt.

## Noch ausstehende persönliche Abnahme

Abschlusskorrektur der Kontoverwaltung: Alte und neue Administrationsansicht ändern denselben wirksamen Kontostatus. Drei betroffene Dateien vorab unter `/var/backups/teacher-platform-upgrade/status-consistency-20260907/before-files.tar` gesichert, serverseitig geprüft und nach Übertragung gehasht. Das Deployment selbst verändert keine Konten.

Bestehendes Lehrerkonto im produktiven Login anmelden, einmalige Zuordnung zum alten Prüfungsapp-Konto mit dessen Passwort bestätigen, einen ausgewählten Klassenbestand in der Vorschau prüfen und bewusst übernehmen. Anschließend den Unterrichtsfluss mit einer freigegebenen Testklasse prüfen. Ohne diese Anmeldung wird keine vollständige persönliche Ende-zu-Ende-Abnahme behauptet.

## Datenschutz- und Betreiberprüfung

Die technischen Hinweise wurden an die ausdrücklich gewünschte persönliche Autospeicherung angepasst. Anonyme Abstimmung und KI-Gateway bleiben getrennt. Vor echten Klassen sind schulische Verantwortlichkeit, Rechtsgrundlage, Information, Aufbewahrung und gegebenenfalls Auftragsverarbeitung festzulegen. Bei religiösen/weltanschaulichen Angaben ist Art. 9 DSGVO besonders zu prüfen. Eine vollständige Impressums- und Betreiberprüfung ist in diesem technischen Umbau nicht nachgewiesen; keine Zusage „rechtssicher“.

Maßgebliche Primärquellen: [DSGVO](https://eur-lex.europa.eu/eli/reg/2016/679/deu), [DDG § 5](https://www.gesetze-im-internet.de/ddg/__5.html), [TDDDG § 25](https://www.gesetze-im-internet.de/ttdsg/__25.html). Die verlinkten Regelungen begründen keine pauschale Freigabe schulischer Datenverarbeitung. Es werden keine neuen Käufe, Werbeangebote oder Analyse-Tracker eingeführt.

Separater Betriebshinweis: Die Proxmox-Oberfläche meldet für den entfernten Backup-Speicher am 07.09.2026 einen Verbindungsfehler; ein anderer nächtlicher Backupjob ist erfolgreich. Die hier aufgeführten Upgrade-Sicherungen und isolierten Wiederherstellungstests waren erfolgreich, ersetzen aber keine Prüfung des entfernten Backup-Ziels. An dieser außerhalb des Umbauauftrags liegenden Konfiguration wurde nichts geändert.
