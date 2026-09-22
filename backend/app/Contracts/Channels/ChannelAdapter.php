<?php

declare(strict_types=1);

namespace App\Contracts\Channels;

use App\DTOs\Channels\InboundMessage;

interface ChannelAdapter
{
    public function channelName(): string;

    /**
     * Handle an inbound normalised message and return optional reply text.
     */
    public function handleInbound(InboundMessage $message): ?string;
}
