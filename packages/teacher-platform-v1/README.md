# Gemeinsame Unterrichtsplattform 2.1.0

2.1.0 ergänzt den geprüften Klassenlistenimport aus XLSX, XLSM, CSV, Text oder
der Zwischenablage. Owner und Editor können Listen selbst importieren und dabei
getrennt festlegen, ob neue Konten LernHTML, Prüfungsapp oder beide Angebote
verwenden. Identitäten werden weiterhin niemals allein anhand gleicher Namen
zusammengeführt. Eigene Klassen einer verbundenen Prüfungsapp können nun auch
von normalen berechtigten Lehrkräften veröffentlicht werden.

2.0.1 ergänzt die durch das aktuelle Passwort bestätigte Änderung des zentralen
Anmeldenamens. Verknüpfte Lernstände, Klassen und Prüfungsdaten bleiben über die
unveränderte Konto-ID erhalten.

Neu: optionaler Schülerzugang, getrennte Produktfreigaben für LernHTML und
Prüfungsapp, gemeinsame Klassen, OIDC, verschlüsselte persönliche Mitschriften
und gezielte Beamerfreigaben. Die Migrationen 5 und 6 sind additiv. Bestehende
Lehrerkonten behalten LernHTML-Zugriff; Installationen verbindet weiterhin nur
die Administration. Owner und Editor dürfen ihre eigenen bearbeitbaren Klassen
nach geprüfter Vorschau übernehmen. Der Pilot ist Ethik 13.1.1. Andere LernHTMLs
bleiben unverändert lokal, bis ihr Adapter und Lernstandsvertrag integriert wurden.

Siehe [Umsetzungsplan](../../docs/UNIFIED-PLATFORM-PLAN.md), [Freigabenachweis](../../docs/UNIFIED-PLATFORM-RELEASE-20260907.md) und [Synchronisationsvertrag](../learning-sync-v1/README.md).

Zentrale, datensparsame Verwaltung für die interaktiven Religions-Lernpfade auf
`markuspiller.de`.

Die Plattform ergänzt die Lernseiten um:

- persönliche Lehrer- und Administratorkonten,
- Organisationen/Schulen und Einladungen,
- öffentliche, moderierte Zugriffsanfragen,
- optionale Begründungen und getrennt widerrufbare Einwilligung in
  Änderungs-E-Mails zur kostenlosen Pilotphase,
- einen nachvollziehbaren Anfrageverlauf von „offen“ über „genehmigt – Registrierung ausstehend“ bis „registriert“,
- automatisch versendete, einmalige Einladungslinks mit Versandstatus und sicherer Erneuerung,
- einmalige, 45 Minuten gültige Passwort-Reset-Links und zentrale Administrationsauslösung,
- optionale TOTP-Zwei-Faktor-Anmeldung mit einmaligen Wiederherstellungscodes,
- Supportfälle mit Status, sichtbarem Verlauf und geschützten internen Notizen,
- serverseitig verschlüsselte persönliche oder schulische OpenAI-Schlüssel,
- widerrufbare, zeitlich und mengenmäßig begrenzte KI-Förderkontingente ohne Schlüsselweitergabe,
- flexible, einem Lehrer zugeordnete Kursräume,
- ein kontrolliertes, lehrplanbezogenes KI-Feedback-Gateway,
- Quoten, Rate-Limits und ein inhaltsfreies Nutzungsprotokoll.

Ohne persönliche Synchronisation können Schüler die Lernseiten weiterhin ohne
Konto bearbeiten. Im zugewiesenen, angemeldeten Unterricht speichert der neue
Lernstandsbereich Mitschriften verschlüsselt für die zuständigen Lehrkräfte.
API-Schlüssel und Passwörter gelangen weder in Lern-HTMLs noch in Raumdateien,
Browserstorage oder Exporte. Das getrennte KI-Gateway leitet Antworten nur für
den einzelnen Feedbackaufruf weiter und führt selbst kein Antwortarchiv.

