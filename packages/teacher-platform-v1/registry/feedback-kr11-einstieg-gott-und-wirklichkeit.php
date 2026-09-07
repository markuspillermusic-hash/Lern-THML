<?php
declare(strict_types=1);

$common = [
    'curriculum' => 'LehrplanPLUS Bayern, Katholische Religionslehre, Jahrgangsstufe 11; Lernbereich Gott und Wirklichkeit; operatorengestützte kompetenzorientierte Rückmeldung',
    'minimum_chars' => 180,
    'maximum_chars' => 9000,
    'prompt_version' => 'kr11-einstieg-feedback-2026-08-v1',
];

return [
    'einstieg-hartmann-rekonstruieren' => $common + [
        'title' => 'Hartmanns Argument fair rekonstruieren',
        'operator' => 'zusammenfassen und rekonstruieren',
        'afb' => 'I–II',
        'level' => 'Jgst. 11',
        'material' => 'Videoabschnitt 01:17–06:14. Hartmann verbindet die ausbleibende eindeutige wissenschaftliche Nachweisbarkeit Gottes mit dem Verdacht, Gottesvorstellungen könnten aus menschlichen Wünschen hervorgehen. Die Antwort ist in Behauptung, Gründe beziehungsweise Beispiele und Schluss gegliedert. Bewertet werden nur Aussagen, die die Lernenden dem Video nachvollziehbar entnehmen; exakte Formulierungen dürfen nicht erfunden werden.',
        'criteria' => ['zentrale These sachgerecht benennen', 'Gründe und Beispiele von der These unterscheiden', 'Schlussfolgerung nachvollziehbar rekonstruieren', 'faire Wiedergabe ohne vorschnelle Bewertung', 'erkennbare Verbindung der drei Antwortteile'],
    ],
    'einstieg-hartmann-beurteilen' => $common + [
        'title' => 'Hartmanns Argument beurteilen',
        'operator' => 'beurteilen',
        'afb' => 'III',
        'level' => 'Jgst. 11',
        'material' => 'Hartmanns Argument betrifft Gottes Verborgenheit, wissenschaftliche Beweisbarkeit und den Projektionsverdacht. Aus dem möglichen Wunschursprung einer Vorstellung folgt logisch weder automatisch ihre Falschheit noch ihre Wahrheit. Ein Urteil kann zustimmend, ablehnend oder abgestuft ausfallen.',
        'criteria' => ['mindestens ein offengelegtes Kriterium', 'Argument und Ergebnis statt persönliche Glaubensposition bewerten', 'Materialbezug', 'starken Einwand fair berücksichtigen', 'Abwägung und klar begründetes Urteil'],
    ],
    'einstieg-lennox-rekonstruieren' => $common + [
        'title' => 'Lennox’ Antwort rekonstruieren',
        'operator' => 'herausarbeiten und erläutern',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Videoabschnitt 06:14–09:21. Lennox kehrt den Wunschverdacht um: Auch der Wunsch, Gott möge nicht existieren, entscheidet die Wahrheitsfrage nicht. Das Eiffelturm-Beispiel soll zeigen, dass Wünschen über Existenz nicht entscheidet. Der Verweis auf Jesus verschiebt die Frage auf einen geschichtlichen Offenbarungsanspruch, der eigens historisch und theologisch zu prüfen bleibt.',
        'criteria' => ['beantwortete Einwände benennen', 'Funktion des Eiffelturm-Beispiels erklären', 'Funktion des Christusbezugs erklären', 'offene Prüf- oder Wahrheitsfrage markieren', 'Antwortschritte logisch verbinden'],
    ],
    'einstieg-lennox-beurteilen' => $common + [
        'title' => 'Lennox’ Antwort auf Wunschprojektion und Verborgenheit beurteilen',
        'operator' => 'beurteilen',
        'afb' => 'III',
        'level' => 'Jgst. 11',
        'material' => 'Lennox zeigt eine mögliche Symmetrie des Wunschverdachts und verweist auf Jesus als Offenbarungsanspruch. Damit kann er einen vorschnellen Schluss aus Wünschen kritisieren; die Existenz Gottes und die historische beziehungsweise theologische Tragfähigkeit des Christusbezugs sind dadurch noch nicht bewiesen.',
        'criteria' => ['Leistung der Antwort bestimmen', 'Voraussetzungen offenlegen', 'mindestens eine offene Grenze', 'Gegenargument oder alternative Deutung fair prüfen', 'begründetes Urteil ohne Bewertung persönlichen Glaubens'],
    ],
    'einstieg-leid-rekonstruieren' => $common + [
        'title' => 'Lennox’ Argument zu Leid und Hoffnung rekonstruieren',
        'operator' => 'herausarbeiten und erläutern',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Videoabschnitt 09:21–14:10. Lennox bezeichnet Leid als besonders schwierige Frage. Er verbindet das menschliche Verlangen nach Gerechtigkeit mit Kreuz, Auferstehung, Hoffnung und endgültiger Gerechtigkeit. Diese Hoffnung ist eine christliche Vertrauensaussage; sie erklärt nicht kausal, warum ein konkretes Leid geschieht.',
        'criteria' => ['Problem präzise benennen', 'christliche Antwort in ihren Elementen darstellen', 'Antwortleistung und Erklärung klar unterscheiden', 'bestehende Grenze nennen', 'keine privaten Leiderfahrungen verlangen oder auswerten'],
    ],
    'einstieg-leid-christlich-beurteilen' => $common + [
        'title' => 'Christliche Hoffnung angesichts von Leid beurteilen',
        'operator' => 'aus christlich-theologischer Sicht beurteilen',
        'afb' => 'III',
        'level' => 'Jgst. 11',
        'material' => 'Christliche Deutung bezieht Gottes Mit-Leiden im Kreuz, die Hoffnung der Auferstehung und die Erwartung endgültiger Gerechtigkeit auf die Leidfrage. Sie kann Trost, Protest gegen Unrecht und Hoffnung begründen, beantwortet aber nicht vollständig die Entstehung jedes konkreten Leids. Leid darf nicht verharmlost, gerechtfertigt oder Betroffenen zugeschrieben werden.',
        'criteria' => ['christliche Maßstäbe fachlich korrekt verwenden', 'Leistung als Hoffnung und Deutung bestimmen', 'Erklärungsgrenze und bleibende Frage benennen', 'Leid nicht romantisieren oder relativieren', 'abwägendes Sachurteil ohne persönliche Glaubens- oder Leiderfahrung'],
    ],
];
