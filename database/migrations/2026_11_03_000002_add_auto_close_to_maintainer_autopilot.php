<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('maintainer_autopilot_settings', function (Blueprint $table): void {
            $table->boolean('auto_close')->default(false);
        });
        Schema::create('autopilot_close_actions', function (Blueprint $table): void {
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
        Schema::dropIfExists('autopilot_close_actions');
        Schema::table('maintainer_autopilot_settings', function (Blueprint $table): void {
            $table->dropColumn('auto_close');
        });
    }
};
