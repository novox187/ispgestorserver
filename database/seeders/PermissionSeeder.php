<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            ['nombre' => 'Crear Usuario', 'slug' => 'crear_usuario', 'descripcion' => 'Permite crear nuevos usuarios'],
            ['nombre' => 'Editar Usuario', 'slug' => 'editar_usuario', 'descripcion' => 'Permite editar información de usuarios'],
            ['nombre' => 'Eliminar Usuario', 'slug' => 'eliminar_usuario', 'descripcion' => 'Permite eliminar usuarios'],
            ['nombre' => 'Ver Usuarios', 'slug' => 'ver_usuarios', 'descripcion' => 'Permite ver la lista de usuarios'],
            ['nombre' => 'Crear Plan', 'slug' => 'crear_plan', 'descripcion' => 'Permite crear nuevos planes'],
            ['nombre' => 'Editar Plan', 'slug' => 'editar_plan', 'descripcion' => 'Permite editar planes existentes'],
            ['nombre' => 'Eliminar Plan', 'slug' => 'eliminar_plan', 'descripcion' => 'Permite eliminar planes'],
            ['nombre' => 'Ver Planes', 'slug' => 'ver_planes', 'descripcion' => 'Permite ver la lista de planes'],
            ['nombre' => 'Crear Cliente', 'slug' => 'crear_cliente', 'descripcion' => 'Permite crear nuevos clientes'],
            ['nombre' => 'Editar Cliente', 'slug' => 'editar_cliente', 'descripcion' => 'Permite editar información de clientes'],
            ['nombre' => 'Eliminar Cliente', 'slug' => 'eliminar_cliente', 'descripcion' => 'Permite eliminar clientes'],
            ['nombre' => 'Ver Clientes', 'slug' => 'ver_clientes', 'descripcion' => 'Permite ver la lista de clientes'],
            ['nombre' => 'Crear Factura', 'slug' => 'crear_factura', 'descripcion' => 'Permite crear nuevas facturas'],
            ['nombre' => 'Editar Factura', 'slug' => 'editar_factura', 'descripcion' => 'Permite editar facturas'],
            ['nombre' => 'Eliminar Factura', 'slug' => 'eliminar_factura', 'descripcion' => 'Permite eliminar facturas'],
            ['nombre' => 'Ver Facturas', 'slug' => 'ver_facturas', 'descripcion' => 'Permite ver la lista de facturas'],
            ['nombre' => 'Acceso Total', 'slug' => 'acceso_total', 'descripcion' => 'Acceso total al sistema'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }

        $this->command->info('Permisos creados: ' . count($permissions));
    }
}