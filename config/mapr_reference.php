<?php

return [

    /*
    | Source identifiers come from geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm.
    | Display names keep the thesis spellings. Do not invent PSGC codes.
    */

    'city' => 'Tandag City',
    'province' => 'Surigao del Sur',
    'region' => 'Caraga',

    'barangays' => [
        [
            'barangay_code' => '166819002',
            'barangay_name' => 'Bag-ong Lungsod',
            'source_name' => 'Bagong Lungsod',
            'source_id' => '2',
            'city' => 'Tandag City',
            'province' => 'Surigao del Sur',
            'region' => 'Caraga',
        ],
        [
            'barangay_code' => '166819022',
            'barangay_name' => 'Telaje',
            'source_name' => 'Telaje',
            'source_id' => '22',
            'city' => 'Tandag City',
            'province' => 'Surigao del Sur',
            'region' => 'Caraga',
        ],
        [
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'source_name' => 'Mabua',
            'source_id' => '8',
            'city' => 'Tandag City',
            'province' => 'Surigao del Sur',
            'region' => 'Caraga',
        ],
        [
            'barangay_code' => '166819005',
            'barangay_name' => 'Bongtud',
            'source_name' => 'Bongtod',
            'source_id' => '5',
            'city' => 'Tandag City',
            'province' => 'Surigao del Sur',
            'region' => 'Caraga',
        ],
    ],

    // Deployment starter taxonomy. Review with CDRRMO before importing official records.
    // Categories alone never classify a resident; vulnerability records require verification.
    'disaster_types' => [
        ['name' => 'Flood'],
        ['name' => 'Typhoon'],
        ['name' => 'Earthquake'],
        ['name' => 'Landslide'],
        ['name' => 'Fire'],
        ['name' => 'Storm surge'],
        ['name' => 'Tsunami'],
    ],

    'vulnerability_categories' => [
        ['category_name' => 'Senior citizen', 'description' => 'Person aged 60 years or older; verify individual records.'],
        ['category_name' => 'Person with disability', 'description' => 'Disability status confirmed through the authorized verification workflow.'],
        ['category_name' => 'Pregnant woman', 'description' => 'Pregnancy status confirmed through the authorized verification workflow.'],
        ['category_name' => 'Lactating mother', 'description' => 'Lactation status confirmed through the authorized verification workflow.'],
        ['category_name' => 'Child under five', 'description' => 'Child younger than five years; verify individual records.'],
    ],

    'flood_classes' => [
        'VHF' => 'VHF (source FloodSusc)',
        'HF' => 'HF (source FloodSusc)',
        'MF' => 'MF (source FloodSusc)',
        'LF' => 'LF (source FloodSusc)',
    ],

    'damage_classes' => [
        [
            'class_code' => 'totally_damaged',
            'class_name' => 'Totally damaged',
            'description' => 'Field verification: the building is totally damaged.',
            'display_order' => 1,
            'counts_as_damaged' => true,
        ],
        [
            'class_code' => 'partially_damaged',
            'class_name' => 'Partially damaged',
            'description' => 'Field verification: the building is partially damaged.',
            'display_order' => 2,
            'counts_as_damaged' => true,
        ],
        [
            'class_code' => 'not_affected',
            'class_name' => 'Not affected',
            'description' => 'Field verification: the building is not affected.',
            'display_order' => 3,
            'counts_as_damaged' => false,
        ],
    ],

];
