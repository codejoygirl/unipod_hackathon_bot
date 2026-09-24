<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$query = $argv[1] ?? 'When is the hackathon deadline?';
$communityId = $argv[2] ?? (string) config('zak_presence.web_chat_default_community_id', '01m2y2ccq2p1f32y0hx4hp9pvz');
$email = (string) (config('whatsapp_web_spike.default_user_email') ?: config('telegram_spike.default_user_email') ?: 'demo@zak.test');

$user = App\Models\User::query()->where('email', $email)->first();
if ($user === null) {
    fwrite(STDERR, "User not found: {$email}\n");
    exit(1);
}

$payload = app(App\Services\Assistant\GroundedQuestionService::class)->ask(
    user: $user,
    query: $query,
    communityIds: [$communityId],
    targetLanguage: 'en',
);

echo json_encode([
    'query' => $query,
    'state' => $payload['data']['state'] ?? null,
    'chunks' => $payload['meta']['chunks_evaluated'] ?? null,
    'needs_escalation' => $payload['data']['needs_escalation'] ?? null,
    'answer_preview' => mb_substr((string) ($payload['data']['answer'] ?? ''), 0, 400),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
