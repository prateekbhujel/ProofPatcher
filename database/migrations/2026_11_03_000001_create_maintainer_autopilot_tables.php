<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintainer_autopilot_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('repository')->unique();
            $table->boolean('fix_issues')->default(false);
            $table->boolean('auto_ready')->default(false);
            $table->boolean('auto_merge')->default(false);
            $table->timestamps();
        });
        Schema::create('autopilot_issue_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('repository');
            $table->unsignedBigInteger('issue_number');
            $table->string('issue_revision');
            $table->string('action');
            $table->text('detail')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
            $table->unique(['repository', 'issue_number', 'issue_revision']);
            $table->index(['repository', 'issue_number']);
        });
        Schema::create('autopilot_merge_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('repository');
            $table->unsignedBigInteger('pull_number');
            $table->string('head_sha', 40);
            $table->string('outcome');
            $table->text('detail')->nullable();
            $table->timestamps();
            $table->unique(['repository', 'pull_number', 'head_sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopilot_merge_actions');
        Schema::dropIfExists('autopilot_issue_actions');
        Schema::dropIfExists('maintainer_autopilot_settings');
    }
};
