<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientWhitelist;
use App\Models\Employee;
use App\Services\ClientWhitelistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientWhitelistController extends Controller
{
    public function __construct(
        private readonly ClientWhitelistService $service,
    ) {}

    /**
     * Listar las inclusiones de la lista blanca.
     * Soporta filtros: search (cliente), status (active|inactive|all).
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = strtolower((string) $request->query('status', 'active'));

        $query = ClientWhitelist::query()
            ->with([
                'client:id,full_name,document_id,email,service_status',
                'authorizedBy:id,nombre,email',
            ]);

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'inactive') {
            $query->where(function ($q) {
                $q->where('active', false)
                  ->orWhere('expires_at', '<=', now());
            });
        }

        if ($search !== '') {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('document_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $items = $query->orderByDesc('added_at')->limit(500)->get();

        return response()->json([
            'data' => $items->map(fn (ClientWhitelist $w) => $this->present($w))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $entry = ClientWhitelist::with([
            'client:id,full_name,document_id,email,service_status',
            'authorizedBy:id,nombre,email',
        ])->findOrFail($id);

        return response()->json(['data' => $this->present($entry)]);
    }

    /**
     * Agregar un cliente a la lista blanca.
     * Requiere super_admin (aplicado por middleware en la ruta).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'  => ['required', 'integer', 'exists:clients,id'],
            'reason'     => ['required', 'string', 'min:5', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        /** @var Employee $authorizedBy */
        $authorizedBy = $request->user();

        $client = Client::findOrFail($validated['client_id']);

        try {
            $entry = $this->service->addClient(
                client: $client,
                authorizedBy: $authorizedBy,
                reason: $validated['reason'],
                expiresAt: !empty($validated['expires_at'])
                    ? Carbon::parse($validated['expires_at'])
                    : null,
                ipAddress: $request->ip(),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'WHITELIST_ALREADY_EXISTS',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json(['data' => $this->present($entry)], 201);
    }

    /**
     * Actualizar motivo o fecha de vencimiento de una inclusión.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $entry = ClientWhitelist::findOrFail($id);

        $validated = $request->validate([
            'reason'     => ['sometimes', 'string', 'min:5', 'max:1000'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if (empty($validated)) {
            return response()->json(['data' => $this->present($entry->fresh(['authorizedBy', 'client']))]);
        }

        /** @var Employee $authorizedBy */
        $authorizedBy = $request->user();

        if (array_key_exists('expires_at', $validated) && $validated['expires_at']) {
            $validated['expires_at'] = Carbon::parse($validated['expires_at']);
        }

        $updated = $this->service->updateEntry(
            entry: $entry,
            changes: $validated,
            authorizedBy: $authorizedBy,
            ipAddress: $request->ip(),
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * Retirar al cliente de la lista blanca (baja lógica).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = ClientWhitelist::findOrFail($id);

        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Employee $authorizedBy */
        $authorizedBy = $request->user();
        $client = Client::findOrFail($entry->client_id);

        try {
            $updated = $this->service->removeClient(
                client: $client,
                authorizedBy: $authorizedBy,
                reason: $request->input('reason'),
                ipAddress: $request->ip(),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'WHITELIST_NOT_ACTIVE',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * Historial de modificaciones (auditoría) de la lista blanca.
     * Opcionalmente filtrable por client_id.
     */
    public function history(Request $request): JsonResponse
    {
        $clientId = $request->query('client_id');

        $query = Audit::query()
            ->where('table_name', 'client_whitelists')
            ->orWhere(function ($q) {
                $q->where('table_name', 'clients')
                  ->where('operation', 'SUSPEND_BLOCKED_WHITELIST');
            })
            ->orderByDesc('created_at');

        if ($clientId !== null) {
            $query->where(function ($q) use ($clientId) {
                $q->where('record_id', (string) $clientId)
                  ->orWhereJsonContains('new_values->client_id', (int) $clientId);
            });
        }

        $audits = $query->limit(500)->get();

        $authorIds = $audits->pluck('user_id')->filter()->unique()->all();
        $authors = Employee::whereIn('id', $authorIds)->get(['id', 'nombre', 'email'])->keyBy('id');

        $data = $audits->map(function (Audit $audit) use ($authors) {
            return [
                'id'         => $audit->id,
                'operation'  => $audit->operation,
                'table_name' => $audit->table_name,
                'record_id'  => $audit->record_id,
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
                'user'       => $audit->user_id ? [
                    'id'     => $audit->user_id,
                    'nombre' => $authors[$audit->user_id]->nombre ?? 'Desconocido',
                    'email'  => $authors[$audit->user_id]->email ?? null,
                ] : null,
                'ip_address' => $audit->ip_address,
                'created_at' => $audit->created_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Exporta la lista blanca activa en CSV (streamed).
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $status = strtolower((string) $request->query('status', 'active'));

        $query = ClientWhitelist::query()
            ->with(['client:id,full_name,document_id,email,service_status', 'authorizedBy:id,nombre,email']);

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'inactive') {
            $query->where(function ($q) {
                $q->where('active', false)->orWhere('expires_at', '<=', now());
            });
        }

        $filename = 'lista_blanca_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 para Excel
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID',
                'Cliente ID',
                'Nombre Cliente',
                'Documento',
                'Email Cliente',
                'Estado Servicio',
                'Fecha de Inclusión',
                'Autorizado Por',
                'Email Autorizador',
                'Motivo',
                'Fecha de Vencimiento',
                'Activo',
            ]);

            $query->orderByDesc('added_at')->chunk(500, function ($batch) use ($handle) {
                foreach ($batch as $entry) {
                    /** @var ClientWhitelist $entry */
                    fputcsv($handle, [
                        $entry->id,
                        $entry->client_id,
                        $entry->client?->full_name ?? '',
                        $entry->client?->document_id ?? '',
                        $entry->client?->email ?? '',
                        $entry->client?->service_status ?? '',
                        optional($entry->added_at)->format('Y-m-d H:i:s'),
                        $entry->authorizedBy?->nombre ?? 'Sistema',
                        $entry->authorizedBy?->email ?? '',
                        $entry->reason,
                        optional($entry->expires_at)->format('Y-m-d H:i:s') ?: 'Permanente',
                        $entry->active ? 'Sí' : 'No',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function present(ClientWhitelist $w): array
    {
        return [
            'id'            => $w->id,
            'client_id'     => $w->client_id,
            'client'        => $w->client ? [
                'id'             => $w->client->id,
                'full_name'      => $w->client->full_name,
                'document_id'    => $w->client->document_id,
                'email'          => $w->client->email,
                'service_status' => $w->client->service_status,
            ] : null,
            'added_at'      => optional($w->added_at)->toIso8601String(),
            'authorized_by' => $w->authorized_by,
            'authorizer'    => $w->authorizedBy ? [
                'id'     => $w->authorizedBy->id,
                'nombre' => $w->authorizedBy->nombre,
                'email'  => $w->authorizedBy->email,
            ] : null,
            'reason'        => $w->reason,
            'expires_at'    => optional($w->expires_at)->toIso8601String(),
            'active'        => (bool) $w->active,
            'is_valid'      => $w->isCurrentlyValid(),
            'created_at'    => optional($w->created_at)->toIso8601String(),
            'updated_at'    => optional($w->updated_at)->toIso8601String(),
        ];
    }
}
