<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_routers', function (Blueprint $table) {
            $table->id();

            $table->string('name')
                ->comment('Nombre descriptivo del router (ej: "Router Principal")');
            $table->string('host')
                ->comment('IP o hostname del router MikroTik');
            $table->unsignedSmallInteger('port')->default(8728)
                ->comment('Puerto API RouterOS (8728 plain, 8729 SSL)');
            $table->string('username')
                ->comment('Usuario de la API RouterOS');
            $table->string('password')
                ->comment('Contraseña cifrada de la API RouterOS');
            $table->string('description')->nullable()
                ->comment('Notas adicionales sobre el equipo');
            $table->boolean('is_active')->default(true)
                ->comment('Indica si el router está disponible para operaciones');
            $table->timestamp('last_loaded_at')->nullable()
                ->comment('Última vez que se leyó el snapshot del router');
            $table->timestamp('last_applied_at')->nullable()
                ->comment('Última vez que se aplicaron cambios al router');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_routers');
    }
};
