<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutoReactivation;
use App\Models\Audit;
use App\Models\Client;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function __construct(protected ChatService $chatService) {}

    /**
     * Admin: Add funds to a client's wallet.
     */
    public function addFunds(Request $request, $id): JsonResponse
    {
        $employee = Auth::user();

        $validated = $request->validate([
            'amount'      => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'image'       => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
            'metadata'    => 'nullable|array',
        ]);

        $client = Client::findOrFail($id);
        $wallet = Wallet::where('client_id', $client->id)->first();

        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró una billetera para el cliente proporcionado.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $reference = $this->generateUniqueReference();

            $metadata = $validated['metadata'] ?? [];
            $metadata['admin_employee_id']   = $employee?->id;
            $metadata['admin_employee_name'] = $employee?->nombre ?? $employee?->name ?? 'Admin';

            $transaction = Transaction::create([
                'wallet_id'   => $wallet->id,
                'type'        => Transaction::TYPE_DEPOSIT,
                'amount'      => $validated['amount'],
                'description' => $validated['description'] ?? 'Recarga administrativa',
                'reference'   => $reference,
                'status'      => Transaction::STATUS_COMPLETED,
                'metadata'    => $metadata,
            ]);

            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                $transaction->uploadImage($request->file('image'));
            }

            $wallet->increment('balance', $transaction->amount);

            Audit::create([
                'table_name' => 'transactions',
                'operation'  => 'ADMIN_ADD_FUNDS',
                'record_id'  => (string) $transaction->id,
                'old_values' => ['balance' => $wallet->balance - $transaction->amount],
                'new_values' => [
                    'balance'        => $wallet->balance,
                    'transaction_id' => $transaction->id,
                    'amount'         => $transaction->amount,
                    'client_id'      => $client->id,
                    'timestamp'      => now()->toIso8601String(),
                    'executor_id'    => $employee?->id,
                    'executor'       => $employee?->nombre ?? $employee?->name ?? 'Unknown Admin',
                ],
                'user_id'    => $employee?->id,
                'ip_address' => $request->ip(),
            ]);

            DB::commit();

            // ── Evento de billetera en el chat del cliente ────────────────────
            $this->chatService->createSystemEvent(
                clientId:    $client->id,
                eventType:   'wallet_funded',
                displayText: "Recarga de billetera: $" . number_format($transaction->amount, 2),
                metadata: [
                    'amount'      => (float) $transaction->amount,
                    'currency'    => 'USD',
                    'receipt_url' => $transaction->image_url,
                    'actor_type'  => 'employee',
                    'actor_name'  => $employee?->nombre ?? 'Administrador',
                    'description' => $transaction->description,
                    'reference'   => $transaction->reference,
                ],
            );
            // ─────────────────────────────────────────────────────────────────

            if (in_array(strtoupper($client->service_status), ['SUSPENDED', 'SUSPENDIDO'])) {
                ProcessAutoReactivation::dispatch($client)
                    ->onQueue(config('billing.queue.reactivations'));
            }

            $transaction->load(['wallet', 'wallet.client']);

            return response()->json([
                'success' => true,
                'message' => 'Fondos agregados exitosamente.',
                'data'    => $transaction,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al agregar fondos (Admin): ' . $e->getMessage(), [
                'client_id' => $id,
                'amount'    => $request->input('amount'),
                'trace'     => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al agregar fondos.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function generateUniqueReference(): string
    {
        do {
            $reference = 'TXN-ADM-' . strtoupper(Str::random(8)) . '-' . time();
        } while (Transaction::where('reference', $reference)->exists());

        return $reference;
    }
}
