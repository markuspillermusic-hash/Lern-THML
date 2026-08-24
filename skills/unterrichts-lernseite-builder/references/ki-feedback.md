# KI-Feedback

## Ziel

KI-Feedback ist formative Überarbeitungshilfe nach einer eigenen Lösung. Es ist
keine Benotung, kein Ersatz für Lehrerkorrektur und kein Lösungsgenerator.

## Zugriff und Schlüssel

Lehrkräfte melden sich an der zentralen Plattform an. Administratoren können
Organisationen/Schulen und deren Kontingentschlüssel verwalten; externe
Lehrkräfte können ihren eigenen Schlüssel hinterlegen. Schlüssel werden
serverseitig verschlüsselt. Beim Raum legt die Lehrkraft lediglich fest, ob
Feedback aktiviert ist. Ohne auflösbaren Schlüssel bleibt der Schülerbutton
deaktiviert und erklärt den Grund.

## Serverseitige Registry

Jede zulässige Aufgaben-ID registriert:

- Titel, Operator, AFB und Niveau;
- verbindliche Materialgrundlage;
- fach-/lehrplanbezogene Kriterien;
- zulässige Länge und Promptversion;
- typische Grenzen und sachliche Sicherheitsregeln.

Browserangaben dürfen diese Regeln nicht überschreiben. Unbekannte Aufgaben,
falscher Raum, fremder Besitzer oder ausgeschöpfte Quote werden abgewiesen.

## Feedbackform

Rückmeldung bevorzugt in vier Teilen: Stärken mit Textbezug, wichtigster
Verbesserungsschritt, fachlicher/argumentativer Hinweis und konkrete
Überarbeitungsfrage. Keine fertige Musterlösung ausgeben. Unsicherheit benennen,
keine Note simulieren und nur Kriterien anwenden, die der Aufgabe entsprechen.

## Urteile und Stellungnahmen

- Nicht die persönliche Meinung, Überzeugung oder Schlussposition bewerten.
  Maßgeblich sind Sachrichtigkeit, Materialbezug, offengelegte Kriterien,
  Argumente, Gegenargumente, Abwägung und Folgerichtigkeit.
- Das Aufgabenraster muss gegensätzliche, sachlich vertretbare Ergebnisse
  zulassen. Zwei gleich gut begründete Gegenpositionen erhalten eine
  gleichwertige Rückmeldung.
- Keine private politische, religiöse, gesundheitliche oder biografische
  Offenlegung verlangen. Wo ein Fachprofil es trägt, darf stattdessen ein
  Sachurteil aus einer ausdrücklich benannten fachlichen Perspektive verlangt
  werden; bewertet wird dann deren korrekte Anwendung, nicht die persönliche
  Identifikation mit ihr.
- Vor Release jede Urteilsaufgabe mit mindestens zwei gegensätzlichen starken
  Antworten testen und auf versteckte Ergebnispräferenz prüfen.

## Datenschutz und Betrieb

Vor dem Senden auf Namen und sensible Angaben hinweisen. Schülertext nur für den
einzelnen Request übertragen, weder in Raumdatei noch Datenbank noch
Inhaltsprotokoll speichern. Nur Metadaten wie Zeitpunkt, Konto, Modul,
Aufgaben-ID, Token-/Kostenklasse, Latenz und Erfolg protokollieren. Rate-Limits,
Nutzer-/Raum-/Organisationsquoten, Timeout, Fehlermeldungen und Anbieterwechsel
vorsehen. Anbieter- und Schuldatenschutz vor Aktivierung klären.
