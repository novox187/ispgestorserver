<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;
use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Soporte;
use App\Models\Administrador;
use App\Models\Rol;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Crear 5 planes diferentes con datos específicos (únicos) usando sequence.
        // Esto debe ir primero ya que los Servicios dependen de los Planes.
        Plan::factory()
            ->count(5)
            ->sequence(
                ['velocidad_descarga_mbps' => 50, 'velocidad_subida_mbps' => 10, 'tarifa_mensual' => 30.50],
                ['velocidad_descarga_mbps' => 100, 'velocidad_subida_mbps' => 20, 'tarifa_mensual' => 55.90],
                ['velocidad_descarga_mbps' => 200, 'velocidad_subida_mbps' => 40, 'tarifa_mensual' => 75.00],
                ['velocidad_descarga_mbps' => 500, 'velocidad_subida_mbps' => 100, 'tarifa_mensual' => 99.99],
                ['velocidad_descarga_mbps' => 1000, 'velocidad_subida_mbps' => 200, 'tarifa_mensual' => 150.00],
            )
            ->create(); 
        
        // 2. Crear 50 clientes. 
        // Cada cliente creado (Cliente::factory()) tendrá automáticamente un Servicio asociado (.has(Servicio::factory())).
        Cliente::factory()
            ->count(50)
            ->has(Servicio::factory()) 
            ->create();
        
        // 3. Crear 150 tickets de soporte.
        // Se asocian a clientes existentes creados en el paso anterior.
        Soporte::factory()->count(150)->create();

        // 4. Crear roles base del sistema RBAC
        $roles = [
            ['nombre' => 'Super Admin', 'slug' => 'super_admin', 'descripcion' => 'Acceso total al sistema'],
            ['nombre' => 'Facturación', 'slug' => 'facturacion', 'descripcion' => 'Gestión de cobros y facturas'],
            ['nombre' => 'Técnico', 'slug' => 'tecnico', 'descripcion' => 'Operaciones técnicas y soporte'],
        ];
        foreach ($roles as $r) {
            Rol::firstOrCreate(['slug' => $r['slug']], $r);
        }

        $super = Rol::where('slug', 'super_admin')->first();
        $factu = Rol::where('slug', 'facturacion')->first();
        $tec = Rol::where('slug', 'tecnico')->first();

        // 5. Crear 3 administradores asignados a los roles
        Administrador::factory()->create(['fk_rol_id' => $super?->id_rol]);
        Administrador::factory()->create(['fk_rol_id' => $factu?->id_rol]);
        Administrador::factory()->create(['fk_rol_id' => $tec?->id_rol]);

        $this->command->info('¡Base de datos poblada con éxito con 5 Planes, 50 Clientes, 150 Tickets, Roles base y 3 Administradores!');
    }
}