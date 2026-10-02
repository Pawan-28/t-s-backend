<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // JSON list of feature-permission keys (App\Support\Permissions). NULL = none granted.
            // ADMIN users always hold every permission and ignore this column. No DB default (MySQL JSON/TEXT cannot have one).
            $table->json('permissions')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