Der Mailversand ist vom Konto- und Anfragefluss entkoppelt: Eine Anfrage wird
auch bei einem SMTP-Ausfall gespeichert. Genehmigungen bleiben sichtbar; der
Adminbereich zeigt Versand, Ablauf und Registrierung. Beim erneuten Versand
wird der frühere Link widerrufen. SMTP-Zugangsdaten liegen verschlüsselt im
Systemtresor. Eine reale Testnachricht wird ausschließlich durch eine bewusste
Adminaktion ausgelöst.

Mehrwöchige Kursräume liegen modulweise unter
`/var/lib/teacher-platform/modules/<modul>/rooms/` und damit außerhalb von
`/tmp` und des Webroots. Beim regulären Beenden werden ihre Zustandsdateien
entfernt; zusätzlich gilt das von der Lehrkraft gewählte Ablaufdatum.

## Verzeichnisse auf dem Server

- öffentlich: `/websites/markuspiller.de/lehrer/`
- öffentliche Anfrage: `/websites/markuspiller.de/zugriff-anfragen/`
- öffentliche Supportanfrage: `/websites/markuspiller.de/support-anfragen/`
- öffentliche API: `/websites/markuspiller.de/api/teacher-platform/`
- nicht öffentlich: `/websites/_protected/teacher-platform-v1/`
- Laufzeitdaten: `/var/lib/teacher-platform/`
- Konfiguration und Hauptschlüssel: `/etc/teacher-platform/`
- tägliche, konsistente SQLite-Backups: `/var/backups/teacher-platform/`

Produktivupdates werden aus einem aktuellen Checkout als root mit
`deploy/update.sh` ausgeführt. Das Skript prüft PHP und die Plattformtests,
erstellt vor jeder Änderung ein konsistentes Datenbank- und Dateibackup,
migriert additiv und prüft danach den öffentlichen Laufzeitendpunkt. Für einen
Schulserver lassen sich Ziel-, Webroot-, Konfigurations- und Backuppfad über die
`TEACHER_PLATFORM_*`-Variablen des Skripts setzen.

Die erste Administration wird nicht mit einem fest eingebauten Passwort
angelegt. Solange noch kein Konto existiert, kann der Besitzer die Einrichtung
mit dem bisherigen gemeinsamen Lehrerpasswort autorisieren und unmittelbar ein
persönliches Administratorkonto samt neuem Passwort erstellen. Danach schließt
sich die Ersteinrichtung automatisch.

## Entwicklungs- und Freigaberegel

Q12.1.1 ist der Pilot. Die bewährte Klassenraum-/Beamermechanik bleibt bestehen;
die zentrale Plattform wird über einen kleinen Serveradapter angebunden. Erst
nach dem Pilot-QA wird dieselbe Adapterversion in weitere Lernpfade übernommen.

Die Datenbank wird täglich um 03:17 Uhr mit der SQLite-Backup-API gesichert und
anschließend per `PRAGMA integrity_check` geprüft. Backups bleiben standardmäßig
30 Tage erhalten und liegen mit Modus `0600` außerhalb des Webroots. Für eine
vollständige Wiederherstellung muss zusätzlich der Hauptschlüssel aus
`/etc/teacher-platform/master.key` über die reguläre Proxmox-/System-Sicherung
erhalten bleiben; er wird bewusst nicht neben die Datenbankkopien dupliziert.

Um 03:12 Uhr entfernt die Wartung abgelaufene Raumzustände, alte Rate-Limits,
abgeschlossene Zugriffsanfragen sowie abgelaufene Einladungen nach den auf der
Datenschutzseite ausgewiesenen Fristen. Dazu gehören auch verbrauchte
Passwort-Reset-Token, geschlossene Supportfälle und lange abgelaufene
Förderkontingente. Ausformulierte KI-Antworten werden grundsätzlich nicht in
der Plattformdatenbank abgelegt.
