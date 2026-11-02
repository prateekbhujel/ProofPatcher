<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ProofPatch\Console\Commands\AutoReviewWorkerCommand;
use ProofPatch\Console\Commands\CheckReceiptCommand;
use ProofPatch\Console\Commands\KernelReviewsCommand;
use ProofPatch\Console\Commands\KernelTelemetryCommand;
use ProofPatch\Console\Commands\KernelMentionsCommand;
use ProofPatch\Console\Commands\InspectPullRequestCommand;
use ProofPatch\Console\Commands\RecordPullRequestSnapshotCommand;
use ProofPatch\Console\Commands\TriagePullRequestCommand;
use ProofPatch\Console\Commands\VerifyPatchCommand;
use ProofPatch\Http\Middleware\HandleInertiaRequests;
use ProofPatch\Http\Middleware\AuthorizeGithubTasks;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
    )
    ->withMiddleware(static function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
        $middleware->web(append: [
            AuthorizeGithubTasks::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withCommands([
        AutoReviewWorkerCommand::class,
        KernelReviewsCommand::class,
        KernelTelemetryCommand::class,
        KernelMentionsCommand::class,
        RecordPullRequestSnapshotCommand::class,
        InspectPullRequestCommand::class,
        TriagePullRequestCommand::class,
        VerifyPatchCommand::class,
        CheckReceiptCommand::class,
    ])
    ->withExceptions(static function (): void {})
    ->create();

return $app;
