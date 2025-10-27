<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained('plan')->cascadeOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained('subtask')->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('thumb_path', 500);
            $table->string('uploaded_by_device_id', 36);
            $table->foreign('uploaded_by_device_id')->references('id')->on('device')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('user')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment');
    }
};
