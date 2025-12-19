<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WalletSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener todos los clientes existentes
        $clients = Client::all();

        if ($clients->isEmpty()) {
            $this->command->warn('No hay clientes en la base de datos. Creando clientes de ejemplo...');
            
            // Crear algunos clientes de ejemplo si no existen
            $clients = Client::factory()->count(10)->create();
        }

        $this->command->info('Creando billeteras para ' . $clients->count() . ' clientes...');

        foreach ($clients as $client) {
            // Verificar si el cliente ya tiene una billetera
            if (!$client->wallet) {
                Wallet::create([
                    'client_id' => $client->id,
                    'balance' => $this->generateInitialBalance(),
                    'currency' => 'USD',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->command->info("Billetera creada para cliente: {$client->full_name}");
            }
        }

        $this->command->info('¡Todas las billeteras han sido creadas!');
    }

    /**
     * Generar un saldo inicial aleatorio
     */
    private function generateInitialBalance(): float
    {
        return 0.00; // El balance se calculará basado en las transacciones en TransactionSeeder
    }
}