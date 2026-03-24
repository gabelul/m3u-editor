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

        Schema::create('channel_scrubbers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->uuid('uuid')->nullable();
            $table->longText('errors')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->boolean('processing')->default(false);
            $table->float('progress')->default(0);
            $table->float('sync_time')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->boolean('include_vod')->default(false);
            $table->boolean('scan_all')->default(false);
            $table->enum('check_method', ['http', 'ffprobe'])->default('http');
            $table->boolean('recurring')->default(false);
            $table->unsignedBigInteger('channel_count')->default(0);
            $table->unsignedBigInteger('dead_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_scrubbers');
    }
};
