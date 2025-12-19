<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // 1. Permisos primero
            PermissionSeeder::class,

            // 2. Roles y administradores
            RolSeeder::class,
            RolePermissionSeeder::class,
            AdministradorSeeder::class,
            
            // 2. Planes y características
            PlanSeeder::class,
            PlanFeatureSeeder::class,
            
            // 3. Clientes
            ClientSeeder::class,
            
            // 4. Relación clientes-planes
            ClientPlanSeeder::class,
            
            // 5. Sistema de billeteras
            WalletSeeder::class,
            TransactionSeeder::class,
            
            // 6. Tickets de soporte
            SupportSeeder::class,
        ]);

        $this->command->info('¡Base de datos poblada completamente!');
    }
}