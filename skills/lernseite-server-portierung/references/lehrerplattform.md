# Zentrale Lehrerplattform und KI-Gateway

Diese Referenz gilt, sobald mehrere Lehrkräfte, mehrere LernHTMLs, geräteübergreifende Kursräume oder eigene beziehungsweise schulische API-Schlüssel verwaltet werden. Die Plattform ist eine gemeinsame Infrastruktur; Lernseiten binden sie über kleine Adapter ein und dürfen sie nicht kopieren.

## Architekturgrenze

```text
öffentlicher Webroot
├─ /lehrer/                         persönliche Anmeldung und Verwaltung
├─ /zugriff-anfragen/               moderiertes Kontaktformular
├─ /api/teacher-platform/feedback.php
└─ /bereiche/…                      Schüler-, Lehrer- und Beamerbuilds

außerhalb des Webroots
├─ Anwendung und serverseitige Aufgabenregister
├─ SQLite-Datenbank
├─ persistente Modul-Raumverzeichnisse
├─ verschlüsselter Secret-Tresor
└─ Master-Key mit root:www-data und 0640
```

- Nur exakt benannte PHP-Routen ausführen. Datenbank, Schlüssel, Registry, Logs und Konfiguration nie unter einer öffentlich auslieferbaren Route ablegen.
- Die Kontoplattform verwaltet Benutzer, Organisationen, Mitgliedschaften, Einladungen, Raummirror, Schlüssel, Kontingente und Audit. Der bestehende Klassenraumkern bleibt für Freigabe, Polls, Kartenwand, Timer und Präsentation zuständig.
- Ein Lernmodul registriert stabile `moduleSlug`, Bezeichnung sowie Schüler-, Lehrer- und Beamer-URL. Die Raum-API spiegelt beim Anlegen Besitzer, Organisation, Ablauf und Feedbackstatus in die Plattformdatenbank.
- Mehrwöchige Raumdateien liegen unter einem persistenten Datenroot wie `/var/lib/teacher-platform/modules/<moduleSlug>/rooms`, niemals unter `/tmp` oder im Webroot. Tägliche Wartung löscht ausschließlich abgelaufene Zustände.

## Konten und Rollen

- Rollen mindestens `admin` und `teacher`; Passwörter mit Argon2id, Sitzungen mit `Secure`, `HttpOnly`, `SameSite=Lax`, Session-Regeneration und Auth-Version.
- Normale Lehrkräfte sehen und steuern ausschließlich eigene aktive Räume. Administration darf zur Unterstützung alle Räume sehen, Konten sperren und Organisationen verwalten.
- Ein früheres gemeinsames Lehrerpasswort darf nur die einmalige Ersteinrichtung des ersten Administratorkontos autorisieren. Danach führt jede Lehrerroute zum zentralen Login.
- Zugriffsanfragen speichern nur erforderliche Kontaktdaten, Einwilligung und gehashten Anschlussnachweis. Honeypot, signierter zeitgebundener Formulartoken und Rate-Limit verwenden. Annahme erzeugt einen einmaligen, ablaufenden Einladungslink.

## Schlüssel und Feedback

- Persönliche und Organisationsschlüssel mit Sodium Secretbox und getrenntem Master-Key verschlüsseln. Schlüssel werden nach Speicherung nur noch als boolescher Zustand gezeigt.
- Auswahlreihenfolge: persönlicher Schlüssel der Raumbesitzerin/des Raumbesitzers → Organisationsschlüssel → Feedback nicht verfügbar.
- Die LernHTML enthält nur `moduleSlug`, relativen Feedback-Endpunkt und stabile `data-feedback-task`-IDs. Operator, AFB, gA/eA, Materialkontext, Kriterien, Mindest-/Maximallänge sowie Promptversion liegen im serverseitigen Register.
- Der öffentliche Endpoint validiert aktiven Raum, Raumfreigabe, registrierte Aufgabe, Längen-, Browser-, Lehrer-, Raum- und Organisationskontingente. Bei gesetztem `Origin` nur Same-Origin akzeptieren und zusätzlich grob pro Anschluss bremsen.
- Antworten nur für den Modellaufruf verarbeiten, nicht in Datenbank, Raumzustand, Export oder technische Logs schreiben. Responses API mit `store: false` und strukturiertem Schema verwenden. Anbieterfehler intern protokollieren, aber keine Rohmeldungen an Schüler ausgeben.

