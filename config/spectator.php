<?php

return [
    'default' => env('SPEC_SOURCE', 'local'),

    'sources' => [
        'local' => [
            'source' => 'local',
            'folder' => env('SPEC_FOLDER'),
        ],

        'remote' => [
            'source' => 'remote',
            'url' => env('SPEC_URL'),
        ],

        'github' => [
            'source' => 'github',
            'repo' => env('SPEC_GITHUB_REPO'),
            'token' => env('SPEC_GITHUB_TOKEN'),
        ],
    ],
];
