<?php
declare(strict_types=1);

$common = [
    'curriculum' => 'LehrplanPLUS Bayern, Katholische Religionslehre, Jahrgangsstufe 11; Christentum, griechische Philosophie, Scholastik und Wissenschaftsgeschichte; operatorengestützte kompetenzorientierte Rückmeldung',
    'minimum_chars' => 180,
    'maximum_chars' => 9000,
    'prompt_version' => 'kr11-block-b-feedback-2026-08-v1',
];

return [
    'block-b-wissensreise-zusammenfassen' => $common + [
        'title' => 'Die Wissensreise des Christentums zusammenfassen',
        'operator' => 'zusammenfassen und erklären',
        'afb' => 'I–II',
        'level' => 'Jgst. 11',
        'material' => 'Entwicklungskette: Jesusbewegung innerhalb des Judentums; Ausbreitung in griechischsprachigen Städten; griechische Sprache des Neuen Testaments; philosophische Durchdringung des Glaubens, besonders in platonisch-augustinischer Tradition; Weitergabe antiker Texte in östlichen, westlichen und arabischsprachigen Wissensräumen; Übersetzungs- und Kommentierungsnetzwerke christlicher, muslimischer und jüdischer Gelehrter; breitere Aristoteles-Rezeption im lateinischen Westen ab dem 12. Jahrhundert; Verbindung mit christlicher Theologie im 13. Jahrhundert.',
        'criteria' => ['konkrete Entwicklung statt bloßer Themenangabe', 'mindestens fünf sachlich richtige Stationen', 'zeitliche und kausale Übergänge', 'mehrsprachiges und multireligiöses Wissensnetzwerk', 'keine lineare oder monokausale Erfolgsgeschichte behaupten'],
    ],
    'block-b-quaestio-entwickeln' => $common + [
        'title' => 'Eine Mini-Quaestio zur Gotteserkenntnis entwickeln',
        'operator' => 'entwickeln und begründen',
        'afb' => 'II–III',
        'level' => 'Jgst. 11',
        'material' => 'Eine scholastische Quaestio klärt die Streitfrage, formuliert einen starken Einwand, nennt eine ernstzunehmende Gegenstimme und entwickelt eine begründete Antwort, die auf den Einwand reagiert. Streitfrage: Kann der Mensch mit Vernunft etwas über Gott erkennen? „Erkennen“ muss hinsichtlich Reichweite und Sicherheit geklärt werden.',
        'criteria' => ['Begriff erkennen präzisieren', 'starken statt leicht widerlegbaren Einwand formulieren', 'eigenständige Gegenstimme nennen', 'Antwort greift den Einwand erkennbar auf', 'Begriffe unterscheiden und Reichweite begrenzen', 'bewertet wird Argumentationsqualität, nicht religiöse Position'],
        'minimum_chars' => 260,
    ],
    'block-b-denkweg-beurteilen' => $common + [
        'title' => 'Anselms oder Thomas’ Startpunkt beurteilen',
        'operator' => 'beurteilen',
        'afb' => 'III',
        'level' => 'Jgst. 11',
        'material' => 'Anselm beginnt beim Gottesbegriff und konsequentem Denken; Thomas beginnt bei erfahrbarer Welt, Veränderung und Ursachen. In diesem Lernblock werden Startpunkt und Denkbewegung, nicht die vollständige Gültigkeit einzelner Gottesbeweise beurteilt. Beide Wege können kriteriengeleitet stark oder begrenzt beurteilt werden.',
        'criteria' => ['fachliches Urteilskriterium offenlegen', 'gewählten Denkweg korrekt darstellen', 'Stärke und Grenze erläutern', 'anderen Denkweg als ernsthaften Einwand einbeziehen', 'begründetes Urteil ohne Bewertung persönlicher Glaubensüberzeugung'],
    ],
    'block-b-scholastik-einordnen' => $common + [
        'title' => 'Bedeutung und Grenze der Scholastik einordnen',
        'operator' => 'erläutern und abgrenzen',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Scholastik verbindet religiöse Überlieferung, antike Philosophie und ein mehrsprachiges Wissensnetzwerk. Die Quaestio fördert präzises Fragen, geordnete Gründe, produktiven Widerspruch und systematische Antworten. Moderne Naturwissenschaft verlangt darüber hinaus unter anderem systematische Beobachtung, Experiment, Messung, Mathematik und empirische Reproduzierbarkeit.',
        'criteria' => ['historische Bedeutung konkret erklären', 'mindestens zwei methodische Leistungen nennen', 'Scholastik nicht mit moderner Naturwissenschaft gleichsetzen', 'Unterschied an nachvollziehbaren Kriterien begründen', 'keine geradlinige zwangsläufige Fortschrittserzählung'],
    ],
    'block-b-ockham-erklaeren' => $common + [
        'title' => 'Ockhams Scharnierfunktion zur Neuzeit erklären',
        'operator' => 'erklären',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Ockham bleibt Scholastiker, begrenzt aber schärfer die Reichweite von Vernunft und Erfahrung. Einzeldinge sind Ausgangspunkt; unnötige Zusatzannahmen sollen ohne ausreichenden Grund vermieden werden. Das Rasiermesser ist eine Sparsamkeitsregel und kein Wahrheitsbeweis. Natur kann eigenständiger untersucht werden; Gott ist damit weder bewiesen noch widerlegt, Glaubensaussagen sind anders begründet.',
        'criteria' => ['Kontinuität mit der Scholastik benennen', 'Vernunft, Erfahrung und Offenbarung unterscheiden', 'Sparsamkeitsregel korrekt erklären', 'Folge für Naturbetrachtung erläutern', 'Nichtfolgerung bezüglich Gottes Existenz ausdrücklich markieren'],
    ],
];
