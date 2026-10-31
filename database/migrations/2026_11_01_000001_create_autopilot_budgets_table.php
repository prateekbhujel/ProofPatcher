<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('autopilot_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('max_attempts')->nullable();
            $table->unsignedBigInteger('max_reserved_micros')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopilot_budgets');
    }
};
