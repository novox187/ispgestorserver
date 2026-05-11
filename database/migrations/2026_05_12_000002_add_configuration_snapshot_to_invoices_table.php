<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Immutable snapshot of system_settings at the moment the invoice was issued.
            // MUST NOT be updated after creation — legal immutability requirement.
            $table->json('configuration_snapshot')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('configuration_snapshot');
        });
    }
};
