<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Channels;

use App\Services\Channels\ChannelConversationService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChannelConversationServiceTest extends TestCase
{
    public function test_hello_is_conversational_not_knowledge(): void
    {
        $svc = new ChannelConversationService;

        $this->assertSame(
            ChannelConversationService::INTENT_CONVERSATIONAL,
            $svc->classifyIntent('Hello')
        );
        $this->assertSame(
            ChannelConversationService::INTENT_CONVERSATIONAL,
            $svc->classifyIntent('hi!')
        );
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent("Who's Diane?")
        );
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent('What do you know about the hackathon?')
        );
        $this->assertSame(
            ChannelConversationService::INTENT_OUT_OF_SCOPE,
            $svc->classifyIntent('2+2')
        );
        $this->assertSame(
            ChannelConversationService::INTENT_OUT_OF_SCOPE,
            $svc->classifyIntent('what is 15 * 3')
        );
        $this->assertFalse($svc->shouldEscalateKnowledgeGap('2+2'));
        $this->assertTrue($svc->shouldEscalateKnowledgeGap('When is the hackathon deadline?'));
        $this->assertSame(
            ChannelConversationService::INTENT_OUT_OF_SCOPE,
            $svc->classifyIntent('Do you love me?')
        );
        $this->assertSame(
            ChannelConversationService::INTENT_OUT_OF_SCOPE,
            $svc->classifyIntent("Who's God?")
        );
        $this->assertFalse($svc->shouldEscalateKnowledgeGap('Do you love me?'));
        $this->assertFalse($svc->shouldEscalateKnowledgeGap("Who's God?"));
        $this->assertTrue($svc->shouldEscalateKnowledgeGap("Who's Diane?"));
        $this->assertTrue($svc->shouldEscalateKnowledgeGap('Send me the recording links'));

        $scope = 'UniPods Wadhwani programme: schedules, sessions, modules, recordings, and links shared here.';
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent('When is the next Wadhwani module session?', $scope)
        );
        $this->assertSame(
            ChannelConversationService::INTENT_OUT_OF_SCOPE,
            $svc->classifyIntent('Who won the World Cup?', $scope)
        );
        $this->assertFalse($svc->shouldEscalateKnowledgeGap('Who won the World Cup?', $scope));
        $this->assertTrue($svc->shouldEscalateKnowledgeGap('Any update on UniPods deadlines?', $scope));

        $scopedReply = $svc->outOfScopeReply('UniPods', $scope);
        $this->assertStringContainsString('UniPods', $scopedReply);
        $this->assertStringContainsString('come back once I do', $scopedReply);
        $this->assertStringNotContainsString('Happy to help with UniPods. UniPods', $scopedReply);
        $this->assertSame(
            ChannelConversationService::INTENT_CONVERSATIONAL,
            $svc->classifyIntent('Why are you not friendly?', $scope)
        );
        $tone = $svc->conversationalReply('Why are you not friendly?');
        $this->assertStringContainsString('Sorry if I came across', $tone);
        $this->assertStringContainsString('come back once I do', $tone);

        // Typos / missing "is" should still search community notes, not OOS.
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent('Who dianee', $scope)
        );
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent('whonis meti_bot', $scope)
        );
        $this->assertSame('who is meti_bot', $svc->normalizeMemberQuery('whonis meti_bot'));
        $this->assertTrue($svc->looksLikePersonLookup('Who dianee'));
        $this->assertFalse($svc->looksLikePersonLookup('Who won the World Cup?'));
        $this->assertTrue($svc->needsModelRouting('Who dianee'));
        $this->assertFalse($svc->needsModelRouting('Hello'));
        $this->assertFalse($svc->needsModelRouting('2+2'));

        $ctx = $svc->communityModelContext(
            'Demo Community',
            'UniPods / Wadhwani programme community: schedules, sessions, hackathon',
        );
        $this->assertSame('Demo Community', $ctx['name']);
        $this->assertStringContainsString('Display name: Demo Community', $ctx['scope']);
        $this->assertStringContainsString('UniPods', $ctx['scope']);
        $this->assertStringContainsString('in-scope for this community', $ctx['scope']);
    }

    public function test_try_again_reuses_last_knowledge_question(): void
    {
        $svc = new ChannelConversationService;
        $scope = 'UniPods Wadhwani programme: schedules, sessions, modules, recordings, and links shared here.';
        $turns = [
            ['role' => 'user', 'text' => 'Send me the recording links'],
            ['role' => 'assistant', 'text' => 'Here are the session recordings:...'],
            ['role' => 'user', 'text' => 'ok'],
            ['role' => 'assistant', 'text' => 'Sounds good.'],
        ];

        $this->assertTrue($svc->isRetryRequest('Try again'));
        $this->assertTrue($svc->isRetryRequest('Try answering again'));
        $this->assertSame(
            'Send me the recording links',
            $svc->lastRetrievableUserQuestion($turns)
        );

        $resolved = $svc->resolveInbound('Try again', $turns, $scope);
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $resolved['intent']);
        $this->assertSame('Send me the recording links', $resolved['query']);

        $resolved2 = $svc->resolveInbound('Try answering again', $turns, $scope);
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $resolved2['intent']);
        $this->assertSame('Send me the recording links', $resolved2['query']);
    }

    public function test_out_of_scope_reply_is_polite_without_escalation_language(): void
    {
        $svc = new ChannelConversationService;
        $reply = $svc->outOfScopeReply();

        $this->assertStringContainsString('community', mb_strtolower($reply));
        $this->assertStringContainsString('come back once I do', $reply);
        $this->assertStringNotContainsString('passed it along', mb_strtolower($reply));
        $this->assertStringNotContainsString('outside what I cover', mb_strtolower($reply));
        $this->assertStringNotContainsString('bit outside my lane', mb_strtolower($reply));
        $this->assertStringNotContainsString("\u{2014}", $reply);
    }

    public function test_conversational_hello_reply_is_warm(): void
    {
        $svc = new ChannelConversationService;
        $reply = $svc->conversationalReply('Hello');

        $this->assertStringContainsString('Good to hear from you', $reply);
        $this->assertStringContainsString('deadlines', mb_strtolower($reply));
        $this->assertStringContainsString('whatever else', mb_strtolower($reply));
        $this->assertStringContainsString("come back once I have an answer", $reply);
        $this->assertStringContainsString("What's on your mind", $reply);
        $this->assertStringNotContainsString("\u{2014}", $reply);
        $this->assertDoesNotMatchRegularExpression('/^Hey\b/i', $reply);
    }

    public function test_session_remembers_turns_per_user(): void
    {
        Cache::flush();
        $svc = new ChannelConversationService;

        $svc->remember('telegram_spike', 'user-a', 'user', 'Hello');
        $svc->remember('telegram_spike', 'user-a', 'assistant', 'Hey!');
        $svc->remember('telegram_spike', 'user-b', 'user', 'Who is Diane?');

        $turnsA = $svc->turns('telegram_spike', 'user-a');
        $this->assertCount(2, $turnsA);
        $this->assertSame('Hello', $turnsA[0]['text']);

        $turnsB = $svc->turns('telegram_spike', 'user-b');
        $this->assertCount(1, $turnsB);
        $this->assertSame('Who is Diane?', $turnsB[0]['text']);
    }

    public function test_knowledge_query_includes_recent_context(): void
    {
        $svc = new ChannelConversationService;
        $query = $svc->buildKnowledgeQuery('When does it end?', [
            ['role' => 'user', 'text' => 'Tell me about the hackathon'],
            ['role' => 'assistant', 'text' => 'It runs mid-September.'],
        ]);

        $this->assertStringContainsString('Current question: When does it end?', $query);
        $this->assertStringContainsString('hackathon', $query);
    }
}
