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

        $this->command->info('Creando transacciones para ' . $wallets->count() . ' billeteras...');

        $totalTransactions = 0;

        foreach ($wallets as $wallet) {
            // Crear entre 3 y 8 transacciones por billetera
            $numberOfTransactions = rand(3, 8);
            
            for ($i = 0; $i < $numberOfTransactions; $i++) {
                $this->createTransaction($wallet, $i);
                $totalTransactions++;
            }

            $this->command->info("{$numberOfTransactions} transacciones creadas para: {$wallet->client->full_name}");
        }

        $this->command->info("¡Se crearon {$totalTransactions} transacciones en total!");
    }

    /**
     * Crear una transacción para una billetera
     */
    private function createTransaction(Wallet $wallet, int $index): void
    {
        $type = $this->getTransactionType($wallet->balance, $index);
        $amount = $this->generateAmount($type);
        $description = $this->generateDescription($type, $amount);

        Transaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'reference' => $this->generateReference($type),
            'status' => 'completed',
            'metadata' => $this->generateMetadata($type),
            'created_at' => $this->generateDate($index),
            'updated_at' => now(),
        ]);

        // Actualizar el balance de la billetera según la transacción
        $this->updateWalletBalance($wallet, $type, $amount);
    }

    /**
     * Determinar el tipo de transacción
     */
    private function getTransactionType(float $currentBalance, int $index): string
    {
        // Para las primeras transacciones, favorecer depósitos
        if ($index < 2) {
            return 'deposit';
        }

        // Si el saldo es muy bajo, favorecer depósitos
        if ($currentBalance < 10) {
            $weights = ['deposit' => 80, 'payment' => 15, 'withdrawal' => 5];
        } else {
            $weights = ['deposit' => 40, 'payment' => 35, 'withdrawal' => 20, 'refund' => 5];
        }

        $random = rand(1, 100);
        $cumulative = 0;

        foreach ($weights as $type => $weight) {
            $cumulative += $weight;
            if ($random <= $cumulative) {
                return $type;
            }
        }

        return 'deposit';
    }

    /**
     * Generar monto según el tipo de transacción
     */
    private function generateAmount(string $type): float
    {
        return match($type) {
            'deposit' => rand(10, 200) + (rand(0, 99) / 100), // $10 - $200
            'withdrawal' => rand(5, 100) + (rand(0, 99) / 100), // $5 - $100
            'payment' => rand(8, 80) + (rand(0, 99) / 100), // $8 - $80
            'refund' => rand(15, 120) + (rand(0, 99) / 100), // $15 - $120
            default => rand(5, 50) + (rand(0, 99) / 100),
        };
    }

    /**
     * Generar descripción según el tipo
     */
    private function generateDescription(string $type, float $amount): string
    {
        $descriptions = [
            'deposit' => [
                "Recarga de saldo",
                "Depósito desde tarjeta",
                "Transferencia recibida",
                "Recarga manual",
                "Pago recibido"
            ],
            'withdrawal' => [
                "Retiro de fondos",
                "Transferencia enviada",
                "Retiro a cuenta bancaria",
                "Reembolso solicitado"
            ],
            'payment' => [
                "Pago de servicio mensual",
                "Pago automático - Suscripción",
                "Factura de servicios",
                "Pago de cuota",
                "Renovación de servicio"
            ],
            'refund' => [
                "Reembolso de pago",
                "Devolución de fondos",
                "Corrección de cargo",
                "Reembolso por servicio no prestado"
            ]
        ];

        $options = $descriptions[$type] ?? ['Transacción general'];
        return $options[array_rand($options)] . " - $" . number_format($amount, 2);
    }

    /**
     * Generar referencia única
     */
    private function generateReference(string $type): string
    {
        $prefix = match($type) {
            'deposit' => 'DEP',
            'withdrawal' => 'WITH',
            'payment' => 'PAY',
            'refund' => 'REF',
            default => 'TXN'
        };

        return $prefix . '_' . strtoupper(uniqid());
    }

    /**
     * Generar metadata adicional
     */
    private function generateMetadata(string $type): ?array
    {
        $metadata = [
            'ip_address' => '192.168.' . rand(1, 255) . '.' . rand(1, 255),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'device' => ['Windows', 'MacOS', 'Linux', 'Android', 'iOS'][rand(0, 4)]
        ];

        // Metadata específica por tipo
        switch ($type) {
            case 'payment':
                $metadata['service_type'] = ['Internet', 'Hosting', 'Software', 'Membresía'][rand(0, 3)];
                $metadata['billing_cycle'] = ['monthly', 'quarterly', 'yearly'][rand(0, 2)];
                break;
            case 'deposit':
                $metadata['payment_method'] = ['credit_card', 'debit_card', 'bank_transfer', 'cash'][rand(0, 3)];
                break;
            case 'refund':
                $metadata['reason'] = ['duplicate_charge', 'service_issue', 'customer_request'][rand(0, 2)];
                break;
        }

        return $metadata;
    }

    /**
     * Generar fecha realista (transacciones en los últimos 60 días)
     */
    private function generateDate(int $index): string
    {
        $daysAgo = rand(0, 60); // Últimos 60 días
        $hours = rand(0, 23);
        $minutes = rand(0, 59);
        
        return now()->subDays($daysAgo)->subHours($hours)->subMinutes($minutes);
    }

    /**
     * Actualizar balance de la billetera
     */
    private function updateWalletBalance(Wallet $wallet, string $type, float $amount): void
    {
        switch ($type) {
            case 'deposit':
            case 'refund':
                $wallet->balance += $amount;
                break;
            case 'withdrawal':
            case 'payment':
                $wallet->balance -= $amount;
                // Asegurar que el balance no sea negativo
                if ($wallet->balance < 0) {
                    $wallet->balance = 0;
                }
                break;
        }

        $wallet->save();
    }
}