<?php
declare(strict_types=1);

$common = [
    'curriculum' => 'LehrplanPLUS Bayern Musik; vor produktiver Nutzung mit der konkreten Jahrgangsstufe abgleichen',
    'minimum_chars' => 120,
    'maximum_chars' => 7000,
    'prompt_version' => 'musik-starter-v1',
];

return [
    'spannung-zusammenfassen' => $common + [
        'title' => 'Einen Sachtext prägnant zusammenfassen',
        'operator' => 'zusammenfassen', 'afb' => 'I', 'level' => 'Basis',
        'material' => 'Spannung wird als Verhältnis von erkennbarer Erwartung, Verzögerung oder Abweichung und Kontext erklärt.',
        'criteria' => ['konkreter Gedankengang', 'Muster, Abweichung und Kontext', 'eigene sachliche Formulierungen', 'keine bloße Themenangabe'],
    ],
    'spannung-analysieren' => $common + [
        'title' => 'Begriffsbeziehungen analysieren',
        'operator' => 'analysieren', 'afb' => 'II', 'level' => 'Basis',
        'material' => 'Das musikalische Mittel erhält seine Wirkung in einem wahrgenommenen Verlauf und einer aufgebauten Erwartung.',
        'criteria' => ['Begriffe unterscheiden', 'Beziehungen erklären', 'Textbezug', 'fachsprachliche Präzision'],
    ],
    'spannung-gestalten' => $common + [
        'title' => 'Spannungsverlauf gestalten und begründen',
        'operator' => 'gestalten und begründen', 'afb' => 'III', 'level' => 'Vertiefung',
        'material' => 'Eine Gestaltung verbindet eine schlüssige musikalische Idee mit einer reflektierten Wirkungsabsicht.',
        'criteria' => ['konsistente Spannungskurve', 'mehrere musikalische Parameter', 'funktionale Begründung', 'reflektierte Hörerwartung'],
    ],
];
