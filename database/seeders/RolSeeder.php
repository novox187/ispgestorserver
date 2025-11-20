<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['nombre' => 'Super Admin', 'slug' => 'super_admin', 'descripcion' => 'Acceso total al sistema'],
            ['nombre' => 'Facturación', 'slug' => 'facturacion', 'descripcion' => 'Gestión de cobros y facturas'],
            ['nombre' => 'Técnico', 'slug' => 'tecnico', 'descripcion' => 'Operaciones técnicas y soporte'],
        ];
        
        foreach ($roles as $r) {
            Rol::firstOrCreate(['slug' => $r['slug']], $r);
        }

        $this->command->info('Roles creados: ' . count($roles));
    }
}