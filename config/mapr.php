<?php

return [

    'demo_password' => env('MAPR_DEMO_PASSWORD'),

    'assessor_demo_password' => env('MAPR_ASSESSOR_DEMO_PASSWORD'),

    'assessor_verifier_email' => env('MAPR_ASSESSOR_VERIFIER_EMAIL', 'mark.merillo@mapr.test'),

    'assessor_approver_email' => env('MAPR_ASSESSOR_APPROVER_EMAIL', ''),

    'mapbox' => [
        'token' => env('MAPBOX_ACCESS_TOKEN'),
        'style' => env('MAPBOX_STYLE', 'mapbox://styles/mapbox/satellite-streets-v12'),
    ],

];
