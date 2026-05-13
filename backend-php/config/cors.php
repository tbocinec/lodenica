<?php

return [
    'paths' => ['api/*', 'health', 'docs', 'docs-json'],

    'allowed_methods' => ['*'],

    // Default to the production frontend origin so deploys without an
    // explicit env var still work. Same-origin browser requests don't
    // require CORS, so this list is mostly for cross-origin tooling.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://tomas.gart.sk,http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
