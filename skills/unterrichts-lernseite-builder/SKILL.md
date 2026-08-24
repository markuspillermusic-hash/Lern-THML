---
name: unterrichts-lernseite-builder
description: Erstellt, überarbeitet und prüft interaktive deutschsprachige LernHTMLs für beliebige Schulfächer – etwa Musik, Religion, Geschichte, Naturwissenschaften oder Veranstaltungstechnik. Verwenden bei Lernseite, Lernpfad, interaktiver HTML, digitalem Hefteintrag, Unterrichtssequenz, Quiz, Prüfungstraining, Lehrer-/Schüler-/Beameransicht, Klassenraum, Live-Abstimmung, Kartenwand oder optionalem KI-Feedback. Deckt didaktischen roten Faden, Lehrplan-/Niveaudifferenzierung, operatorengestützte Aufgaben in AFB I–III, Rollen-Build, mehrwöchige Kursräume, synchronisierte Präsentation, lokale Schülerarbeit, Medienrechte und QA ab. Für ein vorhandenes Fachprofil dessen Skill zusätzlich verwenden; für produktives Deployment zusätzlich lernseite-server-portierung.
---

# Unterrichts-LernHTML-Builder

Eine fachlich belastbare, zugängliche und im realen Unterricht steuerbare
LernHTML aus einer Autorenquelle erstellen. Didaktische Qualität und technische
Verlässlichkeit sind gleichrangige Releasebedingungen.

## Zuerst prüfen

1. Projektordner, vorhandene LernHTMLs, Briefings, Lehrplanmaterial, Assets,
   frühere Korrekturen und lokale `AGENTS.md` vollständig sichten.
2. Thema, Jahrgangsstufe, Fach, Bundesland/Lehrplan, Zeitrahmen, Geräte,
   Prüfungsbezug und vorhandene Serverplattform bestimmen.
3. Bei zeitabhängigen Lehrplan-, Rechts-, Quellen- oder Technikfragen aktuelle
   Primärquellen prüfen. Interne Materialsammlungen sind keine veröffentlichbare
   Quellenangabe.
4. Im Workspace nach `Lern-THML` beziehungsweise nach
   `module-manifest.schema.json` suchen. Die versionierte Referenzlaufzeit und
   den Modulstarter verwenden; keinen neuen Klassenraumkern pro Seite bauen.

## Referenzen gezielt laden

- Für roten Faden, Erschließung, Operatoren, AFB, Differenzierung und
  Prüfungstraining [references/didaktik-und-operatoren.md](references/didaktik-und-operatoren.md) lesen.
- Für Autorenquelle, Manifest, Rollen-Build und lokale Schülerarbeit
  [references/architektur-und-rollen.md](references/architektur-und-rollen.md) lesen.
- Sobald Klassenraum oder Beamer vorkommt,
  [references/klassenraum-und-praesentation.md](references/klassenraum-und-praesentation.md) vollständig lesen.
- Sobald KI-Feedback vorkommt,
  [references/ki-feedback.md](references/ki-feedback.md) vollständig lesen.
- Vor Medienübernahme oder Veröffentlichung
  [references/medien-rechte-und-datenschutz.md](references/medien-rechte-und-datenschutz.md) lesen.
- Für fachspezifische Varianten [references/fachprofile.md](references/fachprofile.md) lesen und den passenden Fachskill ergänzen.
- Vor Abgabe [references/qa.md](references/qa.md) lesen und Manifest- sowie
  LernHTML-Validierung ausführen.

## Arbeitsablauf

1. **Lernziel und Leitproblem klären.** Einen konkreten irritierenden Fall oder
   eine echte Beobachtung wählen, aus der die nächste Frage nachvollziehbar
   entsteht.
2. **Bogen schreiben.** Vor dem Layout einen Satz formulieren, der erklärt, wie
   Einstieg, Erschließung, Systematisierung, Transfer und Rückkehr zum Einstieg
   zusammenhängen.
