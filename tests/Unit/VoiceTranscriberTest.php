<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\VoiceTranscriber;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VoiceTranscriberTest extends TestCase
{
    public function testUsesConfiguredProviderTranscriptionEndpoint(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url];
            return new MockResponse(json_encode(['text' => '  the total looks wrong  ']));
        });
        $audio = tempnam(sys_get_temp_dir(), 'voice-test-');
        file_put_contents($audio, 'fake-audio');
        $transcriber = new VoiceTranscriber($http, 'speech-model-x');

        $result = $transcriber->transcribe($audio, 'test-key', [
            'provider' => 'meta', 'endpoint' => 'https://api.meta.ai/v1/chat/completions',
        ]);

        self::assertSame('the total looks wrong', $result['transcript']);
        self::assertSame('provider:meta', $result['engine']);
        self::assertSame('POST', $requests[0][0]);
        self::assertSame('https://api.meta.ai/v1/audio/transcriptions', $requests[0][1]);
    }

    public function testProviderPathIsUnavailableWithoutConfiguredSpeechModel(): void
    {
        $transcriber = new VoiceTranscriber(new MockHttpClient(), null);
        self::assertFalse($transcriber->providerAvailable());
        try {
            $transcriber->transcribe(__FILE__, 'test-key', [
                'provider' => 'meta', 'endpoint' => 'https://api.meta.ai/v1/chat/completions',
            ]);
            self::fail('Expected refusal without a configured speech model');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('PROOFPATCH_TRANSCRIBE_MODEL', $e->getMessage());
        }
    }
}
