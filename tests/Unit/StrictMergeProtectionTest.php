<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StrictMergeProtectionTest extends TestCase
{
    public function testStrictProtectionPasses(): void
    {
        self::assertTrue($this->client(self::protection())->hasStrictMergeProtection('test', 'owner/repo', 'feature/branch'));
    }

    public function testUnknownOrWeakProtectionFailsClosed(): void
    {
        $weak = self::protection();
        $weak['required_status_checks']['strict'] = false;
        self::assertFalse($this->client($weak)->hasStrictMergeProtection('test', 'owner/repo', 'main'));
        $weak = self::protection();
        $weak['required_status_checks']['contexts'] = [];
        self::assertFalse($this->client($weak)->hasStrictMergeProtection('test', 'owner/repo', 'main'));
        $weak = self::protection();
        $weak['required_conversation_resolution']['enabled'] = false;
        self::assertFalse($this->client($weak)->hasStrictMergeProtection('test', 'owner/repo', 'main'));
        $weak = self::protection();
        $weak['required_pull_request_reviews']['bypass_pull_request_allowances'] = ['users' => [['id' => 1]], 'teams' => [], 'apps' => []];
        self::assertFalse($this->client($weak)->hasStrictMergeProtection('test', 'owner/repo', 'main'));
        self::assertFalse($this->client([], 403)->hasStrictMergeProtection('test', 'owner/repo', 'main'));
        self::assertFalse($this->client([], 404)->hasStrictMergeProtection('test', 'owner/repo', 'main'));
        self::assertFalse($this->client(self::protection())->hasStrictMergeProtection('test', 'owner/repo', null));
    }

    private static function protection(): array
    {
        return ['required_status_checks' => ['strict' => true, 'contexts' => ['ci']],
            'enforce_admins' => ['enabled' => true], 'required_conversation_resolution' => ['enabled' => true]];
    }

    private function client(array $data, int $status = 200): GitHubAppClient
    {
        return new GitHubAppClient(new MockHttpClient(static fn () => new MockResponse(json_encode($data), ['http_code' => $status])));
    }
}
