<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workbench_tasks', function (Blueprint $table): void {
            $table->binary('image_bytes')->nullable();
            $table->string('image_mime', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workbench_tasks', function (Blueprint $table): void {
            $table->dropColumn(['image_bytes', 'image_mime']);
        });
    }
};