## Mail und Betreiberbetrieb

- Zugriffsanfragen müssen auch bei Mailausfall sicher gespeichert werden. Versand wahlweise über vorhandenes Sendmail oder verschlüsselt gespeicherte SMTP-Konfiguration; eine Testnachricht wird nur bewusst durch Administration ausgelöst.
- Einladungslinks werden bei fehlendem Mailversand genau einmal kopierbar angezeigt. Tokens nur gehasht speichern und nach Annahme als benutzt markieren.
- Monatliche Organisationskontingente, stündliche Lehrerkontingente, Raumtageslimit sowie Aufgaben-/Browserlimit sichtbar und nachvollziehbar konfigurieren.
- Keine Schülerprofile, Noten oder Antwortverläufe bilden. Audit enthält nur technische Ereignisse und Metadaten, keine Antworten oder Schlüssel.
- Täglich konsistente SQLite-Backups mit Integritätsprüfung außerhalb des Webroots anlegen, Aufbewahrung begrenzen und Restore regelmäßig isoliert testen. Der Master-Key wird getrennt durch System-/Proxmox-Backup gesichert.
- Vor Aktivierung von KI-Feedback Datenschutzhinweise, Anbieterbedingungen, Verantwortlichkeiten, Rechtsgrundlage, Fristen und schulische Freigabe prüfen. Die Technik allein begründet keine Zulässigkeit.

## Deployment und Abnahme

1. Vorhandene Webroute, Nginx-Includes, PHP-Module, SQLite, Sodium, OpenSSL, Sendmail, RAM, Swap und freien Speicher read-only inventarisieren.
2. Exakte bestehende Lernseite und Bereichsübersicht sichern. Neue Plattform zuerst außerhalb des Webroots kopieren und alle PHP-Dateien serverseitig linten.
3. Master-Key und Datenverzeichnis mit minimalen Rechten erzeugen, Migration als Webserverbenutzer ausführen, dann eng begrenzte Nginx-Locations installieren und `nginx -t` ausführen.
4. Öffentliche Assets und Endpunkte dateigenau kopieren; Modulbuild erst danach auf zentrale Auth und Registry umstellen.
5. Preflight, Migrationstest, Tresor-Rundlauf mit Testschlüssel, öffentliche Header, Same-Origin, deaktiviertes Feedback ohne Raum sowie Login-Redirect prüfen.
6. Die erste reale Kontoanlage und echte API-/SMTP-Schlüssel bleiben bewusste Eingaben des Betreibers. Geheimnisse niemals in Terminalkommandos, Buildskripte oder Chatprotokolle schreiben.
7. Nach Einrichtung Ende-zu-Ende mit Lehrkraft, Schüler und Beamer testen: Raum am PC anlegen, am zweiten Gerät öffnen, Feedback ein/aus, Ablauf ändern, Raum beenden, fremdes Konto abweisen und keine Antwortpersistenz nachweisen.
8. Wartung, Backup, Integritätsprüfung und vollständigen Restore in einer isolierten Testumgebung nachweisen; erst danach weitere Module freischalten.

Bei Änderungen an Plattformkern, Datenbankschema oder Schlüsselverwaltung immer versionsbezogene Sicherung und Migrationspfad vorsehen. Einen Pilot-Lernpfad vollständig abnehmen, bevor weitere Module nur noch über denselben Adapter registriert werden.
