<?php

use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Internal\TelegramSpikeController;
use App\Http\Controllers\Api\V1\Internal\WhatsAppWebSpikeController;
use App\Http\Controllers\Api\V1\Internal\WhatsAppZavuController;
use App\Http\Controllers\Api\V1\KnowledgeSourceController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WebChatController;
use App\Http\Controllers\Api\V1\Webhooks\WhatsAppZavuWebhookController;
use App\Http\Middleware\EnsureTelegramSpikeEnabled;
use App\Http\Middleware\EnsureWhatsAppWebSpikeEnabled;
use App\Http\Middleware\EnsureWhatsAppZavuEnabled;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Zak API — versioned under /api/v1
|--------------------------------------------------------------------------
|
| Prefix `/api` is applied by Laravel. All product endpoints live in `/v1`.
| Scramble documents routes matching `api/v1` at `/docs/api`.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');

    Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::get('/web-chat/bootstrap', [WebChatController::class, 'bootstrap'])->name('web_chat.bootstrap');
    Route::post('/web-chat/ask', [WebChatController::class, 'ask'])->name('web_chat.ask');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');

        Route::get('/tenants/{tenant}/communities', [CommunityController::class, 'index'])->name('communities.index');
        Route::post('/tenants/{tenant}/communities', [CommunityController::class, 'store'])->name('communities.store');
        Route::get('/tenants/{tenant}/communities/{community}', [CommunityController::class, 'show'])->name('communities.show');

        Route::get('/knowledge-sources', [KnowledgeSourceController::class, 'index'])->name('knowledge.index');
        Route::post('/knowledge-sources', [KnowledgeSourceController::class, 'store'])->name('knowledge.store');
        Route::post('/knowledge-sources/import', [KnowledgeSourceController::class, 'import'])->name('knowledge.import');
        Route::get('/knowledge-sources/{knowledgeSource}', [KnowledgeSourceController::class, 'show'])->name('knowledge.show');
        Route::post('/knowledge-sources/{knowledgeSource}/submit-review', [KnowledgeSourceController::class, 'submitReview'])->name('knowledge.submit-review');
        Route::post('/knowledge-sources/{knowledgeSource}/publish', [KnowledgeSourceController::class, 'publish'])->name('knowledge.publish');
        Route::post('/knowledge-sources/{knowledgeSource}/reject', [KnowledgeSourceController::class, 'reject'])->name('knowledge.reject');

        Route::post('/assistant/ask', [AssistantController::class, 'ask'])->name('assistant.ask');
    });

    /*
    | WhatsApp Web automation spike (DEV ONLY). Gated by WHATSAPP_WEB_SPIKE + shared secret.
    | Not part of production Cloud API channel path.
    */
    Route::prefix('internal/whatsapp-web-spike')
        ->middleware(EnsureWhatsAppWebSpikeEnabled::class)
        ->name('internal.whatsapp_web_spike.')
        ->group(function (): void {
            Route::post('/inbound', [WhatsAppWebSpikeController::class, 'inbound'])->name('inbound');
            Route::post('/join-token', [WhatsAppWebSpikeController::class, 'mintJoin'])->name('join');
        });

    /*
    | Telegram Bot spike (DEV ONLY). Gated by TELEGRAM_SPIKE + shared secret.
    | Align with Phase 4 channel adapters later; not a production product path yet.
    */
    Route::prefix('internal/telegram-spike')
        ->middleware(EnsureTelegramSpikeEnabled::class)
        ->name('internal.telegram_spike.')
        ->group(function (): void {
            Route::post('/inbound', [TelegramSpikeController::class, 'inbound'])->name('inbound');
            Route::post('/join-token', [TelegramSpikeController::class, 'mintJoin'])->name('join');
        });

    /*
    | WhatsApp via Zavu (official BSP). Gated by WHATSAPP_ZAVU.
    | Webhook verifies X-Zavu-Signature. Spikes remain independently env-gated.
    */
    Route::post('/webhooks/whatsapp-zavu', WhatsAppZavuWebhookController::class)
        ->middleware(EnsureWhatsAppZavuEnabled::class)
        ->name('webhooks.whatsapp_zavu');

    Route::prefix('internal/whatsapp-zavu')
        ->middleware(EnsureWhatsAppZavuEnabled::class)
        ->name('internal.whatsapp_zavu.')
        ->group(function (): void {
            Route::post('/join-token', [WhatsAppZavuController::class, 'mintJoin'])->name('join');
        });
});
