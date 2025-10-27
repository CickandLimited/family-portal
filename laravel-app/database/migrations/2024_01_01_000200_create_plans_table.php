<?php

use App\Enums\PlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->foreignId('assignee_user_id')->constrained('user')->cascadeOnDelete();
            $table->enum('status', array_map(static fn (PlanStatus $status) => $status->value, PlanStatus::cases()))
                ->default(PlanStatus::IN_PROGRESS->value);
            $table->foreignId('created_by_user_id')->nullable()->constrained('user')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unsignedInteger('total_xp')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan');
    }
};
