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

        Schema::create('channel_scrubber_log_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_scrubber_log_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->text('url');
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_scrubber_log_channels');
    }
};
