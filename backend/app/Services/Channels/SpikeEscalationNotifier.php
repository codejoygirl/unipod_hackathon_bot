<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Models\Community;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Spike-only escalation: record the gap and notify the configured admin
 * on the active channel (Telegram Bot API for the Telegram spike).
 */
final class SpikeEscalationNotifier
{
    /**
     * @param  array{
     *     question: string,
     *     from: string,
     *     community_id: string,
     *     reason: string,
     *     channel: string,
     *     from_name?: string|null,
     *     community_name?: string|null
     * }  $payload
     * @return array{id: string, notified: bool}
     */
    public function escalate(array $payload): array
    {
        $id = (string) Str::ulid();
        $communityName = trim((string) ($payload['community_name'] ?? ''));
        if ($communityName === '' && ($payload['community_id'] ?? '') !== '') {
            $communityName = (string) (Community::query()->whereKey($payload['community_id'])->value('name') ?? '');
        }

        $fromName = trim((string) ($payload['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = 'Telegram member';
        }

        $record = [
            'id' => $id,
            'question' => $payload['question'],
            'from' => $payload['from'],
            'from_name' => $fromName,
            'community_id' => $payload['community_id'],
            'community_name' => $communityName !== '' ? $communityName : 'Unknown community',
            'reason' => $payload['reason'],
            'channel' => $payload['channel'],
            'created_at' => now()->toIso8601String(),
        ];

        Cache::put('spike_escalation:'.$id, $record, now()->addDays(7));
        $list = Cache::get('spike_escalations', []);
        if (! is_array($list)) {
            $list = [];
        }
        array_unshift($list, $id);
        Cache::put('spike_escalations', array_slice($list, 0, 100), now()->addDays(7));

        $notified = $this->notifyAdmin($record);

        Log::info('spike.escalation.created', [
            'id' => $id,
            'channel' => $payload['channel'],
            'notified' => $notified,
            'admin_contact' => config('telegram_spike.admin_contact'),
        ]);

        return ['id' => $id, 'notified' => $notified];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function notifyAdmin(array $record): bool
    {
        $channel = (string) ($record['channel'] ?? '');
        if ($channel !== 'telegram_spike') {
            return false;
        }

        $token = (string) config('telegram_spike.bot_token', '');
        $chatId = (string) config('telegram_spike.admin_chat_id', '');

        if ($token === '' || $chatId === '') {
            Log::warning('spike.escalation.notify_skipped', [
                'reason' => 'TELEGRAM_SPIKE_BOT_TOKEN or TELEGRAM_SPIKE_ADMIN_CHAT_ID not set',
                'escalation_id' => $record['id'] ?? null,
            ]);

            return false;
        }

        $text = $this->formatAdminMessage($record);

        $response = Http::timeout(15)->asJson()->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
            ]
        );

        if ($response->failed()) {
            Log::error('spike.escalation.notify_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'escalation_id' => $record['id'] ?? null,
            ]);

            return false;
        }

        $telegramMessageId = (string) ($response->json('result.message_id') ?? '');
        if ($telegramMessageId !== '') {
            Cache::put(
                'spike_escalation_tg_msg:'.$telegramMessageId,
                (string) $record['id'],
                now()->addDays(7)
            );
        }

        return true;
    }

    /**
     * When an admin replies to an escalation card, forward that answer to the asker.
     *
     * @return array{ok: bool, reply: string}
     */
    public function forwardAdminReply(string $replyToTelegramMessageId, string $answerText): array
    {
        $answerText = trim($answerText);
        if ($answerText === '' || $replyToTelegramMessageId === '') {
            return [
                'ok' => false,
                'reply' => "I couldn't match that to a pending question. Reply directly to the escalation card.",
            ];
        }

        $escalationId = Cache::get('spike_escalation_tg_msg:'.$replyToTelegramMessageId);
        if (! is_string($escalationId) || $escalationId === '') {
            return [
                'ok' => false,
                'reply' => "I couldn't match that to a pending question. Reply directly to the escalation card.",
            ];
        }

        $record = Cache::get('spike_escalation:'.$escalationId);
        if (! is_array($record)) {
            return [
                'ok' => false,
                'reply' => 'That escalation expired or was already cleared.',
            ];
        }

        $memberChatId = trim((string) ($record['from'] ?? ''));
        $token = (string) config('telegram_spike.bot_token', '');
        if ($memberChatId === '' || $token === '') {
            return [
                'ok' => false,
                'reply' => "I couldn't reach the member from here. Please message them directly.",
            ];
        }

        $name = trim((string) ($record['from_name'] ?? ''));
        $question = trim((string) ($record['question'] ?? ''));
        $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';

        $memberText = "{$greeting}\n\n"
            ."You asked:\n{$question}\n\n"
            ."Here's an update:\n{$answerText}";

        $response = Http::timeout(15)->asJson()->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $memberChatId,
                'text' => $memberText,
            ]
        );

        if ($response->failed()) {
            Log::error('spike.escalation.forward_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'escalation_id' => $escalationId,
            ]);

            return [
                'ok' => false,
                'reply' => "I couldn't deliver that to the member. Please try again or message them directly.",
            ];
        }

        Cache::put('spike_escalation:'.$escalationId, array_merge($record, [
            'resolved_at' => now()->toIso8601String(),
            'admin_answer' => $answerText,
        ]), now()->addDays(7));

        return [
            'ok' => true,
            'reply' => 'Thanks. I\'ve sent that to '.($name !== '' ? $name : 'the member').'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function formatAdminMessage(array $record): string
    {
        $name = trim((string) ($record['from_name'] ?? ''));
        if ($name === '') {
            $name = 'Unknown';
        }

        $id = trim((string) ($record['from'] ?? ''));
        if ($id === '') {
            $id = 'Unknown';
        }

        $community = trim((string) ($record['community_name'] ?? ''));
        if ($community === '') {
            $community = 'Unknown community';
        }

        $question = trim((string) ($record['question'] ?? ''));
        if ($question === '') {
            $question = '(No question text)';
        }

        $why = $this->friendlyReason((string) ($record['reason'] ?? ''));

        return "Zak needs a quick hand.\n\n"
            ."Name: {$name}\n\n"
            ."ID: {$id}\n\n"
            ."Community: {$community}\n\n"
            ."Question:\n{$question}\n\n"
            ."Why:\n{$why}\n\n"
            ."Please reply to this message with the answer.\n"
            .'When you do, I\'ll send it to the person who asked.';
    }

    private function friendlyReason(string $reason): string
    {
        $lower = strtolower($reason);

        if (str_contains($lower, 'insufficient')
            || str_contains($lower, 'did not contain enough')
            || str_contains($lower, 'not contain enough')) {
            return "I couldn't find enough in what we have shared to answer confidently.";
        }

        if (str_contains($lower, 'confidence') || str_contains($lower, 'threshold')) {
            return "Nothing solid enough turned up in what we have shared.";
        }

        if (str_contains($lower, 'conflict')) {
            return 'What we have shared seems to disagree, so a human should decide.';
        }

        if (str_contains($lower, 'hallucinat') || str_contains($lower, 'unanchored')) {
            return "I wasn't able to ground an answer safely in what we have shared.";
        }

        if (trim($reason) === '') {
            return "I couldn't answer this one from what we have shared.";
        }

        return "I couldn't answer this one from what we have shared.";
    }
}
