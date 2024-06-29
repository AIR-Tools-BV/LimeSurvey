<?php

$rest = [
    'v1' => [
        'title' => 'LimeSurvey V1',
        'description' => 'LimeSurvey V1 REST API',
        'tags' => [
            'survey' => [
                'name' => 'Survey',
                'description' => 'Survey',
            ],
            'session' => [
                'name' => 'Session',
                'description' => 'Session',
            ],
            'survey-group' => [
                'name' => 'Survey Group',
                'description' => 'Survey Group',
            ],
            'site-settings' => [
                'name' => 'Site Settings',
                'description' => 'Site Settings',
            ]
        ]
    ]
];

return array_merge(
    $rest,
    include_once __DIR__ . '/v1/survey.php',
    include_once __DIR__ . '/v1/session.php',
    include_once __DIR__ . '/v1/survey-group.php',
    include_once __DIR__ . '/v1/user.php',
    include_once __DIR__ . '/v1/site-settings.php',
);
