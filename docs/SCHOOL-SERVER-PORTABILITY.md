# Portabilität auf einen Schulserver

Die gemeinsame Unterrichtsplattform und die PrüfungsAPP bleiben getrennte,
konfigurierbare Anwendungen. Dadurch sind zwei Zielbilder unterstützt.

## Nur PrüfungsAPP

Die PrüfungsAPP wird mit ihrer verifizierten SQLite-Sicherung und dem
Uploadspeicher umgezogen. Auf dem Schulserver wird keine
`PRUEFUNGSAPP_PLATFORM_CONFIG` gesetzt. Lokale Lehrer- und Schülerkonten sowie
alle Prüfungsdaten verbleiben in der App. Zentral verknüpfte Schülerkonten
erhalten vor der Abschaltung kontrolliert neue lokale Aktivierungsdaten; zentrale
Passwort-Hashes werden nicht übertragen.

## LernHTML und PrüfungsAPP gemeinsam

Zusätzlich werden die Plattformdatenbank, das Datenverzeichnis, die geschützte
Konfiguration und der Sodium-Hauptschlüssel gemeinsam gesichert und auf dem
Schulserver wiederhergestellt. In der PrüfungsAPP werden anschließend nur
`issuer`, `client_id`, `client_secret`, `redirect_uri` und `organisation_id` auf
die neue Installation angepasst.

Die Prüfungsdatenbank bleibt auch in dieser Variante eigenständig. Die Plattform
liefert Anmeldung, Produktfreigaben und Klassenkennungen; Prüfungen, Antworten
und Rückgaben verlassen die PrüfungsAPP nicht.

## Anforderungen an den Zielserver

- HTTPS und dauerhaft erreichbare Hostnamen für zentrale Anmeldung und App
- PHP mit PDO-SQLite, Sodium, OpenSSL, ZIP und SimpleXML für die Plattform
- Python und die Abhängigkeiten der PrüfungsAPP
- getrennte Dienstkonten, Datenverzeichnisse und tägliche verifizierte Backups
- ein Testlauf des OIDC-Logins und des Klassenabgleichs in beide Richtungen

Keine Datenbanktabelle oder gespeicherte Identität enthält eine feste Bindung an
`markuspiller.de`. Domains und Rücksprungadressen sind Betriebskonfiguration.
