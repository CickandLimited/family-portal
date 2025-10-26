<?php

use App\Enums\ApprovalAction;
use App\Enums\ApprovalMood;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subtask_id')->constrained('subtask')->cascadeOnDelete();
            $table->enum('action', array_map(static fn (ApprovalAction $action) => $action->value, ApprovalAction::cases()))
                ->default(ApprovalAction::APPROVE->value);
            $table->enum('mood', array_map(static fn (ApprovalMood $mood) => $mood->value, ApprovalMood::cases()))
                ->default(ApprovalMood::NEUTRAL->value);
            $table->string('reason', 500)->nullable();
            $table->string('acted_by_device_id', 36);
            $table->foreign('acted_by_device_id')->references('id')->on('device')->cascadeOnDelete();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('user')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval');
    }
};
