<?php

namespace Database\Seeders;

use App\Models\Administrador;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdministradorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $super = Rol::where('slug', 'super_admin')->first();
        $factu = Rol::where('slug', 'facturacion')->first();
        $tec = Rol::where('slug', 'tecnico')->first();

        // 5. Crear 3 administradores asignados a los roles
        Administrador::factory()->create(['fk_rol_id' => $super?->id_rol]);
        Administrador::factory()->create(['fk_rol_id' => $factu?->id_rol]);
        Administrador::factory()->create(['fk_rol_id' => $tec?->id_rol]);

        $this->command->info('Administradores creados: ' . count(Administrador::all()));
    }
}