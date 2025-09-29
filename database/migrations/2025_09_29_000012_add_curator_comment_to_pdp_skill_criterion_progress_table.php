<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdp_skill_criterion_progress', function (Blueprint $table) {
            $table->text('curator_comment')->nullable()->after('approved');
        });
    }

    public function down(): void
    {
        Schema::table('pdp_skill_criterion_progress', function (Blueprint $table) {
            $table->dropColumn('curator_comment');
        });
    }
};
