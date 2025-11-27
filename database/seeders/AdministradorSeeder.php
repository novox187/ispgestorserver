<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdministradorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $super = Role::where('slug', 'super_admin')->first();
        $factu = Role::where('slug', 'facturacion')->first();
        $tec = Role::where('slug', 'tecnico')->first();

        // 5. Crear 3 administradores asignados a los roles
        Employee::factory()->create(['role_id' => $super?->id]);
        Employee::factory()->create(['role_id' => $factu?->id]);
        Employee::factory()->create(['role_id' => $tec?->id]);

        $this->command->info('Administradores creados: ' . count(Employee::all()));
    }
}