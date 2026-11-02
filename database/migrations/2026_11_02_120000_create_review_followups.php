<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_followups', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('repository');
            $table->unsignedBigInteger('pull_number');
            $table->unsignedBigInteger('comment_id');
            $table->unsignedBigInteger('bot_comment_id');
            $table->timestamp('detected_at')->useCurrent();
            $table->primary(['user_id', 'repository', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_followups');
    }
};
