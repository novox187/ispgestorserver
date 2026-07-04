<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientWhitelist;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * Visor general de auditoría con filtros y paginación.
     *
     * GET /admin/audits
     * Filtros: table_name, operation, record_id, user_id, date_from, date_to.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'table_name' => ['nullable', 'string', 'max:100'],
            'operation'  => ['nullable', 'string', 'max:100'],
            'record_id'  => ['nullable', 'string', 'max:100'],
            'user_id'    => ['nullable', 'integer'],
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $audits = Audit::query()
            ->when($validated['table_name'] ?? null, fn ($q, $v) => $q->where('table_name', $v))
            ->when($validated['operation'] ?? null, fn ($q, $v) => $q->where('operation', $v))
            ->when($validated['record_id'] ?? null, fn ($q, $v) => $q->where('record_id', (string) $v))
            ->when($validated['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($validated['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($validated['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \Carbon\Carbon::parse($v)->addDay()->startOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data'         => collect($audits->items())->map(fn (Audit $a) => $a->toApiArray()),
            'current_page' => $audits->currentPage(),
            'last_page'    => $audits->lastPage(),
            'per_page'     => $audits->perPage(),
            'total'        => $audits->total(),
        ]);
    }

    /**
     * Valores disponibles para los filtros del visor (tablas y operaciones).
     *
     * GET /admin/audits/filters
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'tables'     => Audit::query()->distinct()->orderBy('table_name')->pluck('table_name'),
            'operations' => Audit::query()->distinct()->orderBy('operation')->pluck('operation'),
        ]);
    }

    /**
     * Historial de auditoría integral de un cliente: cambios de datos, cortes,
     * reactivaciones, bajas, intentos fallidos, planes, facturas, billetera,
     * transacciones (recargas de fondos), lista blanca y tickets.
     *
     * GET /admin/clientes/{id}/audits
     */
    public function clientHistory(Request $request, int $id): JsonResponse
    {
        Client::findOrFail($id);

        $validated = $request->validate([
            'operation'  => ['nullable', 'string', 'max:100'],
            'table_name' => ['nullable', 'string', 'max:100'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Subconsultas por tabla relacionada: cada una resuelve los IDs de los
        // registros que pertenecen al cliente (indexado, sin escanear el JSON).
        $related = [
            'client_plans'      => ClientPlan::query()->where('client_id', $id)->select('id'),
            'invoices'          => Invoice::query()->where('client_id', $id)->select('id'),
            'wallets'           => Wallet::query()->where('client_id', $id)->select('id'),
            'client_whitelists' => ClientWhitelist::query()->where('client_id', $id)->select('id'),
            'tickets'           => Ticket::query()->where('client_id', $id)->select('id'),
            'transactions'      => Transaction::query()
                ->whereIn('wallet_id', Wallet::query()->where('client_id', $id)->select('id'))
                ->select('id'),
        ];

        $audits = Audit::query()
            ->where(function ($q) use ($id, $related) {
                $q->where(fn ($w) => $w->where('table_name', 'clients')
                    ->where('record_id', (string) $id));

                foreach ($related as $table => $idsQuery) {
                    $q->orWhere(fn ($w) => $w->where('table_name', $table)
                        ->whereIn('record_id', $idsQuery));
                }
            })
            ->when($validated['operation'] ?? null, fn ($q, $v) => $q->where('operation', $v))
            ->when($validated['table_name'] ?? null, fn ($q, $v) => $q->where('table_name', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data'         => collect($audits->items())->map(fn (Audit $a) => $a->toApiArray()),
            'current_page' => $audits->currentPage(),
            'last_page'    => $audits->lastPage(),
            'per_page'     => $audits->perPage(),
            'total'        => $audits->total(),
        ]);
    }
}
