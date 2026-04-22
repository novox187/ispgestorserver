<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\AutoBillingService;

class InvoiceController extends Controller
{
    /**
     * Generar facturas automáticas (mensuales)
     * Utiliza el servicio AutoBillingService para revisar los planes de clientes
     * activos y generar las facturas que correspondan según su ciclo de facturación.
     * Se usa en el dashboard o panel de facturas para forzar la generación manual 
     * de lo que normalmente correría por cron.
     */
    public function generateAuto(AutoBillingService $billingService)
    {
        try {
            $invoices = $billingService->generateMonthlyInvoices();
            return response()->json([
                'message' => 'Facturas generadas exitosamente',
                'count' => count($invoices),
                'invoices' => $invoices
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
    public function store(StoreInvoiceRequest $request)
    {
        try {
            DB::beginTransaction();

            $data = $request->validated();
            
            // Generar número de factura si no viene (aunque el request no lo pide, el modelo lo tiene)
            $data['invoice_number'] = Invoice::generateInvoiceNumber();
            
            // Calcular total si no viene o recalcular
            $amount = $data['amount'];
            $tax = $data['tax_amount'] ?? 0;
            $data['total_amount'] = $amount + $tax;

            $invoice = Invoice::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Factura creada exitosamente',
                'invoice' => $invoice
            ], 201);

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
