<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->json('data_ownership')->nullable()->after('settings');
            $table->string('installation_status')->default('installed')->after('enabled');
            $table->string('last_cleanup_mode')->nullable()->after('installation_status');
            $table->timestamp('uninstalled_at')->nullable()->after('last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->dropColumn([
                'data_ownership',
                'installation_status',
                'last_cleanup_mode',
                'uninstalled_at',
            ]);
        });
    }
};
