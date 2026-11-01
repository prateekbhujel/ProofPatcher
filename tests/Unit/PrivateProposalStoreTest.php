<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PrivateProposalStore;

final class PrivateProposalStoreTest extends TestCase
{
    private string $directory;
    private PrivateProposalStore $store;
    private array $binding;
    private array $grant;
    private array $proposal;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/private-proposal-test-' . bin2hex(random_bytes(8));
        $this->store = new PrivateProposalStore($this->directory, new Encrypter(random_bytes(32), 'aes-256-cbc'), random_bytes(32));
        $candidate = '<?php /* confidential candidate */';
        $this->binding = ['githubUserId' => 17, 'repository' => 'owner/private-repo', 'issueNumber' => 42,
            'baseCommit' => str_repeat('a', 40), 'path' => 'src/secret.php',
            'sourceSha256' => hash('sha256', 'source'), 'candidateSha256' => hash('sha256', $candidate)];
        $this->grant = ['userId' => 17, 'repositories' => ['owner/private-repo' => true]];
        $this->proposal = ['candidateSource' => $candidate, 'model' => 'model', 'requestDigest' => str_repeat('b', 64),
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testEncryptedRecordRequiresExactBindingAndCurrentGrant(): void
    {
        $this->store->record($this->binding, $this->grant, $this->proposal);
        $found = $this->store->lookup($this->binding, $this->grant);
        self::assertNotNull($found);
        self::assertSame($this->proposal['candidateSource'], $found['candidateSource']);
        self::assertSame('model', $found['model']);
        self::assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5], $found['usage']);
        $files = glob($this->directory . '/*.enc');
        self::assertCount(1, $files);
        $ciphertext = (string) file_get_contents($files[0]);
        self::assertStringNotContainsString('confidential candidate', $ciphertext);
        self::assertStringNotContainsString('owner/private-repo', $ciphertext);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        foreach (['githubUserId' => 18, 'repository' => 'owner/other', 'issueNumber' => 43,
            'baseCommit' => str_repeat('c', 40), 'path' => 'src/other.php',
            'sourceSha256' => hash('sha256', 'other'), 'candidateSha256' => hash('sha256', 'other')] as $field => $value) {
            $changed = $this->binding;
            $changed[$field] = $value;
            self::assertNull($this->store->lookup($changed, $this->grant), $field);
        }
        self::assertNull($this->store->lookup($this->binding, ['userId' => 17, 'repositories' => []]));
        self::assertNull($this->store->lookup($this->binding, ['userId' => 18, 'repositories' => ['owner/private-repo' => true]]));
    }

    public function testTamperedCiphertextFailsClosed(): void
    {
        $this->store->record($this->binding, $this->grant, $this->proposal);
        $file = glob($this->directory . '/*.enc')[0];
        file_put_contents($file, str_repeat('A', strlen((string) file_get_contents($file))));
        self::assertNull($this->store->lookup($this->binding, $this->grant));
    }

    public function testRevokedGrantCannotWrite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->record($this->binding, ['userId' => 17, 'repositories' => []], $this->proposal);
    }

    public function testCandidateDigestMismatchCannotBeRecorded(): void
    {
        $this->proposal['candidateSource'] = 'changed';
        $this->expectException(\InvalidArgumentException::class);
        $this->store->record($this->binding, $this->grant, $this->proposal);
    }

    public function testIdenticalRewriteIsIdempotentButConflictingOverwriteThrows(): void
    {
        $this->store->record($this->binding, $this->grant, $this->proposal);
        $this->store->record($this->binding, $this->grant, $this->proposal);
        self::assertNotNull($this->store->lookup($this->binding, $this->grant));

        $conflicting = $this->proposal;
        $conflicting['usage'] = ['prompt_tokens' => 999, 'completion_tokens' => 999];
        try {
            $this->store->record($this->binding, $this->grant, $conflicting);
            self::fail('Conflicting provenance overwrite must throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already recorded', $e->getMessage());
        }
        $kept = $this->store->lookup($this->binding, $this->grant);
        self::assertNotNull($kept);
        self::assertSame(10, $kept['usage']['prompt_tokens']);
    }

    public function testInvalidBindingsAreRejected(): void
    {
        foreach ([
            ['githubUserId' => 0], ['githubUserId' => '17'],
            ['repository' => 'not a repo'], ['repository' => ''],
            ['issueNumber' => 0], ['baseCommit' => 'short'],
            ['path' => ''], ['sourceSha256' => 'zz'], ['candidateSha256' => 'zz'],
        ] as $override) {
            $binding = array_merge($this->binding, $override);
            try {
                $this->store->record($binding, $this->grant, $this->proposal);
                self::fail('Invalid binding must be rejected: ' . json_encode($override));
            } catch (\InvalidArgumentException) {
            }
            self::assertNull($this->store->lookup($binding, $this->grant));
        }
    }
}
