<?php

declare(strict_types=1);

return [
    'base_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8000'),
    'hmac_secret' => env('INTERNAL_HMAC_SECRET', 'prod_secure_hmac_secret_key_minimum_32_bytes_entropy'),
    // Grounded ask includes embedding + LLM; local OpenAI calls often exceed 10s.
    'timeout_seconds' => (float) env('AI_SERVICE_TIMEOUT', 60.0),
    'connect_timeout_seconds' => (float) env('AI_SERVICE_CONNECT_TIMEOUT', 5.0),
];