3. **Material vor Auswertung.** Lernende zuerst beobachten, lesen, hören,
   beschreiben, ordnen und begründen lassen. Keine Erkenntnisse oder
   Überschriften vorwegnehmen, die eigentlich erschlossen werden sollen.
4. **Aufgaben modellieren.** Operator, AFB, Materialbezug, Niveau, Sozialform,
   Zeit und erwartbare Eigenleistung festlegen. Jede Teilaufgabe erhält ein
   eigenes Eingabefeld; Erwartungshorizonte bleiben Lehrerinhalt.
5. **Differenzieren.** Ein selbstständig tragfähiges Grundniveau bauen.
   Vertiefungen nicht als bloßes „mehr Text“, sondern durch komplexere Quellen,
   Perspektivvergleich, Transfer oder Urteil gestalten und klar markieren.
6. **Einmal autorieren, dreifach bauen.** Lehrerblöcke semantisch markieren,
   stabile Präsentationsanker und Freigabestufen setzen, anschließend Schüler-,
   Lehrer- und Beamerartefakt aus derselben Quelle erzeugen.
7. **Manifest pflegen.** Lehrplan, Versionen, Routen, Laufzeit, Feedbackaufgaben,
   Datenschutz, Rechte und QA maschinenlesbar registrieren.
8. **Klassenraum deklarativ anbinden.** Gemeinsamen Kern verwenden; nur
   Moduldaten, Aktivitäten, Freigabestufen und Feedback-Registry ergänzen.
9. **Medien funktional wählen.** Jedes Bild, Audio oder Video braucht einen
   Lernzweck, eine Aufgabe, zugängliche Alternative und geklärte Rechte.
10. **Lokal und live prüfen.** Rollenartefakte, Persistenz, Export/Import,
    Mehrbrowser-Synchronisation, Beamergrenze, Timer, Medien, KI-Verfügbarkeit,
    mobile Breiten, Druck, Accessibility und Rechte testen.

## Unverhandelbare Regeln

- Keine separate technische Variante pro Fach oder Lernseite forken.
- Keine Lehrerantwort, kein Erwartungshorizont und kein API-Schlüssel in
  Schüler- oder Beamerdatei; CSS-Ausblenden ist kein Schutz.
- Schülertexte lokal halten. KI-Feedback nur über das serverseitige Gateway und
  nur nach eigener Bearbeitung anbieten; Antworten nicht serverseitig speichern.
- Klassenräume einem persönlichen Lehrerkonto zuordnen, flexibel per Kalender
  befristen, verlängern und früh beenden können.
- Auf dem Beamer nie den nächsten noch nicht freigegebenen Hauptabschnitt zeigen.
- Beamerzustand für Theme, Details, Karten/Stepper, Bilder, Audio, Video,
  Zeitstrahl und Timer mit der Lehreransicht spiegeln.
- Zusammenfassungen verlangen konkrete Inhaltswiedergabe, nicht die Aussage
  „Der Text handelt von …“.
- Keine vorweggenommene Musterlösung als Schüler-Arbeitsgrundlage ausgeben.
- Kein öffentliches Release bei ungeklärten Medien- oder Textrechten.
- Keine Live-Schaltung oder Serveränderung ohne ausdrücklichen Auftrag.

## Repository und Versionierung

Ausführbarer gemeinsamer Code gehört in das Referenz-Repository, nicht in den
Skilltext. Skills enthalten Entscheidungs- und Prüfwissen. Änderungen an
Klassenraumkern, Präsentation oder Plattform erhalten eine SemVer-Version,
Changelog/Testnachweis und werden erst danach in Module übernommen.

## Übergabe

Zuerst den erreichten Unterrichtsnutzen nennen. Danach Rollenartefakte,
Manifest, Testnachweise und offene fachliche/rechtliche Punkte verlinken. Keine
vollständige Funktionsbehauptung ohne echten Mehrbrowser-Test.
