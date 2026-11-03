<?php

declare(strict_types=1);

namespace Tests\Unit;

use ProofPatch\Context\RepositoryPathSearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RepositoryPathSearchTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/hari-context-'.bin2hex(random_bytes(8));
        mkdir($this->base, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function testRepositoriesAndExactRevisionsStayIsolatedFromTheWorkingTree(): void
    {
        $first = $this->repo('first', ['src/Order.php' => 'one', 'docs/Order.md' => 'docs']);
        $firstCommit = $this->git($first, ['rev-parse', 'HEAD']);
        $second = $this->repo('second', ['other/Order.php' => 'two', 'src/Order.php' => 'three']);
        mkdir($first.'/next');
        file_put_contents($first.'/next/Order.php', 'next');
        $this->git($first, ['add', 'next/Order.php']);
        $this->git($first, ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'next']);
        file_put_contents($first.'/untrackedOrder.php', 'untracked');
        unlink($first.'/src/Order.php');

        $search = new RepositoryPathSearch();
        $old = $search->search([$first], 'order', $firstCommit);
        self::assertSame($firstCommit, $old[0]['commit']);
        self::assertSame(['docs/Order.md', 'src/Order.php'], $old[0]['paths']);
        $both = $search->search([$first, $second], 'Order');
        self::assertSame(['docs/Order.md', 'next/Order.php', 'src/Order.php'], $both[0]['paths']);
        self::assertSame(['other/Order.php', 'src/Order.php'], $both[1]['paths']);
        self::assertNotSame($both[0]['commit'], $both[1]['commit']);
        self::assertSame(3, $both[0]['scanned']);
    }

    public function testResultLimitAndUnsafePathsAreReported(): void
    {
        $files = [];
        for ($i = 0; $i < 30; ++$i) $files[sprintf('orders/%02d.php', $i)] = 'test';
        $files["orders/bad\x1b[31m.php"] = 'bad';
        $repo = $this->repo('many', $files);
        $result = (new RepositoryPathSearch())->search([$repo], 'orders')[0];
        self::assertSame(31, $result['scanned']);
        self::assertSame(30, $result['matches']);
        self::assertCount(25, $result['paths']);
        self::assertSame('orders/00.php', $result['paths'][0]);
        self::assertSame('orders/24.php', $result['paths'][24]);
        self::assertSame(1, $result['omittedUnsafe']);
    }

    public function testInvalidInputsAndNonRepositoriesFailClosed(): void
    {
        $search = new RepositoryPathSearch();
        foreach ([
            [[], 'x', 'HEAD'],
            [array_fill(0, 6, $this->base), 'x', 'HEAD'],
            [[$this->base], "bad\x1b", 'HEAD'],
            [[$this->base], 'x', '--help'],
            [[$this->base], 'x', 'HEAD'],
        ] as [$repos, $query, $revision]) {
            try {
                $search->search($repos, $query, $revision);
                self::fail('Expected search to reject invalid input.');
            } catch (\InvalidArgumentException|\RuntimeException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    public function testCommandReturnsJsonForTwoRepositories(): void
    {
        $one = $this->repo('one', ['src/Task.php' => 'one']);
        $two = $this->repo('two', ['src/Task.php' => 'two']);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/artisan', 'kernel:context', 'Task', '--repo='.$one, '--repo='.$two, '--json'], dirname(__DIR__, 2), null, null, 15);
        $process->mustRun();
        $json = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('source content not inspected', $json['scope']);
        self::assertCount(2, $json['repositories']);
        self::assertSame(['src/Task.php'], $json['repositories'][0]['paths']);
        self::assertSame(['src/Task.php'], $json['repositories'][1]['paths']);
    }

    public function testContentSearchIsRevisionPinnedIsolatedAndSkipsBinary(): void
    {
        $one = $this->repo('one', [
            'big.php' => str_repeat("nothing here\n", 5000)."needle old\nneedle second\n",
            'binary.dat' => "\0needle binary\n",
        ]);
        $old = $this->git($one, ['rev-parse', 'HEAD']);
        $two = $this->repo('two', ['big.php' => "needle other\n"]);
        file_put_contents($one.'/big.php', "needle new uncommitted\n");

        $search = new RepositoryPathSearch();
        $results = $search->searchContent([$one, $two], 'needle');
        self::assertSame($old, $results[0]['commit']);
        self::assertSame([['path' => 'big.php', 'line' => 5001, 'text' => 'needle old', 'textTruncated' => false]], $results[0]['results']);
        self::assertSame([['path' => 'big.php', 'line' => 1, 'text' => 'needle other', 'textTruncated' => false]], $results[1]['results']);
        self::assertSame([], $search->searchContent([$one], 'missing-needle')[0]['results']);
        $this->git($one, ['add', 'big.php']);
        $this->git($one, ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'change']);
        self::assertSame('needle new uncommitted', $search->searchContent([$one], 'needle')[0]['results'][0]['text']);
        self::assertSame('needle old', $search->searchContent([$one], 'needle', $old)[0]['results'][0]['text']);
    }

    public function testContentSearchLimitsAndSanitizesUntrustedText(): void
    {
        $files = [];
        for ($i = 0; $i < 30; ++$i) $files[sprintf('src/%02d.php', $i)] = "needle\x1b[31m".str_repeat('x', 500)."\n";
        $files["src/bad\x1b.php"] = "needle\n";
        $repo = $this->repo('many-content', $files);
        $result = (new RepositoryPathSearch())->searchContent([$repo], 'needle')[0];
        self::assertSame(30, $result['matches']);
        self::assertCount(25, $result['results']);
        self::assertSame(1, $result['omittedUnsafe']);
        self::assertStringNotContainsString("\x1b", $result['results'][0]['text']);
        self::assertLessThanOrEqual(300, mb_strwidth($result['results'][0]['text'], 'UTF-8'));
        self::assertTrue($result['results'][0]['textTruncated']);
    }

    public function testContentSearchIgnoresRepositoryGrepColumnAndTextconvConfiguration(): void
    {
        $repo = $this->repo('git-config', [
            '.gitattributes' => "*.txt diff=audit\n",
            'src/example.txt' => "hello needle world\n",
        ]);
        $marker = $this->base.'/textconv-ran';
        $this->git($repo, ['config', 'grep.column', 'true']);
        $this->git($repo, ['config', 'diff.audit.textconv', 'touch '.escapeshellarg($marker)]);

        $result = (new RepositoryPathSearch())->searchContent([$repo], 'needle')[0];
        self::assertSame([['path' => 'src/example.txt', 'line' => 1, 'text' => 'hello needle world', 'textTruncated' => false]], $result['results']);
        self::assertFileDoesNotExist($marker);
    }

    public function testSubdirectoryAndBareRepositorySearchUseRepositoryRoot(): void
    {
        $repo = $this->repo('root', ['outside.txt' => "needle outside\n", 'sub/inside.txt' => "needle inside\n"]);
        $search = new RepositoryPathSearch();
        $paths = $search->search([$repo.'/sub'], '.txt')[0];
        $content = $search->searchContent([$repo.'/sub'], 'needle')[0];

        self::assertSame(realpath($repo), $paths['repository']);
        self::assertSame(realpath($repo), $content['repository']);
        self::assertSame(['outside.txt', 'sub/inside.txt'], $paths['paths']);
        self::assertSame(['outside.txt', 'sub/inside.txt'], array_column($content['results'], 'path'));

        $bare = $this->base.'/bare.git';
        (new Process(['git', 'clone', '--bare', $repo, $bare]))->mustRun();
        $bareResult = $search->searchContent([$bare], 'needle')[0];
        self::assertSame(realpath($bare), $bareResult['repository']);
        self::assertSame(['outside.txt', 'sub/inside.txt'], array_column($bareResult['results'], 'path'));

        $linked = $this->base.'/linked';
        $this->git($repo, ['worktree', 'add', '--detach', $linked, 'HEAD']);
        $linkedResult = $search->searchContent([$linked], 'needle')[0];
        self::assertSame(realpath($linked), $linkedResult['repository']);
        self::assertSame(['outside.txt', 'sub/inside.txt'], array_column($linkedResult['results'], 'path'));
    }

    public function testSelectedRepositoryCannotRedirectSearchToAnotherRepository(): void
    {
        $first = $this->repo('first-redirect', ['file.txt' => "needle first\n"]);
        $sibling = $this->repo('sibling-redirect', ['file.txt' => "needle sibling\n"]);
        $this->git($first, ['config', 'core.worktree', $sibling]);

        $outer = $this->repo('outer', ['file.txt' => "needle outer\n"]);
        $inner = $this->repo('outer/inner', ['file.txt' => "needle inner\n"]);
        $this->git($inner, ['config', 'core.worktree', $outer]);

        foreach ([$first, $inner] as $selected) {
            try {
                (new RepositoryPathSearch())->searchContent([$selected], 'needle');
                self::fail('A different repository must not be searched.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('repository', $error->getMessage());
            }
        }
    }

    public function testInheritedGitTracesDoNotLogPrivateSearchTerms(): void
    {
        $repo = $this->repo('trace', ['file.txt' => "sensitive-search-term\n"]);
        $trace = $this->base.'/trace.log';
        $trace2 = $this->base.'/trace2.json';
        $oldTrace = getenv('GIT_TRACE');
        $oldTrace2 = getenv('GIT_TRACE2_EVENT');
        try {
            putenv('GIT_TRACE='.$trace);
            putenv('GIT_TRACE2_EVENT='.$trace2);
            $result = (new RepositoryPathSearch())->searchContent([$repo], 'sensitive-search-term')[0];
            self::assertSame('sensitive-search-term', $result['results'][0]['text']);
            self::assertFileDoesNotExist($trace);
            self::assertFileDoesNotExist($trace2);
        } finally {
            $oldTrace === false ? putenv('GIT_TRACE') : putenv('GIT_TRACE='.$oldTrace);
            $oldTrace2 === false ? putenv('GIT_TRACE2_EVENT') : putenv('GIT_TRACE2_EVENT='.$oldTrace2);
        }
    }

    public function testContentCommandWarnsAboutLocalSensitiveData(): void
    {
        $repo = $this->repo('content', ['src/Example.php' => "some needle here\n"]);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/artisan', 'kernel:context', 'needle', '--repo='.$repo, '--content', '--json'], dirname(__DIR__, 2), null, null, 15);
        $process->mustRun();
        $json = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('may be sensitive', $json['scope']);
        self::assertStringContainsString('nothing sent to a model', $json['scope']);
        self::assertSame('some needle here', $json['repositories'][0]['results'][0]['text']);
    }

    public function testContentSearchFailsClosedWhenGitOutputExceedsLimit(): void
    {
        $repo = $this->repo('huge-line', ['huge.txt' => 'needle'.str_repeat('x', 9 * 1024 * 1024)."\n"]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no partial results');
        (new RepositoryPathSearch())->searchContent([$repo], 'needle');
    }

    /** @param array<string, string> $files */
    private function repo(string $name, array $files): string
    {
        $dir = $this->base.'/'.$name;
        mkdir($dir, 0700, true);
        $this->git($dir, ['init', '-q']);
        foreach ($files as $path => $content) {
            $parent = dirname($dir.'/'.$path);
            if (!is_dir($parent)) mkdir($parent, 0700, true);
            file_put_contents($dir.'/'.$path, $content);
        }
        $this->git($dir, ['add', '--all']);
        $this->git($dir, ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'initial']);
        return $dir;
    }

    /** @param list<string> $args */
    private function git(string $dir, array $args): string
    {
        $process = new Process(['git', '-C', $dir, ...$args]);
        $process->mustRun();
        return trim($process->getOutput());
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->remove($path.'/'.$entry);
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) unlink($path);
    }
}
