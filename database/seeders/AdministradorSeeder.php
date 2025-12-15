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
        Employee::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['nombre' => 'Admin', 'password' => 'password', 'telefono' => '000000000', 'role_id' => $super?->id]
        );
        Employee::firstOrCreate(
            ['email' => 'facturacion@example.com'],
            ['nombre' => 'Facturación', 'password' => 'password', 'telefono' => '000000001', 'role_id' => $factu?->id]
        );
        Employee::firstOrCreate(
            ['email' => 'tecnico@example.com'],
            ['nombre' => 'Técnico', 'password' => 'password', 'telefono' => '000000002', 'role_id' => $tec?->id]
        );

        $this->command->info('Administradores creados: ' . count(Employee::all()));
    }
}
