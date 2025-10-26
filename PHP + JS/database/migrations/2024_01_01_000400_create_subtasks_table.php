<?php

use App\Enums\SubtaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtask', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_day_id')->constrained('plan_day')->cascadeOnDelete();
            $table->unsignedInteger('order_index');
            $table->string('text', 500);
            $table->unsignedInteger('xp_value')->default(10);
            $table->enum('status', array_map(static fn (SubtaskStatus $status) => $status->value, SubtaskStatus::cases()))
                ->default(SubtaskStatus::PENDING->value);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['plan_day_id', 'order_index'], 'uq_subtask_plan_day_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtask');
    }
};
