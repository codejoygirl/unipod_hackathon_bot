<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WebChat\WebChatAccessService;
use App\Services\WebChat\WebChatMemberPhone;
use App\Services\WebChat\WebChatUrlBuilder;
use Illuminate\Console\Command;

final class WebChatLinkCommand extends Command
{
    protected $signature = 'zak:web-chat-link
                            {community_id? : Community ULID (defaults to ZAK_WEB_CHAT_DEFAULT_COMMUNITY_ID)}
                            {--phone= : Member phone (intl digits) for ?p= and ?s=}
                            {--mint : Mint a new time-limited ?k= key instead of the configured access key}';

    protected $description = 'Print a same-domain web chat URL (?k= community access, ?p= phone, ?s= session)';

    public function handle(
        WebChatAccessService $access,
        WebChatUrlBuilder $urls,
        WebChatMemberPhone $phones,
    ): int {
        $communityId = trim((string) ($this->argument('community_id') ?: config('zak_web_chat.default_community_id')));
        if ($communityId === '') {
            $this->components->error('Set ZAK_WEB_CHAT_DEFAULT_COMMUNITY_ID or pass community_id.');

            return self::FAILURE;
        }

        $community = $access->community($communityId);

        if ($this->option('mint')) {
            $key = $access->mintAccessKey($communityId);
            $this->components->twoColumnDetail('Minted access key (k)', $key);
            $this->components->warn('Add this to ZAK_WEB_CHAT_ACCESS_KEY in .env or share the minted link once.');
        } else {
            $key = trim((string) config('zak_web_chat.access_key'));
            if ($key === '') {
                $this->components->warn('No ZAK_WEB_CHAT_ACCESS_KEY — minting a temporary key.');
                $key = $access->mintAccessKey($communityId);
                $this->components->twoColumnDetail('Temporary k', $key);
            }
        }

        $phone = $phones->normalize($this->option('phone'));
        if ($this->option('phone') !== null && $phone === null) {
            $this->components->error('Invalid phone — use 8–15 international digits (no + required).');

            return self::FAILURE;
        }

        $url = $urls->inviteUrl($phone);

        $this->components->twoColumnDetail('Community', $community->name.' ('.$community->id.')');
        $this->newLine();
        $this->components->info('Share this URL');
        $this->line($url);

        if ($phone === null) {
            $this->newLine();
            $this->line('Add a member phone: php artisan zak:web-chat-link --phone=2347041131371');
        }

        return self::SUCCESS;
    }
}
