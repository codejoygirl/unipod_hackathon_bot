<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Services\Channels\WhatsAppZavuAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WhatsAppZavuController extends Controller
{
    public function mintJoin(Request $request): JsonResponse
    {
        $expected = (string) config('whatsapp_zavu.admin_secret');
        $provided = (string) $request->header('X-Zavu-Admin-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid admin secret.');
        }

        $validated = $request->validate([
            'community_id' => ['required', 'ulid', 'exists:communities,id'],
        ]);

        $join = WhatsAppZavuAdapter::mintJoinToken($validated['community_id']);
        $waMe = WhatsAppZavuAdapter::waMeLink($join);

        return response()->json([
            'data' => [
                'join_message' => $join,
                'wa_me_link' => $waMe,
                'hint' => $waMe !== null
                    ? 'Share the wa.me link, or ask the member to send the join_message as text.'
                    : 'Send this exact text to the WhatsApp business number (set WHATSAPP_ZAVU_PHONE for wa.me links).',
            ],
        ]);
    }
}
