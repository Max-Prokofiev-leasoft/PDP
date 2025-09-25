<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdp_skill_criterion_progress', function (Blueprint $table) {
            $table->boolean('approved')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('pdp_skill_criterion_progress', function (Blueprint $table) {
            $table->dropColumn('approved');
        });
    }
};
