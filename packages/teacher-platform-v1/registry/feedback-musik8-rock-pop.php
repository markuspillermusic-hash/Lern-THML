<?php
declare(strict_types=1);

$common = [
    'curriculum' => 'LehrplanPLUS Bayern, Gymnasium Musik, Jahrgangsstufe 8, Regelklasse; Rock- und Popmusik nach Rhythmik, Instrumentation, Sound und Produktionsweise unterscheiden',
    'minimum_chars' => 180,
    'maximum_chars' => 7000,
    'prompt_version' => 'musik8-rock-pop-feedback-2026-08-v2',
];

return [
    'genre-zuordnung-begruenden' => array_merge($common, [
        'title' => 'Sieben Hörbeispiele fachlich zuordnen und begründen',
        'operator' => 'zuordnen und begründen',
        'afb' => 'II',
        'level' => 'Jgst. 8 · Regelklasse',
        'minimum_chars' => 280,
        'material' => <<<'TEXT'
Die Antwort enthält sieben nummerierte Einträge aus einer Hörstation. Pro Eintrag stehen Titel, gewählte Stilrichtung und Schülerbegründung. Die KI hört keine Audiodatei; sie prüft ausschließlich, ob die schriftlich genannten Merkmale fachlich zur erwarteten Zuordnung passen. Erwartete Zuordnungen und tragfähige, aber nicht abschließende Hörkriterien:
1. „Fünf Gründe“ – Deutschrap: deutschsprachiger rhythmischer Sprechgesang/Rap-Flow; Beat und Bass im Zentrum; programmierte oder elektronisch bearbeitete Produktion.
2. „Band im Takt“ – Schlager: klarer melodischer Gesang; regelmäßiger tanzbarer Puls; eingängige Wiederholung und helle, geglättete Produktion.
3. „Iron Onslaught“ – Metal: stark verzerrte Gitarren oder schwere Riffs; druckvolles Schlagzeug; dichter harter Gesamtklang und expressive Stimme.
4. „Backline Takeover“ – Hiphop: Rap-Flow oder rhythmisches Sprechen; Beat, Bass und wiederholte Pattern im Zentrum; Loop-, Sample- oder elektronisch geprägte Produktion.
5. „Louder than the Doubt“ – K-Pop: hoch verdichtete polierte Produktion; geschichtete Stimmen und Synthesizer; Kontraste zwischen Gesang, Rap, Break oder Drop.
6. „Band Roll Call“ – Rock-Pop: Gitarre, Bass und Schlagzeug als Bandkern; klare Popstimme und eingängige Form; polierte Produktion zwischen Bandklang und Popästhetik.
7. „Plug It In“ – Rock: gitarrengeführte Riffs; hörbares Bandspiel von Drums und Bass; energetischer, weniger beatprogrammierter Gesamtklang.
Andere präzise hörbare Merkmale können ebenfalls tragen. Nicht akzeptabel sind erfundene Höreindrücke, bloße Geschmacksurteile, Zirkelschlüsse wie „es klingt eben nach Rock“, stereotype Künstlerbilder oder reine Textlänge. Bei Fehlzuordnung soll die Rückmeldung das stärkste Gegenindiz nennen und einen konkreten erneuten Hörfokus geben. Pro Eintrag knapp und schülergerecht rückmelden; am Ende zwei übergreifende Stärken und höchstens zwei nächste Arbeitsschritte nennen. Keine Note vergeben und nicht behaupten, die Aufnahmen selbst gehört zu haben.
TEXT,
        'criteria' => [
            'alle sieben Einträge eindeutig zugeordnet',
            'pro Eintrag mindestens zwei konkrete hörbare Merkmale statt Geschmacksurteil',
            'Merkmale schlüssig mit der gewählten Stilrichtung verbunden',
            'Stimme oder Flow, Klangquellen, Groove/Rhythmus und Produktion differenziert genutzt',
            'nahe Stile wie Rock/Rock-Pop und Hiphop/Deutschrap durch passende Kriterien unterschieden',
            'Unsicherheit transparent benannt statt Höreindrücke zu erfinden',
        ],
        'typical_errors' => [
            'bloße Aussage, der Song klinge nach dem gewählten Genre',
            'nur ein unspezifisches Merkmal wie schnell, laut oder modern',
            'Bühnenbild, Kleidung oder vermutete Herkunft statt hörbarer Musikmerkmale',
            'korrekte Stilrichtung, aber keine Erklärung des Zusammenhangs',
            'Verwechslung von Hiphop als Oberbegriff und Deutschrap als sprachlich-kultureller Ausprägung ohne Hörbeleg',
        ],
    ]),
    'timeline-verbindung-erklaeren' => $common + [
        'title' => 'Eine Entwicklung der Rock- und Popgeschichte erklären',
        'operator' => 'erklären und vergleichen',
        'afb' => 'II',
        'level' => 'Jgst. 8 · Regelklasse',
        'material' => <<<'TEXT'
Der Lernpfad verdichtet die Geschichte der Rock- und Popmusik in fünf Entwicklungspaaren: erzwungene Arbeit und Spiritual/Call-and-Response; Spirituals, Gospel, Blues und früher Jazz; Migration, technische Medien, Rhythm and Blues und Rock ’n’ Roll; Soul/Funk und Rock/Disco; Blockparty, Sampling und global vernetzte Popmusik. Die Darstellung ist keine lückenlose Ahnenreihe und keine einfache Abfolge „schwarz – weiß – schwarz – weiß“. Erwartet wird, dass die Schülerin oder der Schüler eine konkrete Verbindung zwischen zwei benachbarten Stationen erklärt: historische oder mediale Voraussetzung, übernommenes musikalisches Mittel, hörbare Veränderung und mögliche gesellschaftliche Bedeutung. Die KI hört die verlinkten Aufnahmen nicht und darf nicht behaupten, sie gehört zu haben. Sie beurteilt nur die schriftlich genannten Hörbeobachtungen. Präzisiere pauschale Fortschrittserzählungen und stereotype Zuschreibungen; würdige Wechselwirkungen, Aneignung, Ausgrenzung und eigene Innovationen verschiedener Akteurinnen und Akteure. Gib ein kurzes, konkretes Feedback mit einer Stärke, einer fachlichen Präzisierung und einer weiterführenden Hörfrage. Keine Note.
TEXT,
        'criteria' => [
            'zwei zeitlich oder sachlich benachbarte Stationen eindeutig benannt',
            'historischen, sozialen oder medialen Auslöser konkret erklärt',
            'mindestens ein übernommenes und ein verändertes musikalisches Merkmal genannt',
            'Hörbeobachtung und historische Deutung als unterschiedliche Ebenen kenntlich gemacht',
            'Wechselwirkungen differenziert statt als lineare oder ethnisch starre Abfolge dargestellt',
        ],
        'typical_errors' => [
            'reine Aufzählung von Jahreszahlen und Stilnamen',
            'Veränderungen ohne Ursache oder hörbares Merkmal',
            'eine einzige monokausale Ursprungserzählung',
            'pauschale Aussagen über Schwarze oder weiße Kultur ohne Akteure, Orte und Machtverhältnisse',
            'erfundene Höreindrücke oder bloße Geschmacksurteile',
        ],
    ],
    'mix-entscheidungen-erklaeren' => $common + [
        'title' => 'Mixing-Entscheidungen hörbezogen erklären',
        'operator' => 'erläutern und reflektieren',
        'afb' => 'II',
        'level' => 'Jgst. 8 · Regelklasse',
        'material' => <<<'TEXT'
Die Aufgabe bezieht sich auf das virtuelle Mischpult mit Einzelspuren, Fader, Mute/Solo, Panorama, Drei-Band-EQ, Effektweg und Master. Erwartet werden mindestens zwei konkrete Eingriffe und ihre beabsichtigte hörbare Wirkung, zum Beispiel: Stimme mit dem Fader verständlicher einordnen; Bass und Bassdrum in Lautstärke und Tiefen entzerren; Gitarren oder Begleitstimmen im Panorama verteilen; störende Schärfe in den Höhen reduzieren; Effekte sparsam für Räumlichkeit einsetzen. Es gibt nicht nur einen guten Mix. Beurteilt werden technische Plausibilität, Hörbezug, Balance und begründete Prioritäten, nicht eine identische Reglerzahl. Die KI kann den tatsächlich erzeugten Mix nicht hören und darf keine Klangqualität vortäuschen. Sie soll klar markieren, wenn eine behauptete Wirkung vom Text allein nicht überprüfbar ist, und einen konkreten A/B-Hörversuch vorschlagen. Keine Note.
TEXT,
        'criteria' => [
            'mindestens zwei konkrete Regler oder Kanäle benannt',
            'jeweils Ausgangsproblem, Eingriff und beabsichtigte Hörwirkung verbunden',
            'Fader, Panorama, EQ und Effekt in ihrer Funktion fachlich plausibel verwendet',
            'Einzelspur und Gesamtmix unterschieden',
            'Entscheidungen als hörabhängig statt als starre Universalregel formuliert',
        ],
        'typical_errors' => [
            'Reglerwerte ohne hörbares Ziel aufzählen',
            'EQ mit Lautstärke oder Panorama verwechseln',
            'alle Spuren möglichst laut einstellen',
            'Solo oder Mute als dauerhafte Qualitätsverbesserung missverstehen',
            'behaupten, der Mix sei gut, ohne Vergleich oder Hörkriterium',
        ],
    ],
    'buehnenwirkung-analysieren' => $common + [
        'title' => 'Bühnenwirkung sachgerecht analysieren',
        'operator' => 'analysieren',
        'afb' => 'II',
        'level' => 'Jgst. 8 · Regelklasse',
        'material' => <<<'TEXT'
Die Aufgabe verlangt die Analyse einer Bühnenabbildung oder einer von der Lehrkraft ausgewählten Live-Szene. Erwartet werden mindestens zwei sicht- oder hörbare Gestaltungsmittel aus Licht/Farbe, Raum/Position, Bewegung/Gestik, Kameraführung/Bild oder Klang/Lautstärke. Jede Beobachtung soll von ihrer möglichen Wirkung getrennt werden: „sichtbar/hörbar ist …; das kann die Aufmerksamkeit …, weil …“. Mehrere Deutungen sind möglich. Kleidung, Körper, Herkunft, Geschlecht oder vermuteter Musikgeschmack dürfen nicht stereotyp bewertet werden. Falls der konkrete Live-Ausschnitt im Text nicht beschrieben ist, darf die KI nur die Qualität der Analyseform prüfen und muss um präzisere Beobachtungen bitten. Gib eine Stärke und höchstens zwei konkrete Überarbeitungshinweise. Keine Note.
TEXT,
        'criteria' => [
            'mindestens zwei konkrete Gestaltungsmittel beobachtet',
            'Beobachtung und Deutung sprachlich getrennt',
            'mögliche Wirkung auf Aufmerksamkeit, Raumgefühl oder Klang nachvollziehbar erklärt',
            'Unsicherheit und alternative Wirkung zugelassen',
            'sachliche Analyse ohne stereotype Festlegung von Personen oder Jugendkulturen',
        ],
        'typical_errors' => [
            'Wertung wie schön oder cool ohne Beobachtung',
            'absolute Symboldeutung wie Rot bedeutet immer Aggressivität',
            'Position auf der Bühne als Beweis musikalischer Wichtigkeit',
            'nur Gestaltungsmittel aufzählen, ohne Wirkung zu erklären',
            'Personen anhand von Aussehen oder Kleidung festlegen',
        ],
    ],
];
