---
name: lernseite-server-portierung
description: Portiert bestehende interaktive Lern-HTMLs sicher und dateigenau auf einen Webserver. Verwenden bei Veröffentlichung unter einer Unterseite, Trennung in Schüler-, Beamer- und passwortgeschützte Lehreransicht, PHP/Nginx-Einrichtung, gemeinsamem Stundencode, vorbereiteten Parallelräumen, QR-Beitritt, abschnittsweiser Schülerfreigabe, synchronisiertem Beamer/Audio/Video/Timer, anonymen Live-Abstimmungen und mehrteiligen Klassenchecks, Lernstand-Export/ByCS, Passwortrotation, Backups und produktiver Browser-QA. Besonders geeignet, wenn nur einzelne Lernseiten ersetzt werden dürfen und alle Nachbarseiten unverändert bleiben müssen. Nicht für die fachlich-didaktische Neuerstellung; dafür den passenden Lernpfad-Skill verwenden.
---

# Lernseiten-Serverportierung

Eine vorhandene Lernseite produktionsfähig trennen und veröffentlichen, ohne den übrigen Webbereich zu beeinträchtigen. Infrastrukturentscheidungen nie ohne Evidenz oder Zustimmung treffen.

## Referenzen gezielt laden

- Für Modulmanifest, Paketversionen und Freigabevertrag [references/modulmanifest-und-versionen.md](references/modulmanifest-und-versionen.md) lesen.
- Für Routen, Ansichten, Datenflüsse und Entwurfs-/Produktionsgrenze [references/architektur.md](references/architektur.md) lesen.
- Vor Nginx-/PHP-Änderungen [references/nginx-und-php.md](references/nginx-und-php.md) lesen.
- Für dateigenaues Backup, Overlay, Cache und Abnahme [references/deployment.md](references/deployment.md) lesen.
- Bei Live-Abstimmungen [references/live-abstimmung.md](references/live-abstimmung.md) lesen.
- Bei Beamerkopplung, Abschnittssperre, Timer, Audio/Video oder einem Code für die ganze Stunde [references/unterrichtssynchronisation.md](references/unterrichtssynchronisation.md) lesen.
- Sobald persönliche Lehrerkonten, geräteübergreifender Raumbesitz, Organisationsschlüssel, Zugriffsanfragen oder KI-Feedback vorkommen, [references/lehrerplattform.md](references/lehrerplattform.md) vollständig lesen.
- Bei Mitschrift, Gerätewechsel oder ByCS [references/bycs-und-speicherung.md](references/bycs-und-speicherung.md) lesen.
- Vor Livegang [references/sicherheit-und-datenschutz.md](references/sicherheit-und-datenschutz.md) lesen.

## Zuerst den Auftrag festnageln

1. Quellseite, Zielhost, exakte öffentliche URL und ausdrücklich zu erhaltende Nachbarseiten ermitteln.
2. Bestehende Serverstruktur, Webroot, Reverse Proxy, PHP-Laufzeit und Cache nur lesend prüfen.
3. Keine benachbarte Plattform, kein CMS und keinen Dienst auswählen, den der Nutzer nicht genannt hat. Eine frühere technische Umgebung nicht auf ein sachfremdes Projekt übertragen.
4. Bei unklarer Zielarchitektur Optionen erklären und die Entscheidung einholen, bevor Dateien oder Serverkonfiguration verändert werden.
5. Vor jeder Änderung eine wiederherstellbare Sicherung mit Zeitstempel anlegen und den Pfad festhalten.

## Standardarchitektur

