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
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->string('url_type')->default('proxy')->after('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn('url_type');
        });
    }
};
