<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoBillingService;

class ProcessAutoBilling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:process 
                            {--generate-invoices : Solo generar facturas}
                            {--process-payments : Solo procesar pagos}
                            {--client-id= : Procesar solo para un cliente específico}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Procesar facturación automática y pagos';

    /**
     * Execute the console command.
     */
    public function handle(AutoBillingService $billingService)
    {
        $this->info('🚀 Iniciando proceso de facturación automática...');
        $this->info('Fecha y hora: ' . now()->toDateTimeString());
        $this->line('');

        $startTime = microtime(true);

        try {
            if ($this->option('generate-invoices')) {
                // Solo generar facturas
                $this->info('📄 Generando facturas mensuales...');
                $invoices = $billingService->generateMonthlyInvoices();
                $this->info("✅ Facturas generadas: " . count($invoices));
                
                foreach ($invoices as $invoice) {
                    $this->line("   • {$invoice->invoice_number} - {$invoice->client->full_name} - $" . number_format($invoice->total_amount, 2));
                }

            } elseif ($this->option('process-payments')) {
                // Solo procesar pagos
                $this->info('💳 Procesando pagos automáticos...');
                $results = $billingService->processAutoPayments();
                
                $this->processPaymentResults($results);

            } else {
                // Proceso completo
                $this->info('📄 Generando facturas mensuales...');
                $invoices = $billingService->generateMonthlyInvoices();
                $this->info("✅ Facturas generadas: " . count($invoices));

                $this->info('💳 Procesando pagos automáticos...');
                $results = $billingService->processAutoPayments();
                
                $this->processPaymentResults($results);
            }

            // Mostrar resumen final
            $this->showFinalSummary($billingService);

        } catch (\Exception $e) {
            $this->error('❌ Error en el proceso de facturación: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $executionTime = round(microtime(true) - $startTime, 2);
        $this->info("\n⏱️  Tiempo de ejecución: {$executionTime} segundos");
        $this->info('🎉 Proceso de facturación completado exitosamente!');

        return Command::SUCCESS;
    }

    /**
     * Procesar y mostrar resultados de pagos
     */
    private function processPaymentResults(array $results)
    {
        $successful = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result['result']['success']) {
                $successful++;
                $this->info("   ✅ {$result['invoice_number']} - Pago exitoso");
            } else {
                $failed++;
                $this->error("   ❌ {$result['invoice_number']} - {$result['result']['error']}");
            }
        }

        $this->info("📊 Resumen de pagos:");
        $this->info("   ✅ Exitosos: {$successful}");
        $this->info("   ❌ Fallidos: {$failed}");
        $this->info("   📋 Total procesados: " . count($results));
    }

 /**
     * Mostrar resumen final ACTUALIZADO
     */
    private function showFinalSummary(AutoBillingService $billingService)
    {
        // Obtener el resumen MÁS RECIENTE después de los cambios
        $summary = $billingService->getBillingSummary();

        $this->info("\n📈 RESUMEN GENERAL ACTUALIZADO:");
        $this->info("   📄 Total facturas: {$summary['total_invoices']}");
        $this->info("   💰 Facturas pagadas: {$summary['paid_invoices']}");
        $this->info("   ⏳ Facturas pendientes: {$summary['pending_invoices']}");
        $this->info("   🚨 Facturas vencidas: {$summary['overdue_invoices']}");
        $this->info("   💵 Ingresos totales: $" . number_format($summary['total_revenue'], 2));
        $this->info("   📋 Monto pendiente: $" . number_format($summary['pending_amount'], 2));

        // Mostrar información adicional para mayor claridad
        $this->info("\n💡 INFORMACIÓN ADICIONAL:");
        $this->info("   🎯 Facturas procesadas en esta ejecución: " . ($summary['paid_invoices'] ?? 0));
        $this->info("   📅 Facturas generadas hoy: " . \App\Models\Invoice::whereDate('created_at', today())->count());
    }
}