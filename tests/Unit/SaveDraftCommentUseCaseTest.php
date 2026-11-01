<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\SaveDraftCommentInput;
use ProofPatch\Application\Review\SaveDraftCommentUseCase;

final class SaveDraftCommentUseCaseTest extends TestCase
{
    private const HEAD = '1111111111111111111111111111111111111111';

    private string $samplePatch = <<<'DIFF'
@@ -10,3 +10,5 @@
  public function testExample()
  {
+     $guard = true;
+     $this->assertTrue($guard);
  }
DIFF;

    /** @return array<string, mixed> */
    private function pull(): array
    {
        return ['repository' => 'acme/demo', 'headSha' => self::HEAD, 'isPrivate' => false];
    }

    /** @return array<string, mixed> */
    private function preview(): array
    {
        return ['headSha' => self::HEAD, 'files' => [['name' => 'src/A.php', 'patch' => $this->samplePatch]]];
    }

    /** @return array<string, mixed> */
    private function raw(array $overrides = []): array
    {
        return array_merge([
            'path' => 'src/A.php', 'comment' => 'Nice guard.', 'headSha' => self::HEAD,
            'line' => 12, 'side' => 'RIGHT',
        ], $overrides);
    }

    public function testSavesAnchoredLineDraft(): void
    {
        $result = (new SaveDraftCommentUseCase())->execute(
            SaveDraftCommentInput::fromRaw($this->raw(), $this->pull())->withPreview($this->preview())
        );

        self::assertTrue($result->ok);
        self::assertSame('src/A.php', $result->draft['path']);
        self::assertSame(12, $result->draft['line']);
        self::assertSame('Nice guard.', $result->draft['comment']);
        self::assertSame(self::HEAD, $result->draft['headSha']);
        self::assertSame(hash('sha256', 'src/A.php:12:'.self::HEAD), $result->draft['id']);
        self::assertTrue($result->draft['commentable']);
        self::assertSame('addition', $result->draft['lineType']);
    }

    public function testSavesFileLevelDraft(): void
    {
        $result = (new SaveDraftCommentUseCase())->execute(
            SaveDraftCommentInput::fromRaw($this->raw(['line' => null]), $this->pull())->withPreview($this->preview())
        );

        self::assertTrue($result->ok);
        self::assertNull($result->draft['line']);
        self::assertSame(hash('sha256', 'src/A.php:file:'.self::HEAD), $result->draft['id']);
    }

    public function testUncommentableLineFails(): void
    {
        $result = (new SaveDraftCommentUseCase())->execute(
            SaveDraftCommentInput::fromRaw($this->raw(['line' => 99]), $this->pull())->withPreview($this->preview())
        );

        self::assertFalse($result->ok);
        self::assertStringContainsString('not commentable', $result->error);
    }

    public function testMissingPreviewFails(): void
    {
        $result = (new SaveDraftCommentUseCase())->execute(
            SaveDraftCommentInput::fromRaw($this->raw(), $this->pull())
        );

        self::assertFalse($result->ok);
        self::assertSame('Open the pull request again before adding comments.', $result->error);
    }

    public function testGuardsRejectBadInput(): void
    {
        $cases = [
            'empty path' => ['path' => ''],
            'empty comment' => ['comment' => '  '],
            'long comment' => ['comment' => str_repeat('x', 2001)],
            'bad sha' => ['headSha' => 'zzz'],
            'head mismatch' => ['headSha' => str_repeat('2', 40)],
        ];
        foreach ($cases as $name => $overrides) {
            try {
                SaveDraftCommentInput::fromRaw($this->raw($overrides), $this->pull());
                self::fail("Guard accepted: {$name}.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
