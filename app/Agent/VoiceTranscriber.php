<?php

declare(strict_types=1);

namespace ProofPatch\Agent;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Speech-to-text through the already-configured Muse provider (Baseten or
 * Meta Model API), using its OpenAI-compatible audio transcription route.
 * A speech model is never guessed: the operator names one their provider
 * serves via PROOFPATCH_TRANSCRIBE_MODEL. Without it, callers fall back to
 * local transcription and this class refuses cleanly.
 */
final class VoiceTranscriber
{
    private ?string $model;

    public function __construct(
        private HttpClientInterface $http,
        ?string $model = null,
    ) {
        $this->model = $model ?? self::configuredModel();
    }

    public static function configuredModel(): ?string
    {
        $value = getenv('PROOFPATCH_TRANSCRIBE_MODEL');
        if (!is_string($value) || trim($value) === '') {
            $value = $_ENV['PROOFPATCH_TRANSCRIBE_MODEL'] ?? $_SERVER['PROOFPATCH_TRANSCRIBE_MODEL'] ?? null;
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function providerAvailable(): bool
    {
        return $this->model !== null;
    }

    /**
     * @param array{provider: string, endpoint: string} $target
     * @return array{transcript: string, engine: string}
     */
    public function transcribe(string $audioPath, string $apiKey, array $target): array
    {
        if ($this->model === null) {
            throw new \RuntimeException('Set PROOFPATCH_TRANSCRIBE_MODEL to a speech model your configured provider serves, or use local transcription.');
        }
        if (!is_file($audioPath) || trim($apiKey) === '') {
            throw new \RuntimeException('Audio file or provider key is missing.');
        }
        $endpoint = preg_replace('#/chat/completions$#', '/audio/transcriptions', (string) ($target['endpoint'] ?? ''));
        if (!is_string($endpoint) || $endpoint === (string) ($target['endpoint'] ?? '')) {
            throw new \RuntimeException('The configured provider endpoint cannot serve transcription.');
        }

        $response = $this->http->request('POST', $endpoint, [
            'headers' => ['Authorization' => 'Bearer '.$apiKey],
            'body' => [
                'file' => fopen($audioPath, 'r'),
                'model' => $this->model,
            ],
            'timeout' => 30,
            'max_redirects' => 0,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Provider transcription returned HTTP '.$response->getStatusCode().'.');
        }
        $data = json_decode($response->getContent(false), true);
        $text = is_array($data) ? trim((string) ($data['text'] ?? '')) : '';
        if ($text === '') {
            throw new \RuntimeException('Provider transcription returned no text.');
        }
        return ['transcript' => $text, 'engine' => 'provider:'.($target['provider'] ?? 'unknown')];
    }
}
