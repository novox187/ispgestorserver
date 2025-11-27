<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;

class InvoiceController extends Controller
{
    /**
     * Obtener todas las facturas del cliente autenticado
     */
    public function getAllInvoices(Request $request)
    {
        // Obtener el cliente autenticado
        $client = Auth::user();

        if (!$client) {
            return response()->json(['error' => 'Cliente no autenticado'], 401);
        }

        // Obtener todas las facturas del cliente
        $invoices = Invoice::where('client_id', $client->id)
            ->with(['clientPlan.plan']) // Cargar relaciones si es necesario
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'invoices' => $invoices
        ]);
    }

    /**
     * Obtener todas las facturas pagadas del cliente autenticado
     */
    public function getPaidInvoices(Request $request)
    {
        // Obtener el cliente autenticado
         $client = Auth::user();

        if (!$client) {
            return response()->json(['error' => 'Cliente no autenticado'], 401);
        }

        // Obtener las facturas pagadas del cliente
        $paidInvoices = Invoice::where('client_id', $client->id)
            ->paid()
            ->with(['clientPlan.plan']) // Cargar relaciones si es necesario
            ->orderBy('paid_at', 'desc')
            ->get();

        return response()->json([
            'invoices' => $paidInvoices
        ]);
    }
}
