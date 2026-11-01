<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\HariError;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\EvidenceVerifySkill;
use ProofPatch\Evidence\CanonicalJson;
use ProofPatch\Evidence\Receipt;

final class EvidenceVerifySkillTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/proofpatch-evidence-skill-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['receipt.json', 'result.json'] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @return array{manifest: array, sha256: string} */
    private function sealValidReceipt(): array
    {
        // FAILED decisions carry no gate requirements: minimal valid fixture.
        file_put_contents($this->directory.'/result.json', CanonicalJson::encode([
            'id' => '20261101T000000Z-abcdefabcdef',
            'decision' => 'FAILED',
            'baseCommit' => str_repeat('b', 40),
        ]));
        $sealed = (new Receipt())->create($this->directory);
        file_put_contents($this->directory.'/receipt.json', CanonicalJson::encode($sealed));
        return $sealed;
    }

    public function testValidReceiptReturnsPayload(): void
    {
        $sealed = $this->sealValidReceipt();

        $result = (new EvidenceVerifySkill())->invoke(['directory' => $this->directory]);

        self::assertTrue($result['valid']);
        self::assertSame('VALID', $result['receipt']['integrity']);
        self::assertSame($sealed['sha256'], $result['receipt']['sha256']);
        self::assertSame('FAILED', $result['receipt']['decision']);
    }

    public function testTamperedEvidenceIsDataNotError(): void
    {
        $this->sealValidReceipt();
        file_put_contents($this->directory.'/result.json', CanonicalJson::encode([
            'id' => '20261101T000000Z-abcdefabcdef',
            'decision' => 'VERIFIED',
            'baseCommit' => str_repeat('b', 40),
        ]));

        $result = (new EvidenceVerifySkill())->invoke(['directory' => $this->directory]);

        self::assertFalse($result['valid']);
        self::assertStringNotContainsString($this->directory, (string) $result['reason']);
    }

    public function testExpectedDigestMismatchIsData(): void
    {
        $this->sealValidReceipt();

        $result = (new EvidenceVerifySkill())->invoke([
            'directory' => $this->directory,
            'expected' => str_repeat('0', 64),
        ]);

        self::assertFalse($result['valid']);
    }

    public function testMissingDirectoryThrows(): void
    {
        try {
            (new EvidenceVerifySkill())->invoke(['directory' => $this->directory.'/nope']);
            self::fail('Missing directory was accepted.');
        } catch (HariError $error) {
            self::assertSame('invalid_input', $error->code());
        }
    }

    public function testMalformedExpectedDigestThrows(): void
    {
        $this->sealValidReceipt();
        try {
            (new EvidenceVerifySkill())->invoke(['directory' => $this->directory, 'expected' => 'xyz']);
            self::fail('Malformed digest was accepted.');
        } catch (HariError $error) {
            self::assertSame('invalid_input', $error->code());
        }
    }
}
