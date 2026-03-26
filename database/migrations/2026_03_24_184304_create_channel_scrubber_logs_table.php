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
        Schema::disableForeignKeyConstraints();

        Schema::create('channel_scrubber_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_scrubber_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->enum('status', ['processing', 'completed', 'cancelled', 'failed'])->default('processing');
            $table->unsignedBigInteger('channel_count')->default(0);
            $table->unsignedBigInteger('dead_count')->default(0);
            $table->unsignedBigInteger('disabled_count')->default(0);
            $table->float('runtime')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_scrubber_logs');
    }
};
