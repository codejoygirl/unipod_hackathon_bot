<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$config = config('ai_service');
$baseUrl = $argv[1] ?? $config['base_url'];
config(['ai_service.base_url' => $baseUrl]);
app()->forgetInstance(App\Services\AI\AiServiceClient::class);

$path = $argv[2] ?? '';
if ($path === '' || ! is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/smoke-understand-image.php [base_url] <image_path>\n");
    exit(1);
}

$b64 = base64_encode((string) file_get_contents($path));
$client = app(App\Services\AI\AiServiceClient::class);
$result = $client->understandImage(
    imageBase64: $b64,
    mimeType: 'image/png',
    filename: basename($path),
    caption: 'When is the hackathon deadline and submission requirements?',
);

echo json_encode([
    'base_url' => $baseUrl,
    'http_status' => $result['http_status'] ?? null,
    'unreachable' => $result['unreachable'] ?? false,
    'text_len' => strlen($result['text'] ?? ''),
    'text_preview' => mb_substr((string) ($result['text'] ?? ''), 0, 300),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

exit(($result['text'] ?? '') === '' ? 1 : 0);
