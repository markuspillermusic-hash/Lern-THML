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

## Datenschutz und Betrieb

Vor dem Senden auf Namen und sensible Angaben hinweisen. Schülertext nur für den
einzelnen Request übertragen, weder in Raumdatei noch Datenbank noch
Inhaltsprotokoll speichern. Nur Metadaten wie Zeitpunkt, Konto, Modul,
Aufgaben-ID, Token-/Kostenklasse, Latenz und Erfolg protokollieren. Rate-Limits,
Nutzer-/Raum-/Organisationsquoten, Timeout, Fehlermeldungen und Anbieterwechsel
vorsehen. Anbieter- und Schuldatenschutz vor Aktivierung klären.