- Öffentliche Schülerseite unter der bestehenden URL erhalten.
- Beameransicht als eigene, nicht passwortgeschützte Route bereitstellen.
- Lehreransicht als eigene serverseitig geschützte Route bereitstellen.
- Ist eine zentrale Lehrerplattform vorhanden, die Lehrerroute an deren persönliche Sitzung anbinden und Räume dem Konto zuordnen; kein paralleles gemeinsames Seitenpasswort einführen.
- Lehrkraftinhalte aus Schüler- und Beamerdatei physisch entfernen; CSS-Ausblenden reicht nicht.
- Mitschrift, Handschrift und Selbstcheck lokal im Schülerbrowser halten; Export/Import anbieten.
- Live-Abstimmungen nur als anonyme Summen mit zufälligen Raumcodes, fester Ablaufzeit und getrennten Parallelräumen führen.
- Einen Stundencode für Ablaufkonfiguration, Freigaben, Abstimmungen und Timer verwenden; keine konkurrierenden Codes im normalen Unterrichtsfluss.
- Raumvorbereitung von konkreten Aktivitäten trennen: Räume dürfen am Vorabend bereitstehen, Fragen werden erst am fachlichen Einsatzort gestartet.
- ByCS zunächst als Abgabe-/Sicherungskanal behandeln, nicht als ungeprüfte SSO- oder API-Integration.

## Arbeitsablauf

1. **Inventarisieren.** Dateien, Medien, Remote-Fallbacks, Lehrerblöcke, lokale Speicherkeys, Skripte und bestehende Links erfassen.
2. **Zielbaum planen.** Exakte Routen für Schüler, Beamer, Lehrer, Assets und API festlegen. Überschreibungen und neue Dateien getrennt auflisten.
3. **Manifest validieren und Produktionsbuild erzeugen.** Aus einer Quelle drei Ansichten bauen; stabile Schritt-IDs vor der Rollentrennung vergeben, relative Pfade umschreiben, öffentliche Lehrerblöcke entfernen, `noindex` für Beamer/Lehrer setzen und deklarierte Asset-/Medienversionen vergeben.
4. **Serverfähigkeit prüfen.** PHP niemals hochladen, solange der Pfad PHP-Quelltext ausliefern könnte. Erst Nginx sichern, eng begrenzte Locations ergänzen, `nginx -t` ausführen und neu laden.
5. **Harmlosen PHP-Probe-Endpunkt testen.** Nur JSON ohne Geheimnis ausliefern; erst nach bestätigter PHP-Ausführung Authentifizierung und API übertragen.
6. **Overlay vorprüfen.** Medienkonflikte, Zielpfade und erlaubte Änderungen vollständig prüfen, bevor die erste Produktivdatei kopiert wird.
7. **Dateigenau deployen.** Niemals den gesamten Jahrgangs- oder Bereichsordner ersetzen oder bereinigen. Nur freigegebene Dateien und neue Unterordner kopieren.
8. **Hashes vergleichen.** Overlay gegen Ziel und alle nicht freigegebenen Bestandsdateien gegen das Backup prüfen. `scripts/verify_overlay.py` verwenden.
9. **Live testen.** Schüler-, Beamer- und Lehreransicht, Login und alte Sitzungen, Speicherstatus, Export/Import, Voll-/Kompaktmodus, Inline-Abstimmung, Antwortwechsel, Abschnittsfreigabe, Timer, Audio-/Videowiedergabe ohne Dopplung, gebündelten Klassencheck, zwei Parallelräume und Löschung prüfen.
10. **Cache beachten.** Geänderte statische Assets versionieren und die öffentlich gelieferte Datei hashen; nicht nur die Serverfreigabe prüfen.
11. **Nachbarseiten prüfen.** Übersicht, Zeitstrahl und ausdrücklich geschützte Routen live abrufen und Prüfsummen bestätigen.

## Sicherheitsregeln

