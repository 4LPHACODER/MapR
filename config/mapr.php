<?php

return [

    'assessor_verifier_email' => env('MAPR_ASSESSOR_VERIFIER_EMAIL', ''),
    'assessor_approver_email' => env('MAPR_ASSESSOR_APPROVER_EMAIL', ''),

    'consolidated_signer_emails' => [
        'noted_by' => env('MAPR_CONSOLIDATED_NOTED_BY_EMAIL', ''),
        'approved_by' => env('MAPR_CONSOLIDATED_APPROVED_BY_EMAIL', ''),
    ],

    'mapbox' => [
        'token' => env('MAPBOX_ACCESS_TOKEN'),
        'style' => env('MAPBOX_STYLE', 'mapbox://styles/mapbox/satellite-streets-v12'),
    ],

    'damage_inference_url' => env('MAPR_DAMAGE_INFERENCE_URL', ''),

    'field_consolidation_barangays' => [
        'Bag-ong Lungsod',
        'Bungtod',
        'Mabua',
        'Telaje',
    ],

    /*
    | Display name => alternate registry names / PSGC codes used when resolving barangays.
    | Display labels stay in field_consolidation_barangays; matching uses these aliases.
    */
    'field_consolidation_barangay_aliases' => [
        'Bungtod' => ['Bongtud', 'Bongtod', '166819005'],
    ],

];
