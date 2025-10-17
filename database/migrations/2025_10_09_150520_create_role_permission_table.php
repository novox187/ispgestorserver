<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->unsignedBigInteger('fk_rol_id');
            $table->unsignedBigInteger('fk_permission_id');

            $table->primary(['fk_rol_id', 'fk_permission_id']);

            $table->foreign('fk_rol_id')->references('id_rol')->on('roles')->cascadeOnDelete();
            $table->foreign('fk_permission_id')->references('id_permission')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
