<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Services\Channels\ChannelListenGate;
use Tests\TestCase;

class ChannelListenGateTest extends TestCase
{
    public function test_private_always_listens(): void
    {
        $gate = new ChannelListenGate;
        $this->assertTrue($gate->shouldListen('private', 'hello', ['zak']));
        $this->assertTrue($gate->shouldListen('dm', 'Okay', []));
        $this->assertTrue($gate->shouldListen('private', 'What is the latest timestamp?', ['zak']));
        $this->assertTrue($gate->shouldListen('private', '/ask hours?', ['zak']));
        $this->assertTrue($gate->shouldListen('dm', '@zak when is clinic?', ['zak']));
    }

    public function test_group_silent_without_mention_or_command(): void
    {
        $gate = new ChannelListenGate;
        $this->assertFalse($gate->shouldListen('group', 'anyone free tonight?', ['zak']));
        $this->assertFalse($gate->shouldListen('group', 'Okay', ['zak']));
    }

    public function test_group_listens_to_admin_without_mention(): void
    {
        $gate = new ChannelListenGate;
        $this->assertTrue($gate->shouldListen(
            'group',
            'Clinic closed Friday afternoon',
            ['zak'],
            'mention_or_command',
            fromAdmin: true,
        ));
        $this->assertFalse($gate->shouldListen(
            'group',
            'Clinic closed Friday afternoon',
            ['zak'],
            'private_only',
            fromAdmin: true,
        ));
    }

    public function test_group_listens_on_alias_or_command(): void
    {
        $gate = new ChannelListenGate;
        $this->assertTrue($gate->shouldListen('group', '@zak when is clinic?', ['zak']));
        $this->assertTrue($gate->shouldListen('group', '/ask open hours?', ['zak']));
        $this->assertTrue($gate->shouldListen('group', 'zak help please', ['zak']));
        $this->assertTrue($gate->shouldListen('group', '/publish latest', ['zak']));
        $this->assertTrue($gate->shouldListen('group', '/knowledge drafts', ['zak']));
        $this->assertTrue($gate->shouldListen('group', '/features open', ['zak']));
        $this->assertTrue($gate->startsWithRecognizedCommand('/kb'));
    }

    public function test_group_listens_on_at_username_mention(): void
    {
        $gate = new ChannelListenGate;
        $aliases = ['zak_bot', '@unipod_bot'];

        $this->assertTrue($gate->shouldListen('group', '@unipod_bot when is clinic?', $aliases));
        $this->assertTrue($gate->shouldListen('group', 'hey @unipod_bot hours?', $aliases));
        // Bare handle without @ is not a real mention
        $this->assertFalse($gate->shouldListen('group', 'unipod_bot when is clinic?', $aliases));
        $this->assertSame('when is clinic?', $gate->stripMentions('@unipod_bot when is clinic?', $aliases));
    }

    public function test_group_listens_on_bot_number_mention(): void
    {
        $gate = new ChannelListenGate;
        $aliases = array_merge(['zak_bot'], ChannelListenGate::phoneMentionVariants('2347041131371'));
        $this->assertTrue($gate->shouldListen('group', 'hey @2347041131371 hours?', $aliases));
        $this->assertTrue($gate->shouldListen('group', 'hi +234 704 113 1371 are you there?', $aliases));
        $this->assertTrue($gate->shouldListen('group', '07041131371 help', $aliases));
        $this->assertFalse($gate->shouldListen('group', 'random chatter only', $aliases));
    }

    public function test_group_listens_on_bot_lid_mention(): void
    {
        $gate = new ChannelListenGate;
        $aliases = ['@100696296808461', '100696296808461'];
        $this->assertTrue($gate->shouldListen(
            'group',
            '@100696296808461 What are the meetings scheduled for today?',
            $aliases,
        ));
    }

    public function test_strip_mention(): void
    {
        $gate = new ChannelListenGate;
        $this->assertSame('when is clinic?', $gate->stripMentions('@zak when is clinic?', ['zak']));
        $this->assertSame('when is clinic?', $gate->stripMentions('zak, when is clinic?', ['zak']));
        // Glued bot LID (no space before the question).
        $this->assertSame(
            'Who is leading Tommorrows session?',
            $gate->stripMentions(
                '@100696296808461Who is leading Tommorrows session?',
                ['100696296808461'],
            ),
        );
    }

    public function test_private_only_mode_blocks_groups(): void
    {
        $gate = new ChannelListenGate;
        $this->assertFalse($gate->shouldListen('group', '/ask hi', ['zak'], 'private_only'));
        $this->assertTrue($gate->shouldListen('private', '/ask hi', ['zak'], 'private_only'));
        $this->assertTrue($gate->shouldListen('private', 'hello', ['zak'], 'private_only'));
    }

    public function test_media_payload_needs_inline_reply(): void
    {
        $gate = new ChannelListenGate;
        $this->assertTrue($gate->spikeNeedsInlineReply('', [
            'media' => [
                'kind' => 'image',
                'data_base64' => base64_encode('tiny-photo'),
            ],
        ]));
        $this->assertFalse($gate->spikeNeedsInlineReply('', []));
        $this->assertTrue($gate->spikeNeedsInlineReply('/ask hours?', []));
        $this->assertFalse($gate->spikeNeedsInlineReply('Share me the resource links', []));
    }

    public function test_slash_commands_only_for_share_ask_and_feature(): void
    {
        $gate = new ChannelListenGate;

        $this->assertTrue($gate->startsWithSlashCommand('/share Clinic moved to 3pm', 'share'));
        $this->assertFalse($gate->startsWithSlashCommand('Share me the resources links', 'share'));
        $this->assertFalse($gate->startsWithSlashCommand('share me links', 'share'));

        $this->assertTrue($gate->startsWithSlashCommand('/ask Who runs onboarding?', 'ask'));
        $this->assertFalse($gate->startsWithSlashCommand('Ask me anything', 'ask'));

        $this->assertTrue($gate->startsWithSlashCommand('/feature Add reminders', 'feature'));
        $this->assertFalse($gate->startsWithSlashCommand('Feature request please', 'feature'));

        $this->assertSame('Clinic moved to 3pm', $gate->slashCommandBody('/share Clinic moved to 3pm', 'share'));
    }

    public function test_recognized_command_requires_leading_slash(): void
    {
        $gate = new ChannelListenGate;
        $this->assertTrue($gate->startsWithRecognizedCommand('/share tip'));
        $this->assertFalse($gate->startsWithRecognizedCommand('Share me links'));
    }

    public function test_legacy_import_export_prefix_without_slash(): void
    {
        $gate = new ChannelListenGate;
        $this->assertTrue($gate->startsWithImportOrExport('EXPORT UniPods chat paste'));
        $this->assertTrue($gate->startsWithImportOrExport('/import pasted text'));
        $this->assertFalse($gate->startsWithImportOrExport('please export my data'));
    }
}
