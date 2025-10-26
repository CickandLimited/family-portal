<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('friendly_name', 200)->nullable();
            $table->foreignId('linked_user_id')->nullable()->constrained('user')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device');
    }
};
