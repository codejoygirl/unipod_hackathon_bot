<?php

declare(strict_types=1);

return [
    'base_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8000'),
    'hmac_secret' => env('INTERNAL_HMAC_SECRET', 'prod_secure_hmac_secret_key_minimum_32_bytes_entropy'),
    // Grounded ask includes embedding + LLM; local OpenAI calls often exceed 10s.
    'timeout_seconds' => (float) env('AI_SERVICE_TIMEOUT', 60.0),
    'connect_timeout_seconds' => (float) env('AI_SERVICE_CONNECT_TIMEOUT', 5.0),

    // Drop citations the member cannot be proven to reach, and BLOCK the answer when none
    // survive. Correct for production; a dead end while the corpus is still thin.
    'revalidate_citations' => (bool) env('AI_REVALIDATE_CITATIONS', true),

    // When retrieval has nothing to stand on, reply conversationally instead of returning an
    // empty answer, so a greeting or an uncovered question is never a dead end.
    'conversational_fallback' => (bool) env('AI_CONVERSATIONAL_FALLBACK', false),
];