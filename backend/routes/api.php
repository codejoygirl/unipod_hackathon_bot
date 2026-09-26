<?php

use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PublicReachController;
use App\Http\Controllers\Api\V1\Internal\TelegramSpikeController;
use App\Http\Controllers\Api\V1\Internal\WhatsAppWebSpikeController;
use App\Http\Controllers\Api\V1\Internal\WhatsAppZavuController;
use App\Http\Controllers\Api\V1\KnowledgeSourceController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WebChatController;
use App\Http\Controllers\Api\V1\WebChatProjectController;
use App\Http\Controllers\Api\V1\WebChatVaultController;
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
    Route::get('/public/reach', PublicReachController::class)->name('public.reach');

    Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::get('/web-chat/bootstrap', [WebChatController::class, 'bootstrap'])->name('web_chat.bootstrap');
    Route::get('/web-chat/resources', [WebChatController::class, 'resources'])->name('web_chat.resources');
    Route::get('/web-chat/meetings', [WebChatController::class, 'meetings'])->name('web_chat.meetings.index');
    Route::get('/web-chat/notifications', [WebChatController::class, 'notifications'])->name('web_chat.notifications.index');
    Route::post('/web-chat/notifications/read-all', [WebChatController::class, 'markAllNotificationsRead'])->name('web_chat.notifications.read_all');
    Route::post('/web-chat/notifications/{notification}/read', [WebChatController::class, 'markNotificationRead'])
        ->whereUlid('notification')
        ->name('web_chat.notifications.read');
    Route::post('/web-chat/notifications', [WebChatController::class, 'publishNotification'])->name('web_chat.notifications.publish');
    Route::get('/web-chat/push/vapid-public-key', [WebChatController::class, 'pushPublicKey'])->name('web_chat.push.vapid');
    Route::post('/web-chat/push/subscribe', [WebChatController::class, 'pushSubscribe'])->name('web_chat.push.subscribe');
    Route::post('/web-chat/push/unsubscribe', [WebChatController::class, 'pushUnsubscribe'])->name('web_chat.push.unsubscribe');
    Route::post('/web-chat/admin/assets', [WebChatController::class, 'registerAsset'])->name('web_chat.admin.assets');
    Route::post('/web-chat/admin/import', [WebChatController::class, 'importKnowledge'])->name('web_chat.admin.import');
    Route::post('/web-chat/admin/meetings', [WebChatController::class, 'publishMeeting'])->name('web_chat.admin.meetings');
    Route::post('/web-chat/ask', [WebChatController::class, 'ask'])->name('web_chat.ask');
    Route::get('/web-chat/vault', [WebChatVaultController::class, 'show'])->name('web_chat.vault.show');
    Route::post('/web-chat/vault/documents', [WebChatVaultController::class, 'upload'])->name('web_chat.vault.upload');
    Route::get('/web-chat/vault/documents/{document}/download', [WebChatVaultController::class, 'download'])
        ->whereUlid('document')
        ->name('web_chat.vault.documents.download');
    Route::delete('/web-chat/vault/documents/{document}', [WebChatVaultController::class, 'destroyDocument'])
        ->whereUlid('document')
        ->name('web_chat.vault.documents.destroy');
    Route::post('/web-chat/vault/ask', [WebChatVaultController::class, 'ask'])->name('web_chat.vault.ask');
    Route::post('/web-chat/vault/generate', [WebChatVaultController::class, 'generate'])->name('web_chat.vault.generate');
    Route::get('/web-chat/vault/artefacts/{artefact}/download', [WebChatVaultController::class, 'downloadArtefact'])
        ->whereUlid('artefact')
        ->name('web_chat.vault.artefacts.download');
    Route::get('/web-chat/projects', [WebChatProjectController::class, 'index'])->name('web_chat.projects.index');
    Route::post('/web-chat/projects', [WebChatProjectController::class, 'store'])->name('web_chat.projects.store');
    Route::get('/web-chat/projects/{project}', [WebChatProjectController::class, 'show'])
        ->whereUlid('project')
        ->name('web_chat.projects.show');
    Route::patch('/web-chat/projects/{project}', [WebChatProjectController::class, 'update'])
        ->whereUlid('project')
        ->name('web_chat.projects.update');
    Route::delete('/web-chat/projects/{project}', [WebChatProjectController::class, 'destroy'])
        ->whereUlid('project')
        ->name('web_chat.projects.destroy');
    Route::post('/web-chat/projects/{project}/files', [WebChatProjectController::class, 'attachFiles'])
        ->whereUlid('project')
        ->name('web_chat.projects.files.attach');
    Route::delete('/web-chat/projects/{project}/files/{document}', [WebChatProjectController::class, 'detachFile'])
        ->whereUlid('project')
        ->whereUlid('document')
        ->name('web_chat.projects.files.detach');
    Route::post('/web-chat/projects/{project}/chats', [WebChatProjectController::class, 'storeChat'])
        ->whereUlid('project')
        ->name('web_chat.projects.chats.store');
    Route::get('/web-chat/projects/{project}/chats/{chat}', [WebChatProjectController::class, 'showChat'])
        ->whereUlid('project')
        ->whereUlid('chat')
        ->name('web_chat.projects.chats.show');
    Route::delete('/web-chat/projects/{project}/chats/{chat}', [WebChatProjectController::class, 'destroyChat'])
        ->whereUlid('project')
        ->whereUlid('chat')
        ->name('web_chat.projects.chats.destroy');
    Route::post('/web-chat/projects/{project}/chats/{chat}/ask', [WebChatProjectController::class, 'askChat'])
        ->whereUlid('project')
        ->whereUlid('chat')
        ->name('web_chat.projects.chats.ask');
    Route::post('/communities/{community}/assistant/ask', [WebChatController::class, 'askForCommunity'])
        ->whereUlid('community')
        ->name('communities.assistant.ask');
    Route::post('/web-chat/feature-request', [WebChatController::class, 'featureRequest'])->name('web_chat.feature_request');

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
    Route::get('/webhooks/whatsapp-zavu', static fn () => response()->json([
        'status' => 'ok',
        'message' => 'Zavu webhook is active. Events must be POST with X-Zavu-Signature.',
    ]))->middleware(EnsureWhatsAppZavuEnabled::class)
        ->name('webhooks.whatsapp_zavu.probe');

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
