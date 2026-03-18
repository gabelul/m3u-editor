<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_plugin_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_plugin_run_id')->constrained()->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_plugin_run_logs');
    }
};
