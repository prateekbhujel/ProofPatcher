<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('maintainer_autopilot_settings', function (Blueprint $table): void {
            $table->timestamp('auto_merge_armed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintainer_autopilot_settings', function (Blueprint $table): void {
            $table->dropColumn('auto_merge_armed_at');
        });
    }
};
