<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pdp_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdp_id')->constrained('pdps')->cascadeOnDelete();
            $table->string('skill');
            $table->text('description')->nullable();
            $table->text('criteria')->nullable();
            $table->enum('priority', ['Low','Medium','High'])->default('Medium');
            $table->string('eta')->nullable();
            $table->enum('status', ['Planned','In Progress','Done','Blocked'])->default('Planned');
            $table->unsignedInteger('order_column')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdp_skills');
    }
};
