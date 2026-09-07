<?php
declare(strict_types=1);

$common = [
    'curriculum' => 'LehrplanPLUS Bayern, Katholische Religionslehre, Jahrgangsstufe 11; Wahrnehmung, Wirklichkeit und Weltbild; operatorengestützte kompetenzorientierte Rückmeldung',
    'minimum_chars' => 180,
    'maximum_chars' => 9000,
    'prompt_version' => 'kr11-block-a-feedback-2026-08-v1',
];

return [
    'block-a-uexkuell-analysieren' => $common + [
        'title' => 'Uexkülls Umweltbegriff erklären und übertragen',
        'operator' => 'erklären und übertragen',
        'afb' => 'I–II',
        'level' => 'Jgst. 11',
        'material' => 'Jakob von Uexküll unterscheidet die physikalische Umgebung von der artspezifischen Umwelt. Merkwelt und Wirkwelt bilden für ein Lebewesen eine geschlossene Einheit. Die Zecke reagiert beispielhaft nur auf wenige biologisch bedeutsame Reize. Menschen erweitern Zugänge unter anderem durch Messgeräte, bleiben aber ebenfalls wahrnehmungsgebunden.',
        'criteria' => ['Umgebung und Umwelt präzise unterscheiden', 'geschlossen als vollständigen artspezifischen Zugang erklären', 'Zeckenbeispiel sachgerecht nutzen', 'drei plausible, nicht direkt wahrnehmbare Phänomene nennen', 'Übertragung mit dem Umweltbegriff verbinden'],
    ],
    'block-a-helmholtz-erklaeren' => $common + [
        'title' => 'Helmholtz’ Theorie unbewusster Schlüsse erklären und prüfen',
        'operator' => 'erklären, belegen und prüfen',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Helmholtz beschreibt Wahrnehmung als Ergebnis unbewusster Schlussprozesse statt als neutrales Abbild. Das Gehirn deutet lückenhafte Reizmuster als wahrscheinliche Ursachen. Optische Phänomene wie Hermann-Gitter, blinder Fleck oder Adelsons Schachbrett zeigen, dass Wissen über den Sachverhalt das Seherlebnis nicht automatisch verändert.',
        'criteria' => ['Schluss und Abbild unterscheiden', 'unbewusste Verarbeitung erklären', 'passendes Wahrnehmungsbeispiel korrekt beziehen', 'Einwand formulieren', 'Einwand anhand des Materials selbst prüfen'],
    ],
    'block-a-zwei-zeugen-beurteilen' => $common + [
        'title' => 'Aus widersprüchlichen Zeugenaussagen ein belastbares Urteil entwickeln',
        'operator' => 'analysieren und beurteilen',
        'afb' => 'II–III',
        'level' => 'Jgst. 11',
        'material' => 'Zwei glaubwürdige Augenzeugen widersprechen sich nach einem Unfall. Wahrnehmung und Erinnerung können durch Standort, Aufmerksamkeit, Vorwissen, Deutung und spätere Rekonstruktion variieren. Ein belastbares Urteil entsteht durch unabhängige Aussagen, Spuren, Perspektivenprüfung und Gegenkontrolle; Ehrlichkeit garantiert keine Fehlerfreiheit.',
        'criteria' => ['mindestens zwei relevante Filter analysieren', 'Ehrlichkeit und Richtigkeit unterscheiden', 'konkretes Prüfverfahren entwickeln', 'Grenzen einzelner Aussagen berücksichtigen', 'begründetes Urteil statt Relativismus'],
    ],
    'block-a-feed-vergleichen' => $common + [
        'title' => 'Personalisierte Feeds mit gefilterter Wahrnehmung vergleichen',
        'operator' => 'vergleichen und beurteilen',
        'afb' => 'II–III',
        'level' => 'Jgst. 11',
        'material' => 'Zwei Personen erhalten bei derselben Plattform unterschiedliche Inhalte. Wie bei mehrdeutiger Wahrnehmung kann ein Ausschnitt für das Ganze gehalten werden. Anders als ein biologischer Wahrnehmungsfilter ist der Feed technisch gestaltet, veränderbar, zieloptimiert und mit Verantwortlichkeiten von Plattform, Produzierenden und Nutzenden verbunden.',
        'criteria' => ['mindestens eine präzise Gemeinsamkeit', 'mindestens zwei relevante Unterschiede', 'technische Zielsteuerung und Verantwortung einbeziehen', 'Grenze der Analogie erklären', 'weder naiven Objektivismus noch Beliebigkeitsrelativismus vertreten'],
    ],
    'block-a-hoehle-deuten' => $common + [
        'title' => 'Platons Höhlengleichnis deuten und übertragen',
        'operator' => 'deuten und übertragen',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Im Höhlengleichnis stehen Fesseln für Gewohnheit und Unbildung, Schatten für ungeprüfte Erscheinungen oder Meinungen, Umkehr und Aufstieg für einen anstrengenden Bildungsweg, die Sonne für die Idee des Guten und die Rückkehr für Verantwortung gegenüber der Gemeinschaft. Moderne Erkenntnis bleibt revidierbar; niemand steht endgültig außerhalb jeder Perspektive.',
        'criteria' => ['drei Elemente zutreffend deuten', 'Übertragung strukturell und nicht nur oberflächlich ausführen', 'Tragfähigkeit der Analogie zeigen', 'Grenze der Analogie benennen', 'Notwendigkeit der Rückkehr begründen'],
    ],
    'block-a-antike-vergleichen' => $common + [
        'title' => 'Damon, Platon und Aristoteles vergleichen',
        'operator' => 'vergleichen und erläutern',
        'afb' => 'II',
        'level' => 'Jgst. 11',
        'material' => 'Damon ist eine fiktive Bauernfigur mit erfahrungsnah-religiöser Weltsicht. Platon gewichtet die intelligible Wirklichkeit und den Bildungsweg des Denkens. Aristoteles setzt stärker bei sinnlicher Erfahrung, Formen, Ursachen und systematischer Untersuchung an. Die Positionen unterscheiden sich in Wirklichkeit, Erkenntnis, Göttlichem und Tod; Sokratische Prüfung fragt nach Gründen und Grenzen.',
        'criteria' => ['klare Vergleichskriterien verwenden', 'alle drei Positionen sachgerecht darstellen', 'Gemeinsamkeiten und Unterschiede verknüpfen', 'Pluralität antiker Weltbilder erklären', 'plausible sokratische Prüfungsfrage formulieren'],
    ],
    'block-a-bilanz-beurteilen' => $common + [
        'title' => 'Wahrnehmung, Wirklichkeit und antike Denkwege bilanzieren',
        'operator' => 'erläutern und beurteilen',
        'afb' => 'II–III',
        'level' => 'Jgst. 11',
        'material' => 'Das reale Kleid ist überprüfbar blau-schwarz, obwohl der Bildreiz unterschiedlich erlebt wird. Sinnesorgane, neuronale Verarbeitung, Sprache und Vorwissen beziehungsweise Weltbild filtern Urteile. Intersubjektive Verfahren verbessern Erkenntnis, ohne subjektives Erleben sofort zu verändern. Sokratische Prüfung, Platons Bildungsweg und Aristoteles’ Erfahrungsorientierung bieten verschiedene Denkressourcen.',
        'criteria' => ['Sache, Reiz, Wahrnehmung und Urteil unterscheiden', 'vier Filter korrekt einbeziehen', 'intersubjektive Prüfung erklären', 'antiken Gedanken fachlich passend anwenden', 'Urteilskriterium, Einwand und begründetes Ergebnis; nicht die gewählte Position bewerten'],
        'minimum_chars' => 320,
    ],
];
