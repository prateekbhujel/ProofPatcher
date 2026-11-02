<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mention_review_attempts', static function (Blueprint $table): void {
            $table->id();
            $table->string('repository');
            $table->unsignedBigInteger('comment_id');
            $table->unsignedBigInteger('pull_number');
            $table->string('status');
            $table->timestamps();
            $table->unique(['repository', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mention_review_attempts');
    }
};
