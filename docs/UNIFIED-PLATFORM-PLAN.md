# Gemeinsamer Zugang für LernHTML und PrüfungsAPP

Stand: 6. September 2026. Auftrag: Beide Architekturen so erweitern, dass Lehrkräfte und Lernende dieselbe Anmeldung verwenden, LernHTML und PrüfungsAPP einzeln oder kombiniert einsetzen und die Administration alle Konten, Freigaben und Zuordnungen verwalten kann. Unterrichtsstände sollen direkt in der Lehreransicht erreichbar und ausgewählte Lösungen am Beamer vorzeigbar sein.

## Ausgangspunkt und Sicherung

- PrüfungsAPP: `D:\KI-Projekte\Apps\PrüfungsAPP`, GitHub `markuspillermusic-hash/Pr-fungsAPP`, `main` bei `ad8de046201e13329112c2ce0cbe604f99720ef5`, vor Beginn sauber; Flask, SQLite, lokale Lehrerkonten, permanente Lernende, Klassen, Portal, LTI/ByCS.
- LernHTML: `Lern-THML` ist das fachübergreifende Runtime-Repository. `_shared` ist der aktuell von den Modulbuilds verwendete Paketstand. Die ausführbaren PHP-Dateien stimmen überein; lokale Modulregistrierungen unterscheiden sich. Änderungen werden im Repository entwickelt und gezielt nach `_shared` übernommen. Lokale Inhaltsregistrierungen bleiben erhalten.
- Die vorhandene PHP-Plattform auf `markuspiller.de` verwaltet bereits mehrere Organisationen, persönliche Lehrerkonten, MFA, Einladungen, Support, Räume und verschlüsselte Schlüssel. Die PrüfungsAPP auf `pruefungsapp.markuspiller.de` verwaltet eine Schule pro Installation.
- Vollständiger lokaler Stand vor Änderungen: `D:\KI-Projekte\Archive\Lernplattform-vor-Umbau-20260906-134604`. Enthält Quellarchive einschließlich unversionierter Änderungen, gemeinsamen Paketen und Ethikmodul sowie Git-Bundles beider Repositories. Die ältere App-Konsolidierung bleibt zusätzlich erhalten.
- Produktive Datenbanken, Uploads, Raumzustände und Konfiguration werden vor einem produktiven Eingriff nochmals konsistent gesichert und isoliert wiederhergestellt. Lokale Quellsicherungen ersetzen kein Datenbankbackup.

## Zielarchitektur

Die vorhandene PHP-Plattform wird zur gemeinsamen Zugangs- und Unterrichtsplattform erweitert. Sie bleibt ohne PrüfungsAPP einsetzbar. Die PrüfungsAPP bleibt mit ihrer lokalen Anmeldung selbstständig installierbar und kann sich zusätzlich mit einer Organisation der zentralen Plattform verbinden.

```text
                         Gemeinsamer Einstieg
                     Lehrkraft / Schülerin / Schüler
                                  |
                Zentrale Identität, Organisation und Rechte
                       /                         \
             LernHTML-Arbeitsbereich       PrüfungsAPP-Installation
             Räume und Lernmaterial        Prüfungen und Übungen
             Mitschriften und Karten       Bewertung und Rückgabe
                       \                         /
                    gemeinsame Personen- und Klassen-IDs
```

Produkte `learning` und `assessment` werden getrennt pro Lehrerkonto und Organisation freigegeben. Ein fehlendes Produkt wird nicht nur ausgeblendet, sondern an seinen APIs gesperrt. Globale Administration darf beide Produkte, Organisationszugehörigkeiten und Installationen verwalten. Eine externe Lehrkraft erhält dadurch keinen Zugriff auf eine fremde Schule. Jede PrüfungsAPP-Installation ist genau einer Organisation zugeordnet; die bestehende Ein-Schul-Datenhaltung wird nicht als Mehrschulmandant missverstanden.

Die Verbindung verwendet OpenID Connect mit Authorization Code, PKCE/S256, nonce, exakter Rücksprungadresse und kurzlebigen, einmal nutzbaren Codes. Sitzungen bleiben pro Herkunft in sicheren Cookies; keine gemeinsamen Passwörter oder dauerhaften Zugangstoken in Links. Die bestehenden lokalen Anmeldewege bleiben im Einzelbetrieb verfügbar. Ein verbundenes Konto darf eine zentrale Sperrung nicht über seinen alten lokalen Zugang umgehen.

Bestehende Konten und Lernhistorien werden über explizite, überprüfte Identitätszuordnungen migriert. Gleicher Name oder gleiche E-Mail genügt nicht zum Zusammenführen. Wiederholtes Importieren darf keine doppelten Personen oder Klassen erzeugen. Vor dem Übernehmen wird ein nachvollziehbarer Abgleich mit Konflikten angezeigt.

## Arbeitsstände und Unterricht