- Passwort nie im HTML, JavaScript, Buildartefakt, Skill oder Terminalkommando speichern.
- Persönliche Konten mit Argon2id verwenden; echte Passwörter ausschließlich verdeckt im vorgesehenen Webformular oder über einen bewusst sicheren Betreiberkanal eingeben.
- Ein gemeinsames Lehrerpasswort ist nur ein dokumentierter Übergangs- oder Fallbackmechanismus für Installationen ohne Kontoplattform. Bei vorhandener zentraler Plattform Argon2id-Konten, globale sichere Sitzung und Auth-Version in Lehrerroute sowie Raum-API verwenden; der alte Passwortprüfer darf höchstens die einmalige Ersteinrichtung autorisieren.
- Sessioncookie `Secure`, `HttpOnly` und `SameSite=Lax` setzen; Session-ID nach Login erneuern.
- CSRF-Schutz, Fehlversuchsbremsung, `no-store`, CSP, `nosniff`, Referrer- und Frame-Schutz einsetzen.
- Öffentliche API-Aufrufe ohne vorhandene Lehrersession dürfen kein unnötiges Sitzungscookie erhalten.
- Keine Namen oder Klassenlistennummern für Live-Abstimmungen verlangen. Raumbezeichnungen ausdrücklich ohne Namen halten.
- Abstimmung nicht als prüfungssichere Identitäts- oder Einmalwahl-Lösung darstellen.
- Keine rekursiven Lösch- oder Überschreibbefehle auf Webroot, Bereichsroot oder unaufgelöste Variablen anwenden.

## Wiederverwendbare Ressourcen

- `scripts/generate_password_hash.py`: Salt und serverseitigen PBKDF2-Hash ohne Klartextdatei erzeugen.
- `scripts/render_server_module.py`: Vorlagen aus einer JSON-Konfiguration ohne Klartextpasswort in einen Buildordner rendern.
- `scripts/verify_overlay.py`: Overlay und geschützten Bestand read-only per SHA-256 prüfen.
- `scripts/validate_module_manifest.py`: Modulvertrag, Rollenartefakte, Rechte- und QA-Gates prüfen.
- `assets/config.example.json`: kopierbare Ausgangskonfiguration; nur in einer Projektkopie mit generiertem Salt/Hash verwenden.
- `assets/php-runtime-probe.php`: gefahrlose PHP-Ausführungsprobe.
- `assets/teacher-auth-prefix.php.tpl`: serverseitige Loginvorlage ohne Passwort.
- `assets/live-api.php.tpl`: dateibasierte Aggregat-API mit Räumen, Antwortänderung, Ergebnisstrategie, Timer und mehrteiligem Check.
- `assets/live-client.js` und `assets/live.css`: konservative Basisoberfläche für Raum, Einzelabstimmung, Antwortänderung und gemeinsamen Timer.
- `assets/nginx-locations.conf.tpl`: eng begrenzte FastCGI-Locations.

Die mitgelieferte Live-Oberfläche ist ein konservativer Basiskern. Für Abschnittsfreigabe, Timer, synchronisierte Medien und gebündelte Checks den Zustandsvertrag aus `references/unterrichtssynchronisation.md` projektspezifisch ergänzen. Vorlagen immer kopieren und konfigurieren; nie direkt im Skill mit echten Pfaden oder Geheimnissen überschreiben.

Bei vorhandenem Referenz-Repository dessen versionierte Klassenraum-, Präsentations- und Plattformpakete verwenden. Die historischen Einzelvorlagen in diesem Skill sind nur Migrationshilfen für Altseiten, nicht die Quelle für einen neuen Fork.

## Stoppbedingungen

Stoppen und nachfragen, wenn Zielroute, zu erhaltender Bestand, Serverzugang oder gewünschte Datenhaltung unklar bleibt. Bei fehlgeschlagenem `nginx -t`, PHP-Quelltextauslieferung, Medienkonflikt oder unerwarteter Hashänderung nicht weiter deployen.

## Übergabe

Live-URLs, Datenhaltung, Parallelverhalten, Backup, veränderte Dateien, Testergebnis und offene Betreiber-/Lizenzfragen knapp nennen. Niemals „rechtssicher“ versprechen.
