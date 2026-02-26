<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('table_name');
            $table->string('file_name');
            $table->string('status')->default('pending'); // pending, success, failed, rolled_back
            $table->json('summary')->nullable(); // { "total": 10, "success": 9, "failed": 1 }
            $table->json('errors')->nullable(); // [{ "row": 2, "column": "email", "error": "Invalid format" }]
            $table->json('created_ids')->nullable(); // [1, 2, 3] - IDs of inserted records for rollback
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_histories');
    }
};
