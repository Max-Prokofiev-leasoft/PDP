<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pdps', function (Blueprint $table) {
            if (!Schema::hasColumn('pdps', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable()->after('status');
                $table->foreign('template_id')->references('id')->on('pdp_templates')->nullOnDelete();
            }
        });

        Schema::table('pdp_skills', function (Blueprint $table) {
            if (!Schema::hasColumn('pdp_skills', 'template_skill_key')) {
                $table->string('template_skill_key')->nullable()->after('order_column');
                $table->index('template_skill_key');
            }
            if (!Schema::hasColumn('pdp_skills', 'is_manual_override')) {
                $table->boolean('is_manual_override')->default(false)->after('template_skill_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdps', function (Blueprint $table) {
            if (Schema::hasColumn('pdps', 'template_id')) {
                $table->dropForeign(['template_id']);
                $table->dropColumn('template_id');
            }
        });

        Schema::table('pdp_skills', function (Blueprint $table) {
            if (Schema::hasColumn('pdp_skills', 'is_manual_override')) {
                $table->dropColumn('is_manual_override');
            }
            if (Schema::hasColumn('pdp_skills', 'template_skill_key')) {
                $table->dropIndex('pdp_skills_template_skill_key_index');
                $table->dropColumn('template_skill_key');
            }
        });
    }
};
