<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtask_submission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subtask_id')->constrained('subtask')->cascadeOnDelete();
            $table->string('submitted_by_device_id', 36);
            $table->foreign('submitted_by_device_id')->references('id')->on('device')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('user')->nullOnDelete();
            $table->string('photo_path', 500)->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtask_submission');
    }
};
