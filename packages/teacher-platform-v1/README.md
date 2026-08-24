# Teacher Platform v1.0.2

Referenzimplementierung für persönliche Lehrerkonten, Organisationen,
Zugriffsanfragen, mehrwöchige Kursräume und serverseitiges KI-Feedback.

Der historische PHP-Namespace `ReligionPlatform` bleibt in v1 zur
Abwärtskompatibilität bestehen. Die Plattform selbst registriert Module aller
Fächer über `registry/modules.php`.

## Installation

1. Paket außerhalb des Webroots ablegen.
2. `config/config.example.php` nach `/etc/teacher-platform/config.php` kopieren
   und Domain, Mailadressen und Pfade anpassen.
3. einen zufälligen Sodium-Hauptschlüssel unter
   `/etc/teacher-platform/master.key` anlegen;
4. nur die Dateien aus `public/` an die dokumentierten Routen veröffentlichen;
5. Migration, PHP-Lint, Nginx-Test und lokale Tests ausführen;
6. Wartung und Backup als tägliche Jobs einrichten und einen Restore testen.

API-Schlüssel, die echte Konfiguration, SQLite-Dateien, Raumdateien und
Hauptschlüssel dürfen nicht ins Repository oder in den Webroot gelangen.

Die enthaltenen Registry-Dateien sind Beispiele. Ein produktives Modul erhält
eine eigene serverseitige Feedback-Registry, deren Aufgaben-IDs exakt zum
Modulmanifest passen.

Seit v1.0.2 bewertet der globale Feedbackvertrag bei AFB-III-Aufgaben niemals
die persönliche Meinung oder die gewählte Schlussposition. Er prüft
ausschließlich Fachlichkeit, Materialbezug, offengelegte Kriterien, Argumente,
Gegenargumente und Abwägung; gegensätzliche tragfähige Urteile sind
gleichberechtigt. Fachlich verlangte Perspektiven – etwa ein christliches
Sachurteil – werden geprüft, ohne eine persönliche Identifikation zu
unterstellen.