- Die gemeinsamen Klassen und stabilen Schüleridentitäten gelten für beide Produkte. Login per persönlichem Einstiegslink/QR mit vorausgefülltem Anmeldenamen, Startpasswort und anschließend eigenem Passwort; keine offene Namensauswahl als Identitätsnachweis.
- LernHTMLs bleiben ohne persönliches Konto lokal und offline bearbeitbar. Im angemeldeten, zugewiesenen Unterricht speichert ein gemeinsamer Adapter Arbeitsstände automatisch in einem gesonderten, geschützten Lernstandsbereich. Die Oberfläche nennt Empfänger und Speicherstatus klar.
- Die Lehrkraft sieht Klasse → LernHTML → Person → aktuellen Arbeitsstand einschließlich Texten, Markierungen, Handschrift und Concept-Map. Sie kann genau eine Lösung für die Präsentation auswählen; die Beamerfreigabe veröffentlicht keine Klassenliste oder gesamte Mitschrift.
- Anonyme Abstimmung, Timer und Raumfreigabe bleiben im Klassenraumkern. Individuelle Texte werden nicht in öffentliche Raumantworten oder KI-Protokolle aufgenommen.
- Bei Netzverlust bleibt lokales Arbeiten möglich. Wiederanmeldung und Gerätewechsel erhalten den Stand. Versionskonflikte werden sichtbar aufgelöst, nicht durch stilles Überschreiben.
- Aufbewahrung, Export, Löschung, Kontosperrung und Klassenwechsel sind zentrale Adminfunktionen. Lehrerzugriff folgt der expliziten Klassenzuordnung. Die fachliche Position wird nicht automatisch benotet.

## Umsetzung und Abnahme

1. [x] Aktuelle Quellen sichten und lokal sichern; Plan schriftlich festhalten.
2. [ ] Gemeinsames Datenmodell und additive Migrationen: Identitäten, Organisationen, Produktrechte, App-Installationen, Klassen und Zuordnungen. Bestandsmigration und Wiederholbarkeit prüfen.
3. [ ] OIDC-Anmeldung zwischen PHP und Flask mit Rollenprüfung, expliziter Kontoverknüpfung, Logout/Sperrung und unabhängigem Einzelbetrieb fertigstellen.
4. [ ] Gemeinsame Klassenverwaltung, Schüleraktivierung und prüfbare Übernahme vorhandener App-Klassen und Lernhistorien fertigstellen.
5. [ ] Geschützte Lernstands-API, automatische lokale/serverseitige Synchronisation, Lehrerübersicht und ausgewählte Beamerpräsentation integrieren. Ethikmodul als vollständiger Pilot; gemeinsame Pakete für alle LernHTMLs verfügbar machen.
6. [ ] Gemeinsamen Einstieg und Adminoberflächen umsetzen: Produkte freigeben, Konten/Klassen/Installationen verwalten und Arbeitsstände einsehen. Einzel- und Kombinationsbetrieb tatsächlich bedienbar machen.
7. [ ] Migration und Restore an Kopien, Berechtigungstests für alle Rollen und Produkte, echte HTTP-/Browserläufe mit getrennten Sitzungen, Offline-/Konfliktfälle und Regressionen beider Systeme prüfen.
8. [ ] Versionen und Betriebsdokumentation aktualisieren, in Git sichern, veröffentlichen und entsprechend dem freigegebenen Projektworkflow deployen. Produktivrevisionen, vorhandene Konten und den Ende-zu-Ende-Unterrichtsfluss prüfen.

Ein Schritt ist erst erledigt, wenn seine Oberfläche und Serverfunktion zusammen geprüft sind. Bestehende Tests allein beweisen weder die gemeinsame Anmeldung noch den Lernstandsfluss.

## Technische Referenzen

- [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html)
- [PKCE, RFC 7636](https://www.rfc-editor.org/rfc/rfc7636)
- [OAuth Security Best Current Practice, RFC 9700](https://www.rfc-editor.org/rfc/rfc9700)

VIDIS kann später als weiterer Identitätsanbieter hinzukommen. Der jetzige Umbau wartet nicht auf dessen externe Anbieterregistrierung und behauptet keine bereits freigeschaltete VIDIS-Verbindung.

## Fortschrittsprotokoll

2026-09-06: Beide Quellen geprüft, fünf lokale Sicherungsdateien erzeugt und gehasht. Die explizit gewünschte persönliche Synchronisation erweitert die bisherigen Skillregeln zur ausschließlich lokalen Mitschrift; der anonyme Raum bleibt weiterhin getrennt. Noch keine Produktivdaten oder Zugänge umgestellt.

2026-09-07: Datenmodell, OIDC, Klassenabgleich, persönliche Synchronisation und Administration implementiert; 399 App-Tests, 83 neue und 42 bestehende PHP-Prüfungen sowie JavaScript- und tatsächliche PHP/Python-Interop-Tests bestanden. Beide Anwendungen und der Ethik-Pilot veröffentlicht; Produktionsbackup mit isoliertem Restore und Migration erfolgreich. Die Verbindung zwischen den Anwendungen ist aktiviert. Persönliche Anmeldung und ausdrückliche Bestandskonto-Verknüpfung des Betreibers stehen noch aus; deshalb ist die vollständige Produktionsabnahme noch nicht abgeschlossen. Einzelheiten und Sicherungspfade im [Freigabenachweis](UNIFIED-PLATFORM-RELEASE-20260907.md).
