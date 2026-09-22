<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessZavuInboundMessage;
use App\Services\Channels\Zavu\ZavuWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WhatsAppZavuWebhookController extends Controller
{
    public function __construct(
        private readonly ZavuWebhookSignature $signatures,
    ) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        $header = $request->header('X-Zavu-Signature');
        $secret = (string) config('whatsapp_zavu.webhook_secret');
        $maxAge = (int) config('whatsapp_zavu.webhook_max_age_seconds', 300);

        if (! $this->signatures->verify($rawBody, $header, $secret, $maxAge)) {
            abort(401, 'Invalid Zavu signature.');
        }

        /** @var array<string, mixed>|null $event */
        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            abort(400, 'Invalid JSON payload.');
        }

        if (config('whatsapp_zavu.process_sync') || app()->environment('testing')) {
            ProcessZavuInboundMessage::dispatchSync($event);
        } else {
            ProcessZavuInboundMessage::dispatch($event)->afterResponse();
        }

        return response('OK', 200);
    }
}
