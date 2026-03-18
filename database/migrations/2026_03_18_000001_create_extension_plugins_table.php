<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_id')->unique();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('api_version')->nullable();
            $table->text('description')->nullable();
            $table->string('entrypoint')->nullable();
            $table->string('class_name')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('hooks')->nullable();
            $table->json('actions')->nullable();
            $table->json('settings_schema')->nullable();
            $table->json('settings')->nullable();
            $table->string('source_type')->default('local');
            $table->string('path')->nullable()->unique();
            $table->boolean('available')->default(true);
            $table->boolean('enabled')->default(false);
            $table->string('validation_status')->default('pending');
            $table->json('validation_errors')->nullable();
            $table->timestamp('last_discovered_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_plugins');
    }
};
