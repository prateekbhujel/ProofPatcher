<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ProofPatch\Agent\ProviderConfigStore;
use Symfony\Component\HttpClient\HttpClient;

$status = ProviderConfigStore::status();
if (!($status['providers']['meta']['configured'] ?? false)) {
    echo json_encode([
        'status' => 'not_configured',
        'message' => 'Meta API key is not configured in ProviderConfigStore. Please save it via /settings.',
    ]) . PHP_EOL;
    exit(1);
}

$key = ProviderConfigStore::resolveProviderKey('meta');
if (strlen($key) < 16) {
    echo json_encode([
        'status' => 'invalid_key',
        'message' => 'Configured Meta API key is invalid or too short.',
    ]) . PHP_EOL;
    exit(1);
}

// Strictly public synthetic snippet from prateekbhujel/proofpatch-review-demo
$publicSnippet = <<<'PHP'
<?php
declare(strict_types=1);

function orderTotal(int $unitPrice, int $quantity): int
{
    if ($unitPrice < 0 || $quantity < 0) {
        throw new InvalidArgumentException('Unit price and quantity must be non-negative.');
    }
    return $unitPrice * $quantity;
}
PHP;

$payload = [
    'model' => 'muse-spark-1.3',
    'max_tokens' => 256,
    'temperature' => 0.2,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are a code review assistant evaluating a public synthetic PHP snippet for input validation.',
        ],
        [
            'role' => 'user',
            'content' => "Review this public function for negative edge cases:\n\n```php\n{$publicSnippet}\n```\nProvide a concise 2-sentence summary and check if integer overflow or negative numbers are properly guarded.",
        ],
    ],
];

$client = HttpClient::create(['timeout' => 30]);

try {
    $response = $client->request('POST', 'https://api.meta.ai/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
        ],
        'json' => $payload,
    ]);

    $statusCode = $response->getStatusCode();
    $rawBody = $response->getContent(false);

    // Sanitize any accidental appearance of the key in the response
    $sanitizedBody = str_replace($key, '[REDACTED_KEY]', $rawBody);

    if ($statusCode !== 200) {
        echo json_encode([
            'status' => 'provider_error',
            'http_status' => $statusCode,
            'response' => json_decode($sanitizedBody, true) ?: $sanitizedBody,
        ]) . PHP_EOL;
        exit(2);
    }

    $json = json_decode($sanitizedBody, true);
    $usage = $json['usage'] ?? [];
    $content = $json['choices'][0]['message']['content'] ?? '';

    echo json_encode([
        'status' => 'success',
        'http_status' => $statusCode,
        'model' => $json['model'] ?? 'muse-spark-1.3',
        'usage' => $usage,
        'review_summary' => trim($content),
    ]) . PHP_EOL;
} catch (\Throwable $e) {
    echo json_encode([
        'status' => 'exception',
        'error_class' => get_class($e),
        'message' => str_replace($key, '[REDACTED_KEY]', $e->getMessage()),
    ]) . PHP_EOL;
    exit(3);
}
