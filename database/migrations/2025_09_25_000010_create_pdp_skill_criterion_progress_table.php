<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pdp_skill_criterion_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdp_skill_id')->constrained('pdp_skills')->onDelete('cascade');
            $table->unsignedInteger('criterion_index');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('note');
            $table->timestamps();

            $table->index(['pdp_skill_id', 'criterion_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdp_skill_criterion_progress');
    }
};
