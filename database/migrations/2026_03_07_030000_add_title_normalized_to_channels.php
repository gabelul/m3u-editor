<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a normalized title column to channels for better EPG matching.
     *
     * The title_normalized column stores a cleaned version of the provider's
     * title, with country prefixes, quality suffixes, unicode junk, and
     * other provider-specific decorations stripped out. This enables more
     * accurate EPG matching and duplicate detection without modifying the
     * original provider title.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('title_normalized')->nullable()->after('title_custom');
            $table->index('title_normalized');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex(['title_normalized']);
            $table->dropColumn('title_normalized');
        });
    }
};
