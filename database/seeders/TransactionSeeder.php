<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener todas las billeteras existentes
        $wallets = Wallet::with('client')->get();

        if ($wallets->isEmpty()) {
            $this->command->warn('No hay billeteras en la base de datos. Ejecuta primero WalletSeeder.');
            return;
        }

        $this->command->info('Generando historial de transacciones lógico para ' . $wallets->count() . ' billeteras...');

        $totalTransactions = 0;

        foreach ($wallets as $wallet) {
            // Resetear balance a 0 para reconstruirlo desde el historial
            $currentBalance = 0.00;
            
            // Determinar fecha de inicio (ej. hace 3-6 meses)
            $transactionDate = now()->subDays(rand(90, 180));
            
            // Número de transacciones a generar
            $numberOfTransactions = rand(5, 15);
            
            for ($i = 0; $i < $numberOfTransactions; $i++) {
                // Avanzar el tiempo aleatoriamente (entre 2 y 15 días)
                $transactionDate->addDays(rand(2, 15))->addHours(rand(1, 12));
                
                if ($transactionDate > now()) {
                    break; // No crear transacciones en el futuro
                }

                // Decidir tipo de transacción
                // Si el balance es bajo, forzar depósito con alta probabilidad
                if ($currentBalance < 20) {
                    $type = 'deposit';
                } else {
                    // Distribución normal
                    $rand = rand(1, 100);
                    if ($rand <= 30) $type = 'deposit';
                    elseif ($rand <= 80) $type = 'payment'; // Pago de servicios
                    elseif ($rand <= 95) $type = 'withdrawal';
                    else $type = 'refund';
                }

                // Generar monto
                $amount = $this->generateAmount($type);

                // Validar fondos para salidas de dinero
                if (in_array($type, ['payment', 'withdrawal'])) {
                    if ($currentBalance < $amount) {
                        // Si no hay fondos, crear un depósito previo
                        $depositAmount = $amount + rand(10, 50); // Cubrir el gasto y sobrar algo
                        $this->createTransaction($wallet, 'deposit', $depositAmount, $transactionDate->copy()->subMinutes(30));
                        $currentBalance += $depositAmount;
                        $totalTransactions++;
                    }
                }

                // Crear la transacción principal
                $this->createTransaction($wallet, $type, $amount, $transactionDate);
                
                // Actualizar balance temporal
                if (in_array($type, ['deposit', 'refund'])) {
                    $currentBalance += $amount;
                } else {
                    $currentBalance -= $amount;
                }
                
                $totalTransactions++;
            }

            // Actualizar el balance final de la billetera
            $wallet->balance = $currentBalance;
            $wallet->save();
        }

        $this->command->info("¡Se crearon {$totalTransactions} transacciones lógicas y se actualizaron los balances!");
    }

    /**
     * Crear una transacción individual
     */
    private function createTransaction(Wallet $wallet, string $type, float $amount, \DateTime $date): void
    {
        Transaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $amount,
            'description' => $this->generateDescription($type, $amount),
            'reference' => $this->generateReference($type),
            'status' => 'completed',
            'metadata' => $this->generateMetadata($type),
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    /**
     * Generar monto según el tipo de transacción
     */
    private function generateAmount(string $type): float
    {
        return match($type) {
            'deposit' => rand(20, 200) + (rand(0, 99) / 100),
            'withdrawal' => rand(10, 100) + (rand(0, 99) / 100),
            'payment' => rand(15, 60) + (rand(0, 99) / 100), // Precios típicos de planes
            'refund' => rand(5, 50) + (rand(0, 99) / 100),
            default => rand(10, 50),
        };
    }

    /**
     * Generar descripción según el tipo
     */
    private function generateDescription(string $type, float $amount): string
    {
        $descriptions = [
            'deposit' => [
                "Recarga de saldo", "Depósito bancario", "Transferencia recibida", 
                "Carga via Stripe", "Depósito en efectivo"
            ],
            'withdrawal' => [
                "Retiro de fondos", "Transferencia a cuenta propia"
            ],
            'payment' => [
                "Pago de mensualidad internet", "Renovación de servicio", 
                "Pago de factura #".rand(1000,9999), "Compra de IP estática"
            ],
            'refund' => [
                "Reembolso por ajuste", "Devolución de saldo a favor"
            ]
        ];

        $options = $descriptions[$type] ?? ['Transacción general'];
        return $options[array_rand($options)];
    }

    /**
     * Generar referencia única
     */
    private function generateReference(string $type): string
    {
        $prefix = match($type) {
            'deposit' => 'DEP',
            'withdrawal' => 'WTH',
            'payment' => 'PAY',
            'refund' => 'REF',
            default => 'TXN'
        };

        return $prefix . '-' . strtoupper(uniqid());
    }

    /**
     * Generar metadata adicional
     */
    private function generateMetadata(string $type): ?array
    {
        $metadata = [
            'ip' => '192.168.' . rand(1, 255) . '.' . rand(1, 255),
            'channel' => ['web', 'app', 'admin'][rand(0, 2)]
        ];

        if ($type === 'payment') {
            $metadata['invoice_id'] = rand(10000, 99999);
        }

        return $metadata;
    }
}