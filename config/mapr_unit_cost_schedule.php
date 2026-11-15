<?php

/**
 * Unit base construction costs (PHP per m²) transcribed from
 * Assessor_Building_Assessment_Criteria.md — Series of 2019 schedule.
 * Only legible cells are included. Missing cells require assessor-authorized override.
 */
return [

    'criteria_version' => 'assessor-criteria-2019-partial-v1',

    'source' => 'Schedule of Unit Base Construction Cost for All Types of Buildings or Similar Structures, Form 10, Series of 2019 (partial transcription)',

    'currency' => 'PHP',

    'occupancies' => [
        'one_storey_residence' => [
            'key' => 'one_storey_residence',
            'label' => 'One-storey residence',
        ],
        'two_storey_residence' => [
            'key' => 'two_storey_residence',
            'label' => 'Two-storey residence / row house',
        ],
    ],

    'construction_types' => [
        'V' => 'Type V — Structural steel / reinforced concrete',
        'IV' => 'Type IV — Reinforced concrete',
        'III' => 'Type III — Strong materials',
        'II' => 'Type II — Fair materials',
        'I' => 'Type I — Temporary / makeshift materials',
    ],

    'grades' => ['A', 'B', 'C'],

    /**
     * rates[occupancy_key][construction_type][grade] = unit cost PHP/m²
     */
    'rates' => [
        'one_storey_residence' => [
            'V' => ['A' => 10560.00, 'B' => 7120.00],
            'IV' => ['A' => 5160.00, 'C' => 4680.00],
            'III' => ['A' => 4580.00, 'B' => 4530.00, 'C' => 1900.00],
            'II' => ['A' => 2650.00, 'B' => 2620.00, 'C' => 2520.00],
            'I' => ['B' => 1900.00, 'C' => 1710.00],
        ],
        'two_storey_residence' => [
            'V' => ['A' => 12000.00],
            'IV' => ['B' => 6630.00],
            'III' => ['A' => 5500.00, 'B' => 5250.00],
            'II' => ['A' => 4950.00, 'C' => 4560.00],
            'I' => ['A' => 3200.00, 'B' => 2750.00, 'C' => 2300.00],
        ],
    ],

    'damage_class_display' => [
        'not_affected' => 'No Damage',
        'partially_damaged' => 'Partially Damage',
        'totally_damaged' => 'Totally Damage',
    ],
];
