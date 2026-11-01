<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and cryptographically authenticates the dedicated test case directly from GitHub.
 *
 * Enforces:
 * - Exact configured repository
 * - Exact issue: #1 (must be open, title verified)
 * - Exact configured base commit
 * - Exact source file bytes: src/OrderTotal.php SHA-256
 * - Exact test harness file bytes: tests/order_total.php SHA-256
 * - Fails closed on any drift, tampering, or missing authorization.
 */
final readonly class DedicatedTestRepoFetcher
{
    public const ISSUE_NUMBER = 1;
    public const SOURCE_PATH = 'src/OrderTotal.php';
    public const TEST_PATH = 'tests/order_total.php';

    public const EXPECTED_SOURCE_SHA256 = '54bc37fb2b005badcf309c5a7091ba6a2e704fc54c8eff9e9c7afee7e4054859';
    public const EXPECTED_TEST_SHA256 = '263eb70cbf346221858b9c64ba97384105d3804089a653d63d016664e8c1320e';

    public function __construct(private ?HttpClientInterface $http = null)
    {
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->http ?? HttpClient::create();
    }

    /**
     * Fetches issue #1, src/OrderTotal.php, and tests/order_total.php at the configured base commit from GitHub.
     * Validates digests and returns authenticated issue context.
     *
     * @return array{
     *     repository: string,
     *     issueNumber: int,
     *     issueUrl: string,
     *     commit: string,
     *     title: string,
     *     body: string,
     *     path: string,
     *     source: string,
     *     sourceSha256: string,
     *     testSource: string,
     *     testSha256: string,
     * }
     */
    public function fetchAndVerify(string $token): array
    {
        DedicatedTestRepo::requireName();
        DedicatedTestRepo::requireBaseCommit();
        if (trim($token) === '') {
            throw new \InvalidArgumentException('GitHub authentication token is required to fetch dedicated test case.');
        }

        $client = $this->getHttpClient();
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'ProofPatcher',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        // 1. Fetch Issue #1
        $issueUrl = 'https://api.github.com/repos/' . DedicatedTestRepo::requireName() . '/issues/' . self::ISSUE_NUMBER;
        $issueRes = $client->request('GET', $issueUrl, ['headers' => $headers, 'timeout' => 15, 'max_duration' => 20, 'max_redirects' => 0]);
        $status = $issueRes->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("Failed to fetch issue from GitHub: HTTP {$status}. Check repository access token.");
        }

        $issueData = $issueRes->toArray();
        if (($issueData['number'] ?? 0) !== self::ISSUE_NUMBER) {
            throw new \RuntimeException('Issue number mismatch in GitHub API response.');
        }

        if (($issueData['state'] ?? '') !== 'open') {
            throw new \RuntimeException("Dedicated test issue #1 is closed; expected open state.");
        }

        $title = (string) ($issueData['title'] ?? '');
        $body = (string) ($issueData['body'] ?? '');
        if (!str_contains(strtolower($title), 'zero-quantity')) {
            throw new \RuntimeException("Unexpected issue title: '{$title}'. Expected zero-quantity item bug.");
        }

        // 2. Fetch src/OrderTotal.php at base commit ref
        $srcApiUrl = 'https://api.github.com/repos/' . DedicatedTestRepo::requireName() . '/contents/' . self::SOURCE_PATH . '?ref=' . DedicatedTestRepo::requireBaseCommit();
        $srcRes = $client->request('GET', $srcApiUrl, ['headers' => $headers, 'timeout' => 15, 'max_duration' => 20, 'max_redirects' => 0]);
        if ($srcRes->getStatusCode() !== 200) {
            throw new \RuntimeException("Failed to fetch source file from GitHub at commit " . DedicatedTestRepo::requireBaseCommit());
        }

        $srcData = $srcRes->toArray();
        $rawSrcBase64 = str_replace(["\n", "\r", " "], '', (string) ($srcData['content'] ?? ''));
        $srcContent = base64_decode($rawSrcBase64, true);
        if (!is_string($srcContent)) {
            throw new \RuntimeException('Failed to decode base64 source content from GitHub.');
        }

        $actualSrcSha = hash('sha256', $srcContent);
        if (!hash_equals(self::EXPECTED_SOURCE_SHA256, $actualSrcSha)) {
            throw new \RuntimeException(
                "Source file digest mismatch at commit " . DedicatedTestRepo::requireBaseCommit() . ": expected " . self::EXPECTED_SOURCE_SHA256 . ", got {$actualSrcSha}."
            );
        }

        // 3. Fetch tests/order_total.php at base commit ref
        $testApiUrl = 'https://api.github.com/repos/' . DedicatedTestRepo::requireName() . '/contents/' . self::TEST_PATH . '?ref=' . DedicatedTestRepo::requireBaseCommit();
        $testRes = $client->request('GET', $testApiUrl, ['headers' => $headers, 'timeout' => 15, 'max_duration' => 20, 'max_redirects' => 0]);
        if ($testRes->getStatusCode() !== 200) {
            throw new \RuntimeException("Failed to fetch test harness file from GitHub at commit " . DedicatedTestRepo::requireBaseCommit());
        }

        $testData = $testRes->toArray();
        $rawTestBase64 = str_replace(["\n", "\r", " "], '', (string) ($testData['content'] ?? ''));
        $testContent = base64_decode($rawTestBase64, true);
        if (!is_string($testContent)) {
            throw new \RuntimeException('Failed to decode base64 test harness content from GitHub.');
        }

        $actualTestSha = hash('sha256', $testContent);
        $isKnownHarness = hash_equals(self::EXPECTED_TEST_SHA256, $actualTestSha)
            || hash_equals(\ProofPatch\Verifier\OwnedCaseVerifier::KNOWN_TEST_SHA256, $actualTestSha);
        if (!$isKnownHarness) {
            throw new \RuntimeException(
                "Test harness digest mismatch at commit " . DedicatedTestRepo::requireBaseCommit() . ": no approved fixture matched {$actualTestSha}."
            );
        }

        return [
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => self::ISSUE_NUMBER,
            'issueUrl' => 'https://github.com/' . DedicatedTestRepo::requireName() . '/issues/' . self::ISSUE_NUMBER,
            'commit' => DedicatedTestRepo::requireBaseCommit(),
            'title' => $title,
            'body' => $body,
            'path' => self::SOURCE_PATH,
            'source' => $srcContent,
            'sourceSha256' => $actualSrcSha,
            'testSource' => $testContent,
            'testSha256' => $actualTestSha,
        ];
    }
}
