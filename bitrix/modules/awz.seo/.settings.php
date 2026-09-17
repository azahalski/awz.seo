<?php
return [
    'ui.entity-selector' => [
        'value' => [
            'entities' => [
                [
                    'entityId' => 'awzseo-user',
                    'provider' => [
                        'moduleId' => 'awz.seo',
                        'className' => '\\Awz\\Seo\\Access\\EntitySelectors\\User'
                    ],
                ],
                [
                    'entityId' => 'awzseo-group',
                    'provider' => [
                        'moduleId' => 'awz.seo',
                        'className' => '\\Awz\\Seo\\Access\\EntitySelectors\\Group'
                    ],
                ],
            ]
        ],
        'readonly' => true,
    ]
];