# Sicherheit und Datenschutz

## Lehrerlogin

- Für produktive Mehrmodulinstallationen persönliche Konten verwenden und Passwörter ausschließlich als Argon2id-Hash serverseitig halten.
- Login mit CSRF-Token, Sessionerneuerung und kurzer Fehlversuchsbremsung schützen.
- Die zentrale Plattform nutzt eine sichere globale Lehrersitzung; Modul-APIs prüfen zusätzlich Raum-Eigentümerschaft. Kein gemeinsames Passwort auf neue Module verteilen.
- Eine zufällige Auth-Version gemeinsam mit Hash/Salt erzeugen. Beim erfolgreichen Login in der Sitzung speichern und sowohl vor HTML-Ausgabe als auch bei jeder privilegierten API-Aktion vergleichen. Bei Passwortwechsel Auth-Version mitwechseln; alte Sitzungen werden damit sofort ungültig.
- Lehrerroute `noindex`, `no-store` und mit CSP ausliefern.
- Ein alter gemeinsamer Passwortprüfer darf höchstens die einmalige lokale Ersteinrichtung des ersten Administratorkontos autorisieren und wird danach abgeschaltet. Passwörter nie als Kommandozeilenargument verwenden.

## API

- Nur POST und JSON akzeptieren, Größe begrenzen und Eingaben normalisieren.
- Lehreraktionen anhand vorhandener Session autorisieren.
- Öffentliche Status-/Vote-Aufrufe ohne Lehrercookie nicht mit neuer Session versehen.
- Dateinamen ausschließlich aus validierten Raumcodes bilden.
- Ein freiwilliges Lehrerkürzel nur zur lokalen Wiedererkennung vorbereiteter Räume verwenden; nicht als Authentifizierung oder Schülerdatum behandeln.
- File-Locks und feste Ablaufzeit verwenden.
- Ablaufzeiten serverseitig gegen eine Mindest- und Höchstfrist validieren; beim Verlängern/Verkürzen dieselbe privilegierte Lehrerautorisierung wie bei anderen Raumänderungen verlangen. Aufräumen nach dem im Raumzustand gespeicherten `expiresAt`, nicht nach bloßer Dateiänderungszeit.
- Moderierte Kartenwände öffentlich nur mit freigegebenen Karten ausliefern. Wandsteuerung und Moderation sind Lehreraktionen; Länge, Kategorien, Anzahl und Raumstatus serverseitig validieren.
- Antwortänderungen atomar unter demselben Lock verrechnen. Mehrteilige Checks nur als Summen je Frage/Option speichern, nie als Antwortvektor.
- Fehler als kontrolliertes JSON ohne Pfade oder Stacktraces ausgeben.

## Persönliche API-Schlüssel für KI-Feedback

- Persönliche und schulische Schlüssel werden im zentralen geschützten Lehrer-/Organisationskonto verwaltet, nicht pro Lernseite oder Raum. Sie werden niemals an Schüler-, Lehrer- oder Beamerbrowser zurückgegeben.
- Den Schlüssel mit einem separaten Master-Secret authentifiziert verschlüsseln und außerhalb von Webroot, Raumdatei und normalen Logs speichern. Backups dürfen nur verschlüsselte Tresordaten enthalten; der Master-Key wird getrennt über die Systemsicherung geschützt.
- Öffentliche Raumantworten enthalten nur `feedbackEnabled` und anonyme Limits/Zähler. Auch die Lehrerantwort gibt den Schlüssel nie zurück.
- Beim Raumende oder Ablauf Feedback sperren, aber Kontoschlüssel nicht löschen. Ersetzen und Entfernen des Schlüssels sind ausdrücklich privilegierte Konto-/Organisationsaktionen.
- Modellaufrufe ausschließlich serverseitig, auf registrierte Aufgaben-IDs beschränkt, mit Eingabe-/Ausgabelimit, Rate- und Kostenlimit sowie ohne Persistenz der Schülerantwort durchführen.

## Browser

- CSP auf gleiche Herkunft beschränken; keine Tracker, Remote-Fonts oder versteckte Drittanfragen ergänzen.
- Bei Dialogen Fokus setzen, Tab einschließen, Escape ermöglichen und Fokus zurückgeben.
- Ergebnisexport ohne aktive Skripte erzeugen und heruntergeladene HTML mit restriktiver CSP versehen.
- Assetversionierung statt dauerhafter Cachedeaktivierung verwenden.
- Externe Videoeinbettungen nur in der CSP freigeben, wenn die konkrete Ansicht sie benötigt; Beamer- und Lehrerroute weiterhin so eng wie möglich halten.

## Transparenz

Technische Speicherinformation in einfacher Sprache anbieten:

- welche Eingaben lokal bleiben
- welche Live-Daten an den Server gehen
- dass nur Summen in der Anwendung gespeichert werden
- Ablaufzeit und manuelles Raumende
- Ergebnisdownload auf dem Lehrergerät
- mögliche Hosting-/Proxy-Logs
- ByCS erst nach bewusstem Upload

Diese Information ersetzt keine vollständigen allgemeinen Datenschutzhinweise des Betreibers. Verantwortlichen, Hostingrollen, Logs, Empfänger, Rechtsgrundlage, Fristen und Betroffenenrechte separat dokumentieren. Bei öffentlicher Website außerdem Impressum, Medienrechte und Barrierefreiheitsanwendbarkeit prüfen.
