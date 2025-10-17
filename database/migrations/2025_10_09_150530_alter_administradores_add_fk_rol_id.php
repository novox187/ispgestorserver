<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administradores', function (Blueprint $table) {
            if (Schema::hasColumn('administradores', 'rol')) {
                $table->dropColumn('rol');
            }
            $table->unsignedBigInteger('fk_rol_id')->nullable()->after('password');
            $table->foreign('fk_rol_id')->references('id_rol')->on('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('administradores', function (Blueprint $table) {
            $table->dropForeign(['fk_rol_id']);
            $table->dropColumn('fk_rol_id');
            // Opcionalmente podrías restaurar la columna enum rol si fuera necesario
            // $table->enum('rol', ['super_admin', 'facturacion', 'tecnico'])->nullable();
        });
    }
};
