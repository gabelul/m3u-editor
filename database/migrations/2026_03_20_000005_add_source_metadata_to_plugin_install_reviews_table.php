<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_install_reviews', function (Blueprint $table) {
            $table->text('source_origin')->nullable()->after('source_path');
            $table->json('source_metadata')->nullable()->after('source_origin');
            $table->string('expected_archive_sha256', 64)->nullable()->after('archive_path');
            $table->string('archive_sha256', 64)->nullable()->after('expected_archive_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('plugin_install_reviews', function (Blueprint $table) {
            $table->dropColumn([
                'source_origin',
                'source_metadata',
                'expected_archive_sha256',
                'archive_sha256',
            ]);
        });
    }
};
