<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_plugin_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->text('progress_message')->nullable()->after('progress');
            $table->json('run_state')->nullable()->after('result');
            $table->timestamp('last_heartbeat_at')->nullable()->after('summary');
            $table->boolean('cancel_requested')->default(false)->after('last_heartbeat_at');
            $table->timestamp('cancel_requested_at')->nullable()->after('cancel_requested');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_requested_at');
            $table->timestamp('stale_at')->nullable()->after('cancelled_at');

            $table->index(['status', 'last_heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::table('extension_plugin_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_heartbeat_at']);
            $table->dropColumn([
                'progress',
                'progress_message',
                'run_state',
                'last_heartbeat_at',
                'cancel_requested',
                'cancel_requested_at',
                'cancelled_at',
                'stale_at',
            ]);
        });
    }
};
