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
        $this->assertStringContainsString('follow up once I do', $scopedReply);
        $this->assertStringContainsString('any language', mb_strtolower($scopedReply));
        $this->assertStringContainsString('No need to keep checking', $scopedReply);
        $this->assertStringNotContainsString('Happy to help with UniPods. UniPods', $scopedReply);
        $this->assertSame(
            ChannelConversationService::INTENT_CONVERSATIONAL,
            $svc->classifyIntent('Why are you not friendly?', $scope)
        );
        $this->assertSame(
            ChannelConversationService::INTENT_CONVERSATIONAL,
            $svc->classifyIntent('Are you dumb?', $scope)
        );
        $this->assertSame(
            ChannelConversationService::INTENT_CONVERSATIONAL,
            $svc->classifyIntent('You are mad', $scope)
        );
        $this->assertFalse($svc->shouldEscalateKnowledgeGap('Are you dumb?', $scope));
        $this->assertFalse($svc->shouldEscalateKnowledgeGap('You are mad', $scope));
        $this->assertTrue($svc->isBotDirectedChat('Are you dumb?'));
        $this->assertFalse($svc->isBotDirectedChat('Are you sure?'));
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent('When is the hackathon ending?', $scope)
        );
        $this->assertTrue($svc->shouldEscalateKnowledgeGap('When is the hackathon ending?', $scope));
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent("Give me today's recap", $scope)
        );
        config([
            'zak_presence.show_web_chat' => true,
            'zak_presence.web_chat_url' => 'http://localhost:3000',
            'zak_presence.telegram_handle' => '',
        ]);
        $tone = $svc->conversationalReply('Why are you not friendly?');
        $this->assertStringContainsString('Sorry if I came across', $tone);
        $this->assertStringContainsString('any language', mb_strtolower($tone));
        $this->assertStringContainsString('Web chat', $tone);
        $this->assertStringContainsString('Telegram', $tone);
        $this->assertLessThan(420, mb_strlen($tone));

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
    }

    public function test_expects_bot_response_filters_incidental_group_tags(): void
    {
        $svc = new ChannelConversationService;

        $this->assertTrue($svc->expectsBotResponse('Who is Joy?', [
            'chat_type' => 'private',
        ]));

        $this->assertTrue($svc->expectsBotResponse('/ask Who is Joy?', [
            'chat_type' => 'group',
            'bot_mentioned' => false,
        ]));

        $this->assertTrue($svc->expectsBotResponse('Who is Joy?', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
        ]));

        $this->assertTrue($svc->expectsBotResponse('What about Diane?', [
            'chat_type' => 'group',
            'reply_to_bot' => true,
        ]));

        $this->assertFalse($svc->expectsBotResponse('@diane can you help with this?', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
        ]));

        $this->assertFalse($svc->expectsBotResponse('cc Zak for visibility', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak'],
        ]));

        $this->assertFalse($svc->expectsBotResponse('tell Zak to check the schedule', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
        ]));

        $this->assertFalse($svc->expectsBotResponse('Who is Joy?', [
            'chat_type' => 'group',
            'bot_mentioned' => false,
            'reply_to_bot' => false,
        ]));

        $this->assertFalse($svc->expectsBotResponse('@diane what do you think?', [
            'chat_type' => 'group',
            'reply_to_bot' => true,
            'bot_aliases' => ['zak_bot'],
        ]));

        // Asking Zak about a tagged member is still for Zak.
        $this->assertTrue($svc->expectsBotResponse('@Joy who is she?', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
        ]));

        // Bare @zak alone = friendly ping (start / continue conversation).
        $this->assertTrue($svc->expectsBotResponse('@zak_bot', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
        ]));

        // Bare @zak while quoting a question = answer that quote.
        $this->assertTrue($svc->expectsBotResponse('@zak_bot', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
            'quoted_text' => 'When did the hackathon start?',
        ]));

        // Bare @LID while quoting a question = same (WhatsApp group mention form).
        $this->assertTrue($svc->expectsBotResponse('@100696296808461', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot', '100696296808461'],
            'quoted_text' => 'When did the hackathon start?',
        ]));

        // Bare @zak quoting a message aimed at someone else → stay silent.
        $this->assertFalse($svc->expectsBotResponse('@zak_bot', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
            'quoted_text' => '@diane can you help with this?',
        ]));

        // Tag + chatter that is not an ask for Zak → ambiguous / silent path (not auto-true).
        $this->assertNull($svc->expectsBotResponse('@zak_bot we already sorted it with Joy', [
            'chat_type' => 'group',
            'bot_mentioned' => true,
            'bot_aliases' => ['zak_bot'],
        ]));
    }

    public function test_bare_bot_ping_continues_prior_question_or_greets(): void
    {
        $svc = new ChannelConversationService;

        $this->assertTrue($svc->isBareBotPing('@zak_bot'));
        $this->assertTrue($svc->isBareBotPing('zak_bot'));
        $this->assertFalse($svc->isBareBotPing('@zak_bot when is clinic?'));

        $fresh = $svc->resolveInbound('@zak_bot', []);
        $this->assertSame(ChannelConversationService::INTENT_CONVERSATIONAL, $fresh['intent']);

        $continue = $svc->resolveInbound('@zak_bot', [
            ['role' => 'user', 'text' => 'When did the hackathon start?'],
            ['role' => 'assistant', 'text' => "I don't have a solid answer yet."],
        ]);
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $continue['intent']);
        $this->assertSame('When did the hackathon start?', $continue['query']);

        $this->assertStringContainsString("I'm here", $svc->mentionPingReply());
    }

    public function test_expand_mentioned_people_replaces_ids_with_names(): void
    {
        $svc = new ChannelConversationService;
        $expanded = $svc->expandMentionedPeople(
            "Who's @80599524048943 @100696296808461 ?",
            [
                ['id' => '80599524048943', 'name' => '~Joy❤️'],
                ['id' => '100696296808461', 'name' => 'zak_bot'],
            ],
            ['100696296808461', '2347041131371'],
        );

        $this->assertStringContainsString('Joy', $expanded);
        $this->assertStringNotContainsString('80599524048943', $expanded);
        $this->assertStringNotContainsString('100696296808461', $expanded);
        $this->assertTrue($svc->looksLikePersonLookup($expanded));
        $this->assertTrue($svc->looksLikePersonLookup($svc->normalizeMemberQuery(
            $svc->expandMentionedPeople("Who's @80599524048943 ?", [
                ['id' => '80599524048943', 'name' => 'Joy'],
            ], [])
        )));

        // WA often glues bot LID to the next word with no space / word boundary.
        $glued = $svc->expandMentionedPeople(
            '@100696296808461Who is leading Tommorrows session?',
            [['id' => '100696296808461', 'name' => 'zak_bot']],
            ['100696296808461'],
        );
        $this->assertStringNotContainsString('100696296808461', $glued);
        $this->assertStringContainsString('Who is leading Tommorrows session?', $glued);
    }

    public function test_member_facing_question_strips_follow_up_envelope(): void
    {
        $svc = new ChannelConversationService;
        $envelope = "The member is following up on a previous community answer.\n\n"
            ."Original question: What is the name of the person taking the wadhani session?\n\n"
            ."Previous answer already shown to the member (do NOT repeat):\nTomorrow workshop.\n\n"
            ."Follow-up: Who is leading Tommorrows session?\n\n"
            .'Reply formatting: one blank line after any lead sentence before lists.';

        $this->assertSame(
            'Who is leading Tommorrows session?',
            $svc->memberFacingQuestion($envelope),
        );

        $reply = $svc->askAnsweredMemberReply(
            $envelope,
            'No one is scheduled yet.',
            null,
            'whatsapp',
        );
        $this->assertStringStartsWith("Hi,\n\n", $reply);
        $this->assertStringContainsString("*You asked:*\nWho is leading Tommorrows session?", $reply);
        $this->assertStringNotContainsString('The member is following up', $reply);
        $this->assertStringNotContainsString('Previous answer', $reply);

        $withAdmin = $svc->askAnsweredMemberReply(
            'when are we going to Abuja?',
            'We are going to Abuja next year',
            null,
            'whatsapp',
            '@2348117084647',
        );
        $this->assertStringContainsString(
            "*Here's an update from an admin* (@2348117084647):",
            $withAdmin,
        );
        $this->assertStringContainsString('We are going to Abuja next year', $withAdmin);
    }

    public function test_hello_is_conversational_not_knowledge_continued_checks(): void
    {
        $svc = new ChannelConversationService;
        $scope = 'UniPods Wadhwani programme: schedules, sessions, modules, recordings, and links shared here.';

        $this->assertFalse($svc->looksLikePersonLookup('Who won the World Cup?'));
        $this->assertTrue($svc->needsModelRouting('Who dianee'));
        $this->assertFalse($svc->needsModelRouting('Hello'));
        $this->assertFalse($svc->needsModelRouting('2+2'));

        // Multilingual: model routes online; offline non-ASCII → knowledge; linked community
        // + not hard-OOS should not refuse catch-up style asks.
        $this->assertTrue($svc->needsModelRouting('Ekaro oo'));
        $this->assertTrue($svc->needsModelRouting('Gracias'));
        $this->assertTrue($svc->needsModelRouting('Bonjour'));
        $yoruba = 'Fún mi ní àkótán àwọn ohun tó ṣẹlẹ̀ lónìí.';
        $this->assertTrue($svc->looksLikeNonEnglishCommunityAsk($yoruba));
        $this->assertFalse($svc->isClearlyOutOfScope($yoruba));
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent($yoruba, $scope)
        );
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent("Donnez-moi le récapitulatif d'aujourd'hui.", $scope)
        );

        $arabicRecordings = 'أرسل لي تسجيلات جميع الجلسات';
        $this->assertSame(
            ChannelConversationService::INTENT_KNOWLEDGE,
            $svc->classifyIntent($arabicRecordings, $scope)
        );
        // Offline link_mode is English-only; multilingual recordings come from the model.
        $this->assertSame('none', $svc->inferLinkMode($arabicRecordings));
        $this->assertSame('recordings', $svc->inferLinkMode('Send me all the recording links'));

        // Model wrongly saying OOS must not refuse non-English community asks.
        $merged = $svc->mergeModelClassification(
            ['intent' => ChannelConversationService::INTENT_KNOWLEDGE, 'query' => $arabicRecordings],
            ['intent' => 'out_of_scope', 'link_mode' => 'none'],
            $scope,
        );
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $merged['intent']);
        // Model said none; offline has no Arabic keywords — still knowledge search.
        $this->assertSame('none', $merged['link_mode']);

        // When the model sets link_mode, keep it (any language).
        $mergedOk = $svc->mergeModelClassification(
            ['intent' => ChannelConversationService::INTENT_KNOWLEDGE, 'query' => $arabicRecordings],
            ['intent' => 'knowledge', 'link_mode' => 'recordings'],
            $scope,
        );
        $this->assertSame('recordings', $mergedOk['link_mode']);

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

    public function test_contextual_follow_up_reuses_prior_question_offline_and_via_model_flag(): void
    {
        $svc = new ChannelConversationService;
        $scope = 'UniPods Wadhwani programme: schedules, sessions, hackathon bots, and testing.';
        $priorAnswer = 'Currently, only one bot, Shadrak\'s bot, is confirmed to be ready for testing.';
        $turns = [
            ['role' => 'user', 'text' => 'How many bots are currently being tested in the group?'],
            ['role' => 'assistant', 'text' => $priorAnswer],
        ];

        // English offline fallback only (model owns other languages).
        $this->assertTrue($svc->isContextualFollowUpOffline('Are you sure?'));
        $this->assertTrue($svc->isContextualFollowUpOffline('@zak_bot Are you sure?'));
        $this->assertTrue($svc->isContextualFollowUpOffline("What's being said?"));
        $this->assertTrue($svc->isContextualFollowUpOffline(
            "Regarding this earlier message:\n\"{$priorAnswer}\"\n\nCurrent message:\nAre you sure?"
        ));
        // Do not pretend FR/AR keyword lists exist in PHP.
        $this->assertFalse($svc->isContextualFollowUpOffline('Tu es sûr ?'));
        $this->assertFalse($svc->isContextualFollowUpOffline('متأكد؟'));
        $this->assertTrue($svc->needsModelRouting('Tu es sûr ?'));

        $resolved = $svc->resolveInbound('Are you sure?', $turns, $scope);
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $resolved['intent']);
        $this->assertStringContainsString('following up', $resolved['query']);
        $this->assertStringContainsString('How many bots are currently being tested', $resolved['query']);
        $this->assertFalse($svc->needsModelRouting($resolved['query']));

        $merged = $svc->mergeModelClassification(
            ['intent' => ChannelConversationService::INTENT_OUT_OF_SCOPE, 'query' => 'Tu es sûr ?'],
            [
                'intent' => 'clarify',
                'link_mode' => 'none',
                'follow_up' => true,
            ],
            $scope,
            $turns,
        );
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $merged['intent']);
        $this->assertStringContainsString('following up', $merged['query']);
        $this->assertStringContainsString('Tu es sûr ?', $merged['query']);
        $this->assertStringContainsString('How many bots are currently being tested', $merged['query']);

        // Swipe "Is that all?" must not clarify / poison the prior bots question.
        $poisoned = [
            ...$turns,
            ['role' => 'user', 'text' => 'Is that all?'],
            ['role' => 'assistant', 'text' => 'Could you clarify what you mean by "Is that all?"'],
        ];
        $this->assertSame(
            'How many bots are currently being tested in the group?',
            $svc->lastRetrievableUserQuestion($poisoned),
        );
        $forced = $svc->mergeModelClassification(
            ['intent' => 'clarify', 'query' => 'Is that all?'],
            ['intent' => 'clarify', 'link_mode' => 'none', 'follow_up' => false],
            $scope,
            $poisoned,
            replyToBot: true,
        );
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $forced['intent']);
        $this->assertStringContainsString('How many bots are currently being tested', $forced['query']);
        $this->assertStringContainsString($priorAnswer, $forced['query']);
        $this->assertStringNotContainsString('Could you clarify', $forced['query']);

        $this->assertSame(
            'How many bots are currently being tested in the group?',
            $svc->userTurnTextToRemember('Tu es sûr ?', $merged['query']),
        );

        // Insults / tone after a failed knowledge turn must never become follow-ups or escalate.
        $afterGap = [
            ...$turns,
            ['role' => 'user', 'text' => "Who's Joy?"],
            ['role' => 'assistant', 'text' => "I don't have a solid answer for that yet.\n\nI've passed it along."],
        ];
        $insult = $svc->mergeModelClassification(
            ['intent' => ChannelConversationService::INTENT_KNOWLEDGE, 'query' => 'Are you dumb?'],
            [
                'intent' => 'knowledge',
                'link_mode' => 'none',
                'follow_up' => true,
            ],
            $scope,
            $afterGap,
        );
        $this->assertSame(ChannelConversationService::INTENT_CONVERSATIONAL, $insult['intent']);
        $this->assertFalse($svc->shouldEscalateKnowledgeGap('Are you dumb?', $scope));

        // Repeat recordings ask must search fresh — not wrap as follow-up envelope.
        $recTurns = [
            ['role' => 'user', 'text' => 'Give me all the sessions recordings'],
            ['role' => 'assistant', 'text' => "I don't have a solid answer for that yet.\n\nI've passed it along."],
        ];
        $repeat = $svc->mergeModelClassification(
            ['intent' => ChannelConversationService::INTENT_KNOWLEDGE, 'query' => 'Give me all th sessions recirings'],
            [
                'intent' => 'knowledge',
                'link_mode' => 'recordings',
                'follow_up' => true,
            ],
            $scope,
            $recTurns,
        );
        $this->assertSame(ChannelConversationService::INTENT_KNOWLEDGE, $repeat['intent']);
        $this->assertSame('recordings', $repeat['link_mode']);
        $this->assertStringNotContainsString('following up', $repeat['query']);
        $this->assertTrue($svc->isNearDuplicateAsk(
            'Give me all th sessions recirings',
            'Give me all the sessions recordings',
        ));
        $this->assertNull($svc->lastKnowledgeAssistantAnswer($recTurns));

        $envelope = $svc->buildFollowUpKnowledgeQuery(
            'What\'s being said?',
            'How many bots are currently being tested in the group?',
            $priorAnswer,
        );
        $this->assertSame($envelope, $svc->buildKnowledgeQuery($envelope, $turns));
    }

    public function test_private_chat_link_is_appended_from_config(): void
    {
        config([
            'zak_presence.whatsapp_url' => 'https://wa.me/2347000000000',
            'zak_presence.telegram_url' => 'https://t.me/zak_test_bot',
        ]);
        $svc = new ChannelConversationService;

        $wa = $svc->withPrivateChatLink('Ask me privately.', 'whatsapp', 'whatsapp');
        $this->assertStringContainsString('Ask me privately.', $wa);
        $this->assertStringContainsString('Private chat', $wa);
        $this->assertStringContainsString('2347000000000', $wa);

        $tg = $svc->withPrivateChatLink('Demande-moi en privé.', 'plain', 'telegram');
        $this->assertStringContainsString('Demande-moi en privé.', $tg);
        $this->assertStringContainsString('t.me/zak_test_bot', $tg);

        $merged = $svc->mergeModelClassification(
            ['intent' => ChannelConversationService::INTENT_OUT_OF_SCOPE, 'query' => 'Motivate me'],
            [
                'intent' => ChannelConversationService::INTENT_PERSONAL_HELP,
                'link_mode' => 'none',
                'follow_up' => false,
            ],
            'UniPods',
            [],
        );
        $this->assertSame(ChannelConversationService::INTENT_PERSONAL_HELP, $merged['intent']);
    }

    public function test_out_of_scope_reply_is_polite_without_escalation_language(): void
    {
        $svc = new ChannelConversationService;
        $reply = $svc->outOfScopeReply();

        $this->assertStringContainsString('community', mb_strtolower($reply));
        $this->assertStringContainsString('follow up once I do', $reply);
        $this->assertStringContainsString('/ask', $reply);
        $this->assertStringContainsString('/share', $reply);
        $this->assertStringContainsString('any language', mb_strtolower($reply));
        $this->assertStringContainsString('No need to keep checking', $reply);
        $this->assertStringNotContainsString('passed it along', mb_strtolower($reply));
        $this->assertStringNotContainsString('outside what I cover', mb_strtolower($reply));
        $this->assertStringNotContainsString('bit outside my lane', mb_strtolower($reply));
        $this->assertStringNotContainsString("\u{2014}", $reply);

        $wa = $svc->outOfScopeReply(style: 'whatsapp');
        $this->assertStringContainsString('/share', $wa);
        $this->assertStringContainsString('*Still need help?*', $wa);
        $this->assertStringContainsString('/ask', $wa);
        $this->assertStringContainsString('```', $wa);
        $this->assertStringNotContainsString('Need an admin', $wa);
        $this->assertStringNotContainsString('need a human', mb_strtolower($wa));
        $this->assertLessThan(900, mb_strlen($wa));
    }

    public function test_conversational_hello_reply_is_warm(): void
    {
        config([
            'zak_presence.show_web_chat' => true,
            'zak_presence.web_chat_url' => 'http://localhost:3000',
            'zak_presence.telegram_handle' => '',
        ]);

        $svc = new ChannelConversationService;
        $reply = $svc->conversationalReply('Hello', 'whatsapp');

        $this->assertStringContainsString('Good to hear from you', $reply);
        $this->assertStringContainsString('schedules', mb_strtolower($reply));
        $this->assertStringContainsString('any language', mb_strtolower($reply));
        $this->assertStringContainsString('Web chat', $reply);
        $this->assertStringContainsString('Telegram', $reply);
        $this->assertStringContainsString("What's on your mind", $reply);
        $this->assertStringNotContainsString("\u{2014}", $reply);
        $this->assertDoesNotMatchRegularExpression('/^Hey\b/i', $reply);
        // Keep intros short — no long reassurance wall on hello.
        $this->assertLessThan(420, mb_strlen($reply));
    }

    public function test_member_help_omits_admin_commands_and_skips_current_channel(): void
    {
        config([
            'zak_presence.show_web_chat' => true,
            'zak_presence.web_chat_url' => 'http://localhost:3000',
            'zak_presence.telegram_handle' => 'zak_community_bot',
            'zak_presence.telegram_url' => '',
            'zak_presence.whatsapp_url' => 'https://wa.me/2347041131371',
            'zak_presence.display_name' => 'Zak Bot',
        ]);

        $svc = new ChannelConversationService;
        $wa = $svc->memberHelpText('whatsapp', 'whatsapp');

        $this->assertStringContainsString("I'm Zak Bot", $wa);
        $this->assertStringContainsString('What I can do', $wa);
        $this->assertStringContainsString('no need to keep asking', $wa);
        $this->assertStringContainsString('/ask', $wa);
        $this->assertStringContainsString('/share', $wa);
        $this->assertStringContainsString('/feature', $wa);
        $this->assertStringContainsString('request or improve a feature', $wa);
        $this->assertStringContainsString('e.g.', $wa);
        $this->assertStringNotContainsString('/join', $wa);
        $this->assertStringContainsString('👋', $wa);
        $this->assertStringContainsString('*@mention*', $wa);
        $this->assertStringContainsString('*reply*', $wa);
        $this->assertStringContainsString('Telegram', $wa);
        $this->assertStringContainsString('https://t.me/zak_community_bot', $wa);
        $this->assertStringContainsString('Web chat', $wa);
        $this->assertStringContainsString('localhost:3000', $wa);
        $this->assertStringNotContainsString('WhatsApp:', $wa);
        $this->assertStringNotContainsString('/import', $wa);
        $this->assertStringNotContainsString('/export', $wa);
        $this->assertStringNotContainsString('/approve', $wa);
        $this->assertStringNotContainsString('Admin', $wa);

        $tg = $svc->memberHelpText('plain', 'telegram');
        $this->assertStringContainsString('What I can do', $tg);
        $this->assertStringContainsString('no need to keep asking', $tg);
        $this->assertStringContainsString('WhatsApp', $tg);
        $this->assertStringContainsString('wa.me/2347041131371', $tg);
        $this->assertStringContainsString('Web chat', $tg);
        $this->assertStringNotContainsString('/join', $tg);
        $this->assertStringNotContainsString('t.me/', $tg);
        $this->assertStringNotContainsString('/import', $tg);
        $this->assertStringNotContainsString('/export', $tg);

        $admin = $svc->helpTextFor('whatsapp', 'whatsapp', true);
        $this->assertStringContainsString('/import', $admin);
        $this->assertStringContainsString('knowledge draft', $admin);
        $this->assertStringContainsString('/approve', $admin);
        $this->assertStringContainsString('/reply', $admin);
        $this->assertStringContainsString('Admin', $admin);
        $this->assertStringContainsString('e.g.', $admin);
        $this->assertMatchesRegularExpression('/e\.g\._?\s*`{0,3}\/?ask/i', $admin);
        $this->assertMatchesRegularExpression('/e\.g\._?\s*`{0,3}\/?reply\s+[A-Z0-9]+\s+/i', $admin);

        $plainAdmin = $svc->helpTextFor('plain', 'telegram', true);
        $this->assertStringContainsString('e.g. /ask', $plainAdmin);
        $this->assertStringContainsString('e.g. /share', $plainAdmin);
        $this->assertStringContainsString('e.g. /approve', $plainAdmin);

        $groupHelp = $svc->memberHelpText('whatsapp', 'whatsapp', 'group');
        $this->assertStringContainsString('Private chat', $groupHelp);
        $this->assertStringContainsString('api.whatsapp.com/send?phone=2347041131371', $groupHelp);
        $this->assertStringContainsString('schedules, links, or updates', $groupHelp);
        $this->assertStringContainsString('tip for the community', $groupHelp);
        $this->assertStringNotContainsString("\u{2014}", $groupHelp);

        $sets = [];
        for ($i = 0; $i < 24; $i++) {
            $sets[$svc->formatHelpExampleLines($svc->rotatingHelpExamples())] = true;
        }
        $this->assertGreaterThanOrEqual(2, count($sets), 'help examples should rotate across calls');

        $cmdSets = [];
        for ($i = 0; $i < 24; $i++) {
            $cmdSets[$svc->formatMemberCommandHelp('plain')] = true;
        }
        $this->assertGreaterThanOrEqual(2, count($cmdSets), 'command e.g. examples should rotate');

        $adminSets = [];
        for ($i = 0; $i < 24; $i++) {
            $adminSets[$svc->adminHelpAppendix('plain')] = true;
        }
        $this->assertGreaterThanOrEqual(2, count($adminSets), 'admin command e.g. examples should rotate');
    }

    public function test_channel_presence_ask_returns_clickable_links(): void
    {
        config([
            'zak_presence.show_web_chat' => true,
            'zak_presence.web_chat_url' => 'http://localhost:3000',
            'zak_presence.telegram_handle' => 'zak_meti_26_bot',
            'zak_presence.telegram_url' => 'https://t.me/zak_meti_26_bot',
            'zak_presence.whatsapp_url' => 'https://wa.me/2347041131371',
        ]);

        $svc = new ChannelConversationService;
        $this->assertTrue($svc->isChannelPresenceAsk('What is your Telegram link?'));
        $this->assertTrue($svc->isChannelPresenceAsk('Do you have WhatsApp?'));
        $this->assertTrue($svc->isChannelPresenceAsk('web chat url please'));

        $fromWa = $svc->channelPresenceReply('whatsapp', 'whatsapp');
        $this->assertStringContainsString('https://t.me/zak_meti_26_bot', $fromWa);
        $this->assertStringContainsString('localhost:3000', $fromWa);
        $this->assertStringNotContainsString('wa.me/', $fromWa);

        $fromTg = $svc->conversationalReply('Send me the WhatsApp link', 'plain', 'telegram');
        $this->assertStringContainsString('https://wa.me/2347041131371', $fromTg);
        $this->assertStringContainsString('localhost:3000', $fromTg);
        $this->assertStringNotContainsString('t.me/', $fromTg);
    }

    public function test_tidy_member_answer_cleans_linkedin_intro_dump(): void
    {
        $svc = new ChannelConversationService;
        $raw = "Here they are:\n\n"
            ."1. a🤖🤖👩🏽‍💻f(x)=g(h(x))😉: Hey. I am Yemima  and I am from Togo.  I am\n"
            ."https://www.linkedin.com/in/0xhonodev\n\n"
            ."2. 1:36] ~ C.S. Gad: Hello Everyone. My name is CYIZA Gad Shabazz, COO\n"
            ."https://www.linkedin.com/in/gcyiza?utm_source=share_via\n";

        $tidy = $svc->tidyMemberAnswer($raw);
        $this->assertStringContainsString('recent intros', mb_strtolower($tidy));
        $this->assertStringContainsString('linkedin.com/in/0xhonodev', $tidy);
        $this->assertStringContainsString('linkedin.com/in/gcyiza', $tidy);
        $this->assertStringNotContainsString('🤖', $tidy);
        $this->assertStringNotContainsString('1:36]', $tidy);
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

    public function test_session_isolates_same_user_across_threads(): void
    {
        Cache::flush();
        $svc = new ChannelConversationService;

        $svc->remember('whatsapp_web_spike', 'user-a', 'user', 'Group question', 'group-1');
        $svc->remember('whatsapp_web_spike', 'user-a', 'assistant', 'Group answer', 'group-1');
        $svc->remember('whatsapp_web_spike', 'user-a', 'user', 'Private question', 'dm:user-a');

        $groupTurns = $svc->turns('whatsapp_web_spike', 'user-a', 'group-1');
        $this->assertCount(2, $groupTurns);
        $this->assertSame('Group question', $groupTurns[0]['text']);

        $dmTurns = $svc->turns('whatsapp_web_spike', 'user-a', 'dm:user-a');
        $this->assertCount(1, $dmTurns);
        $this->assertSame('Private question', $dmTurns[0]['text']);

        $otherUser = $svc->turns('whatsapp_web_spike', 'user-b', 'group-1');
        $this->assertSame([], $otherUser);
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

    public function test_english_ask_hint_not_appended_to_non_english_replies(): void
    {
        $svc = new ChannelConversationService;

        $this->assertFalse($svc->shouldAppendEnglishAskHint(
            'ዋድዋኒ ቀጣዩ ክፍል አላውቅም። እባክዎ ይጠይቁ።',
            'ዋድዋኒ ቀጣዩ ክፍል መቼ ነው?'
        ));
        $this->assertFalse($svc->shouldAppendEnglishAskHint(
            "Désolé, je ne peux pas aider.",
            'Quand est la prochaine session?'
        ));
        $this->assertTrue($svc->shouldAppendEnglishAskHint(
            "Sorry, I can't help with that one.",
            'When is the next session?'
        ));
        $this->assertFalse($svc->shouldAppendEnglishAskHint(
            "Sorry, try /ask When is the next session?",
            'hello'
        ));
    }

    public function test_strip_internal_evidence_tags_removes_grouped_citations(): void
    {
        $svc = new ChannelConversationService;
        $raw = "Here are the latest updates.\n\nNext class is Tuesday [E1, E6, E5, E2]\n\n(From chat, POSSIBLE)";
        $clean = $svc->stripInternalEvidenceTags($raw);

        $this->assertStringNotContainsString('[E1', $clean);
        $this->assertStringNotContainsString('POSSIBLE', $clean);
        $this->assertStringContainsString('Next class is Tuesday', $clean);
        $this->assertSame(
            'Water opens Friday.',
            $svc->stripInternalEvidenceTags('Water opens Friday [E1].')
        );
    }

    public function test_ask_answered_member_reply_bolds_labels_per_channel(): void
    {
        $svc = new ChannelConversationService;

        $wa = $svc->askAnsweredMemberReply(
            'are we going to Lagos?',
            'No, for this programme cohort.',
            null,
            'whatsapp',
        );
        $this->assertStringStartsWith("Hi,\n\n", $wa);
        $this->assertStringContainsString("*You asked:*\nare we going to Lagos?", $wa);
        $this->assertStringContainsString(
            "*Here's an update from an admin:*\nNo, for this programme cohort.",
            $wa,
        );

        $tg = $svc->askAnsweredMemberReply(
            'are we going to Lagos?',
            'No, for this programme cohort.',
            'B A',
            'telegram_html',
        );
        $this->assertStringContainsString('Hi B A,', $tg);
        $this->assertStringContainsString('<b>You asked:</b>', $tg);
        $this->assertStringContainsString("<b>Here's an update from an admin:</b>", $tg);
        $this->assertStringNotContainsString('*You asked:*', $tg);

        $escaped = $svc->askAnsweredMemberReply(
            'A <b>trick</b> & more',
            'ok',
            null,
            'telegram_html',
        );
        $this->assertStringContainsString('A &lt;b&gt;trick&lt;/b&gt; &amp; more', $escaped);
    }

    public function test_short_intro_stacks_channel_links_not_one_jammed_line(): void
    {
        config([
            'zak_presence.show_web_chat' => true,
            'zak_presence.web_chat_url' => 'http://localhost:3000',
            'zak_presence.telegram_handle' => 'zak_meti_26_bot',
            'zak_presence.telegram_url' => '',
            'zak_presence.whatsapp_url' => 'https://wa.me/2347041131371',
        ]);

        $svc = new ChannelConversationService;
        $wa = $svc->shortIntro('whatsapp', 'whatsapp', 'group');

        $this->assertStringContainsString('*Also reach me on*', $wa);
        $this->assertStringContainsString("*Telegram*\nhttps://t.me/zak_meti_26_bot", $wa);
        $this->assertStringContainsString("*Web chat*\nhttp://localhost:3000", $wa);
        $this->assertStringContainsString('*Private chat*', $wa);
        $this->assertStringNotContainsString('Also on:', $wa);
        $this->assertStringNotContainsString(' · ', $wa);
    }

    public function test_feature_usage_reply_explains_request_or_improve(): void
    {
        $svc = new ChannelConversationService;

        $wa = $svc->featureUsageReply('whatsapp');
        $this->assertStringContainsString('```/feature```', $wa);
        $this->assertStringContainsString('*new feature*', $wa);
        $this->assertStringContainsString('*improve*', $wa);
        $this->assertStringContainsString('/feature Add reminders', $wa);
        $this->assertStringContainsString('/feature Make group replies shorter', $wa);

        $plain = $svc->featureUsageReply('plain');
        $this->assertStringContainsString('new feature', $plain);
        $this->assertStringContainsString('improve', $plain);
        $this->assertStringNotContainsString('*new feature*', $plain);

        $queued = $svc->featureQueuedReply(true);
        $this->assertStringContainsString('*feature request*', $queued);
        $this->assertStringContainsString('*admin*', $queued);
    }
}
