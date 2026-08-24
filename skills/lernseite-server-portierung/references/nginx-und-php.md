# Nginx und PHP sicher aktivieren

## Vorprüfung

1. Nginx-Konfiguration und laufende Serverblöcke nur lesend erfassen.
2. Bestehenden PHP-FPM-Socket aus einer funktionierenden Site übernehmen, nicht raten.
3. Konfigurationsdatei mit Zeitstempel sichern.
4. Nur exakte Locations für Lehrerindex und API ergänzen.

Beispiel in `assets/nginx-locations.conf.tpl`. Platzhalter vor Einsatz ersetzen.

## Reihenfolge

1. Nginx-Konfiguration sichern.
2. Exakte Locations ergänzen.
3. Auf dem Server `nginx -t` ausführen.
4. Nur bei erfolgreichem Test `nginx -s reload` ausführen. Als `root` kein `sudo` voraussetzen.
5. Zielordner anlegen und ausschließlich `php-runtime-probe.php` als API-Datei kopieren.
6. Über Origin und öffentliche Domain prüfen: Status 200, JSON-Content-Type und erwarteter Probeinhalt.
7. Erst danach echte API- und Lehrer-PHP kopieren.
8. GET auf API muss eine kontrollierte JSON-Fehlermeldung liefern, niemals PHP-Quelltext.

## Gründe für exakte Locations

- Keine pauschale PHP-Ausführung im gesamten statischen Webroot aktivieren.
- Upload- oder Fremddateien nicht versehentlich ausführbar machen.
- Andere Websites und Serverblöcke unberührt lassen.
- Lehrer- und API-Pfade auditierbar halten.

## Fehlerfall

- Bei `nginx -t`-Fehler nicht reloaden.
- Bei Quelltextauslieferung Probe sofort durch statische harmlose Datei ersetzen oder entfernen und Konfiguration korrigieren.
- Bei unbekanntem Socket, Containergrenze oder fehlendem Serverzugang Nutzer um den konkreten administrativen Schritt bitten; kein Passwort im Chat erfragen.
