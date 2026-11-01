<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automatic_inline_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('repository');
            $table->boolean('enabled')->default(false);
            $table->primary(['user_id', 'repository']);
        });
        Schema::create('automatic_inline_attempts', function (Blueprint $table): void {
            $table->string('revision_key', 64)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('repository');
            $table->unsignedBigInteger('pull_number');
            $table->string('status');
            $table->unsignedBigInteger('comment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_inline_attempts');
        Schema::dropIfExists('automatic_inline_settings');
    }
};
