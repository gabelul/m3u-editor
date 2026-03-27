<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_install_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_id')->nullable()->index();
            $table->string('plugin_name')->nullable();
            $table->string('plugin_version')->nullable();
            $table->string('api_version')->nullable();
            $table->string('source_type');
            $table->text('source_path')->nullable();
            $table->string('archive_filename')->nullable();
            $table->text('archive_path')->nullable();
            $table->text('staging_path')->nullable();
            $table->text('extracted_path')->nullable();
            $table->text('installed_path')->nullable();
            $table->string('status')->default('staged');
            $table->string('validation_status')->default('pending');
            $table->json('validation_errors')->nullable();
            $table->string('scan_status')->default('pending');
            $table->text('scan_summary')->nullable();
            $table->json('scan_details')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('hooks')->nullable();
            $table->json('permissions')->nullable();
            $table->json('schema_definition')->nullable();
            $table->json('data_ownership')->nullable();
            $table->json('integrity_hashes')->nullable();
            $table->json('manifest_snapshot')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('extension_plugin_id')->nullable()->constrained('extension_plugins')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scan_status'], 'plugin_install_reviews_status_scan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_install_reviews');
    }
};
