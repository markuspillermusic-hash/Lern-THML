# Sicherheit und Datenschutz

## Geheimnisse

API-Schlüssel, Passwort-Hashes, Mailkennwörter und der Plattform-Hauptschlüssel
gehören weder ins Repository noch in LernHTMLs oder Raumdateien. Konfigurationen
werden außerhalb des Webroots gespeichert. Lehrer-Schlüssel werden mit einem
serverseitigen Hauptschlüssel verschlüsselt; bevorzugt wird ein von der Schule
verwalteter Organisationsschlüssel, alternativ ein persönlicher Schlüssel.

## Schülerdaten

- kein Schülerkonto für normale Lernpfade;
- pseudonymer sechsstelliger Raumcode;
- private Textfelder standardmäßig lokal im Browser;
- KI-Text nur transient für den konkreten Feedbackaufruf;
- keine Namen oder sensiblen Angaben in Aufgaben oder Prompt;
- Kartenwand mit Moderation, Längenlimit und ohne Klarnamen;
- automatische Löschung abgelaufener Räume sowie frühes manuelles Beenden.

## Serverkontrollen

Alle schreibenden Lehreraktionen benötigen authentifiziertes Konto, CSRF-Schutz
und Raum-Eigentümerschaft. Das Feedback-Gateway benötigt zusätzlich freigegebenen
Raum, registrierte Aufgabe, Quoten-/Rate-Limit und serverseitige Promptdefinition.
Antworten erhalten restriktive Header und werden nicht gecacht.

## Betrieb

Raumzustände liegen persistent außerhalb `/tmp` und des Webroots. Tägliche
Wartung löscht abgelaufene Daten. Konsistente Backups, Integritätsprüfung und ein
getesteter Restore gehören zum Betrieb. Der Hauptschlüssel muss separat über die
System-/Proxmox-Sicherung wiederherstellbar sein.

Der OpenAI-Aufruf verwendet die Responses API mit `store: false`, strukturierter
JSON-Schema-Ausgabe und einem pseudonymen `safety_identifier`. Das verhindert
keine technisch oder vertraglich vorgesehene Verarbeitung beim Anbieter;
aktuelle Datenkontrollen, Verträge und schulische Rechtsgrundlage müssen vor der
Aktivierung geprüft und transparent beschrieben werden.
