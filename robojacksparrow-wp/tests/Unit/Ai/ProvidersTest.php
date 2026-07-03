<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Ai;

use RoboJackSparrow\Ai\Dto\LLMRequest;
use RoboJackSparrow\Ai\LLMException;
use RoboJackSparrow\Ai\Providers\AnthropicProvider;
use RoboJackSparrow\Ai\Providers\DeepSeekProvider;
use RoboJackSparrow\Ai\Providers\GeminiProvider;
use RoboJackSparrow\Ai\Providers\GroqProvider;
use RoboJackSparrow\Ai\Providers\OpenAIProvider;
use RoboJackSparrow\Tests\HttpFixtures;
use RoboJackSparrow\Tests\TestCase;

final class ProvidersTest extends TestCase
{
    private LLMRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new LLMRequest(prompt: 'Say hello', systemPrompt: 'You are terse.');
    }

    public function testOpenAiCompatibleProvidersParseChatCompletionsResponse(): void
    {
        HttpFixtures::set('POST', 'https://api.openai.com/v1/chat/completions', $this->chatCompletionFixture('Hello from OpenAI', 'gpt-4o-mini'));
        HttpFixtures::set('POST', 'https://api.groq.com/openai/v1/chat/completions', $this->chatCompletionFixture('Hello from Groq', 'llama-3.1-70b-versatile'));
        HttpFixtures::set('POST', 'https://api.deepseek.com/v1/chat/completions', $this->chatCompletionFixture('Hello from DeepSeek', 'deepseek-chat'));

        $this->assertSame('Hello from OpenAI', (new OpenAIProvider('sk-test'))->send($this->request)->getContent());
        $this->assertSame('Hello from Groq', (new GroqProvider('gsk-test'))->send($this->request)->getContent());
        $this->assertSame('Hello from DeepSeek', (new DeepSeekProvider('ds-test'))->send($this->request)->getContent());
    }

    public function testAnthropicSumsInputAndOutputTokens(): void
    {
        HttpFixtures::set('POST', 'https://api.anthropic.com/v1/messages', [
            'response' => ['code' => 200],
            'body'     => json_encode([
                'content'     => [['text' => 'Hello from Claude']],
                'usage'       => ['input_tokens' => 18, 'output_tokens' => 12],
                'model'       => 'claude-3-haiku-20240307',
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $response = (new AnthropicProvider('sk-ant-test'))->send($this->request);

        $this->assertSame('Hello from Claude', $response->getContent());
        $this->assertSame(30, $response->getTokensUsed());
        $this->assertSame('end_turn', $response->getFinishReason());
    }

    public function testGeminiParsesCandidatesAndUsageMetadata(): void
    {
        HttpFixtures::setPrefix('POST', 'https://generativelanguage.googleapis.com/v1beta/models/', fn () => [
            'response' => ['code' => 200],
            'body'     => json_encode([
                'candidates'    => [['content' => ['parts' => [['text' => 'Hello from Gemini']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['totalTokenCount' => 40, 'promptTokenCount' => 25, 'candidatesTokenCount' => 15],
            ]),
        ]);

        $response = (new GeminiProvider('gm-test'))->send($this->request);

        $this->assertSame('Hello from Gemini', $response->getContent());
        $this->assertSame(40, $response->getTokensUsed());
    }

    public function testReasoningModelsUseMaxCompletionTokensAndOmitTemperature(): void
    {
        $capturedBody = null;
        HttpFixtures::setPrefix('POST', 'https://api.openai.com/v1/chat/completions', function ($url, $args) use (&$capturedBody) {
            $capturedBody = json_decode($args['body'], true);

            return $this->chatCompletionFixture('ok', 'gpt-5.4-mini');
        });

        (new OpenAIProvider('sk-test', 'gpt-5.4-mini'))->send($this->request);

        $this->assertArrayHasKey('max_completion_tokens', $capturedBody, 'reasoning models require max_completion_tokens, not max_tokens');
        $this->assertArrayNotHasKey('max_tokens', $capturedBody);
        $this->assertArrayNotHasKey('temperature', $capturedBody, 'reasoning models reject a custom temperature');
    }

    public function testClassicModelsStillUseMaxTokensAndTemperature(): void
    {
        $capturedBody = null;
        HttpFixtures::setPrefix('POST', 'https://api.openai.com/v1/chat/completions', function ($url, $args) use (&$capturedBody) {
            $capturedBody = json_decode($args['body'], true);

            return $this->chatCompletionFixture('ok', 'gpt-4o-mini');
        });

        (new OpenAIProvider('sk-test', 'gpt-4o-mini'))->send($this->request);

        $this->assertArrayHasKey('max_tokens', $capturedBody);
        $this->assertArrayHasKey('temperature', $capturedBody);
        $this->assertArrayNotHasKey('max_completion_tokens', $capturedBody);
    }

    public function testEmptyApiKeyIsRejectedWithoutAnyHttpCall(): void
    {
        $this->expectException(LLMException::class);
        (new OpenAIProvider(''))->send($this->request);
    }

    public function testApiErrorSurfacesAsLlmException(): void
    {
        HttpFixtures::set('POST', 'https://api.anthropic.com/v1/messages', [
            'response' => ['code' => 401],
            'body'     => json_encode(['error' => ['message' => 'invalid x-api-key']]),
        ]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessageMatches('/invalid x-api-key/');
        (new AnthropicProvider('bad-key'))->send($this->request);
    }

    private function chatCompletionFixture(string $content, string $model): array
    {
        return [
            'response' => ['code' => 200],
            'body'     => json_encode([
                'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
                'usage'   => ['total_tokens' => 30, 'prompt_tokens' => 20, 'completion_tokens' => 10],
                'model'   => $model,
            ]),
        ];
    }
}
