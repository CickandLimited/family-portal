<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_day', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plan')->cascadeOnDelete();
            $table->unsignedInteger('day_index');
            $table->string('title', 200);
            $table->boolean('locked')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['plan_id', 'day_index'], 'uq_plan_day_plan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_day');
    }
};
