<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'rating')) {
                $table->tinyInteger('rating')->unsigned()->nullable()->after('status');
            }
            if (!Schema::hasColumn('tickets', 'review')) {
                $table->text('review')->nullable()->after('rating');
            }
        });
    }
    public function down(): void {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['rating', 'review']);
        });
    }
};
