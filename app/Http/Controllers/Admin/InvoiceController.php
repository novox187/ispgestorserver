<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AutoBillingService;
use App\Services\SettingService;
use App\Services\InvoiceConfigValidator;

class InvoiceController extends Controller
{
    /**
     * Preflight: verify invoice configuration is complete before any billing operation.
     * The frontend calls this before opening the create form or triggering auto-generation.
     * Returns 200 {valid: true} or 422 {valid: false, missing, invalid, messages}.
     */
    public function configCheck(InvoiceConfigValidator $validator)
    {
        $result = $validator->validate();

        if ($result['valid']) {
            return response()->json(['valid' => true]);
        }

        $employee = auth()->user();
        Log::channel('billing')->warning('Intento de facturación bloqueado: configuración incompleta', [
            'employee_id'   => $employee?->id,
            'employee_name' => $employee?->name,
            'missing'       => $result['missing'],
            'invalid'       => $result['invalid'],
            'timestamp'     => now()->toIso8601String(),
        ]);

        return response()->json([
            'valid'    => false,
            'missing'  => $result['missing'],
            'invalid'  => $result['invalid'],
            'messages' => $result['messages'],
        ], 422);
    }

    /**
     * Generar facturas automáticas (mensuales).
     */
    public function generateAuto(AutoBillingService $billingService, InvoiceConfigValidator $validator)
    {
        // Pre-validate before delegating to the billing service
        $check = $validator->validate();
        if (!$check['valid']) {
            $employee = auth()->user();
            Log::channel('billing')->warning('Generación automática bloqueada: configuración incompleta', [
                'employee_id'   => $employee?->id,
                'employee_name' => $employee?->name,
                'missing'       => $check['missing'],
                'invalid'       => $check['invalid'],
                'timestamp'     => now()->toIso8601String(),
            ]);

            return response()->json([
                'error'    => 'No es posible generar facturas. Debe configurar previamente los parámetros de facturación.',
                'valid'    => false,
                'missing'  => $check['missing'],
                'invalid'  => $check['invalid'],
                'messages' => $check['messages'],
                'config_url' => '/configuraciones/facturacion',
            ], 422);
        }

        try {
            $invoices = $billingService->generateMonthlyInvoices();
            return response()->json([
                'message'  => 'Facturas generadas exitosamente',
                'count'    => count($invoices),
                'invoices' => $invoices,
                'tax_rate_used' => $invoices[0]->configuration_snapshot['tax_rate']['value'] ?? null,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al generar facturas: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Listar facturas con filtros y paginación
     */
    public function index(Request $request)
    {
        $invoices = Invoice::with(['client:id,full_name,email,document_id', 'clientPlan.plan:id,name'])
            ->filter($request->all())
            ->orderBy($request->get('sort_by', 'created_at'), $request->get('sort_order', 'desc'))
            ->paginate($request->get('per_page', 15));

        return response()->json($invoices);
    }

    /**
     * Crear una nueva factura
     */
    public function store(StoreInvoiceRequest $request, SettingService $settings)
    {
        try {
            DB::beginTransaction();

            // Build the immutable configuration snapshot before any calculation.
            // This throws ValidationException if required settings are missing.
            $snapshot = $settings->buildInvoiceSnapshot();

            $data = $request->validated();

            $data['invoice_number'] = Invoice::generateInvoiceNumber();

            // REGLA DE CÁLCULO: totals are derived exclusively from the snapshot's
            // tax_rate, not from the request payload, to guarantee absolute coherence.
            $amount  = (float) $data['amount'];
            $taxRate = $settings->taxRateFromSnapshot($snapshot);
            $tax     = round($amount * $taxRate, 2);

            $data['tax_amount']              = $tax;
            $data['total_amount']            = round($amount + $tax, 2);
            $data['configuration_snapshot']  = $snapshot;

            $invoice = Invoice::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Factura creada exitosamente',
                'invoice' => $invoice,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            $employee = auth()->user();
            Log::channel('billing')->warning('Creación manual de factura bloqueada: configuración incompleta', [
                'employee_id'   => $employee?->id,
                'employee_name' => $employee?->name,
                'errors'        => $e->errors(),
                'timestamp'     => now()->toIso8601String(),
            ]);
            return response()->json([
                'error'      => 'No es posible crear la factura. Debe configurar previamente los parámetros de facturación.',
                'details'    => $e->errors(),
                'config_url' => '/configuraciones/facturacion',
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al crear la factura: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Mostrar detalles de una factura
     */
    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'clientPlan.plan', 'transaction']);
        return response()->json($invoice);
    }

    /**
     * Actualizar una factura
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        try {
            DB::beginTransaction();

            $data = $request->validated();

            // Si se actualizan montos, recalcular total
            if (isset($data['amount']) || isset($data['tax_amount'])) {
                $amount = $data['amount'] ?? $invoice->amount;
                $tax = $data['tax_amount'] ?? $invoice->tax_amount;
                $data['total_amount'] = $amount + $tax;
            }

            // Si cambia a pagada y no tiene fecha, ponerla
            if (isset($data['status']) && $data['status'] === Invoice::STATUS_PAID && !$invoice->paid_at) {
                $data['paid_at'] = now();
            }

            $invoice->update($data);

            DB::commit();

            return response()->json([
                'message' => 'Factura actualizada exitosamente',
                'invoice' => $invoice
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al actualizar la factura: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cobro manual de una factura por parte de un administrador.
     * Intenta descontar el total desde la wallet del cliente. Si no hay saldo
     * suficiente, permite registrar el pago con método 'manual' (efectivo/transferencia)
     * sin tocar la wallet. Registra la operación en el log de auditoría.
     */
    public function charge(Request $request, Invoice $invoice, AutoBillingService $billingService)
    {
        $validated = $request->validate([
            'method'    => ['required', 'in:wallet,manual,card,transfer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        // Solo se pueden cobrar facturas en estado pendiente o fallida
        if (!in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_FAILED])) {
            return response()->json([
                'error' => "No se puede cobrar una factura con estado '{$invoice->status}'. Solo se permiten facturas pendientes o fallidas.",
            ], 422);
        }

        $employee = auth()->user();

        try {
            if ($validated['method'] === Invoice::PAYMENT_WALLET) {
                // Usar la lógica existente de AutoBillingService (descuenta de wallet)
                $result = $billingService->processInvoicePayment($invoice);

                if (!$result['success']) {
                    return response()->json([
                        'error' => $result['error'] ?? 'No se pudo procesar el pago desde la wallet.',
                    ], 422);
                }

                $invoice->refresh();

                Log::channel('billing')->info('Cobro manual via wallet', [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount'   => $invoice->total_amount,
                    'employee_id'    => $employee?->id,
                    'employee_name'  => $employee?->name,
                    'reference'      => $result['transaction_reference'] ?? null,
                    'notes'          => $validated['notes'] ?? null,
                    'timestamp'      => now()->toIso8601String(),
                ]);

                return response()->json([
                    'message'   => 'Pago descontado de la wallet exitosamente.',
                    'invoice'   => $invoice->load(['client', 'clientPlan.plan']),
                    'reference' => $result['transaction_reference'] ?? null,
                ]);
            }

            // Pago manual (efectivo, transferencia, tarjeta) — sin tocar wallet
            DB::beginTransaction();

            $reference = $validated['reference'] ?? 'MAN-' . strtoupper(substr(md5(uniqid()), 0, 8));

            $invoice->markAsPaid($validated['method'], $reference);

            DB::commit();

            Log::channel('billing')->info('Cobro manual registrado', [
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount'   => $invoice->total_amount,
                'method'         => $validated['method'],
                'reference'      => $reference,
                'employee_id'    => $employee?->id,
                'employee_name'  => $employee?->name,
                'notes'          => $validated['notes'] ?? null,
                'timestamp'      => now()->toIso8601String(),
            ]);

            return response()->json([
                'message'   => 'Pago registrado correctamente.',
                'invoice'   => $invoice->load(['client', 'clientPlan.plan']),
                'reference' => $reference,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::channel('billing')->error('Error en cobro manual', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
                'employee_id' => $employee?->id,
            ]);
            return response()->json(['error' => 'Error al procesar el cobro: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar (Soft Delete) una factura
     */
    public function destroy(Invoice $invoice)
    {
        try {
            $invoice->delete();
            return response()->json(['message' => 'Factura eliminada correctamente']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar la factura'], 500);
        }
    }
}
