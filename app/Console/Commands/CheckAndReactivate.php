<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAutoReactivation;
use App\Models\Client;
use Illuminate\Console\Command;

class CheckAndReactivate extends Command
{
    protected $signature = 'billing:reactivate
                                {--client-id= : Procesar solo un cliente específico}
                                {--dry-run    : Mostrar candidatos sin despachar jobs}';

    protected $description = 'Busca clientes suspendidos con saldo suficiente y los reactiva automáticamente';

    public function handle(): int
    {
        $queue = config('billing.queue.reactivations');

        $query = Client::whereIn('service_status', ['suspended', 'SUSPENDED', 'SUSPENDIDO'])
            ->with(['wallet', 'invoices']);

        if ($clientId = $this->option('client-id')) {
            $query->where('id', $clientId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->info('No hay clientes suspendidos para revisar.');
            return Command::SUCCESS;
        }

        $this->info("Encontrados {$candidates->count()} cliente(s) suspendido(s).");

        $dispatched = 0;

        foreach ($candidates as $client) {
            if ($this->option('dry-run')) {
                $balance = $client->wallet?->balance ?? 0;
                $this->line("  [DRY-RUN] Cliente ID {$client->id} — saldo: {$balance}");
                continue;
            }

            ProcessAutoReactivation::dispatch($client)->onQueue($queue);
            $dispatched++;
        }

        if (!$this->option('dry-run')) {
            $this->info("Jobs de reactivación despachados: {$dispatched}");
        }

        return Command::SUCCESS;
    }
}
