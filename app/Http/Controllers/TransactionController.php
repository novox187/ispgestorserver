<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    /**
     * Display a listing of the transactions.
     */
public function index(Request $request)
{
    $client = Auth::user();

    $wallet = Wallet::where('client_id', $client->id)->first();

    if (!$wallet) {
        return response()->json([
            'message' => 'El usuario no tiene billetera asociada.'
        ], 404);
    }

    $perPage = $request->get('per_page', 10);

    $transactions = Transaction::where('wallet_id', $wallet->id)
        ->when($request->type, function ($q, $type) {
            $q->where('type', $type);
        })
        ->when($request->status, function ($q, $status) {
            $q->where('status', $status);
        })
        ->when($request->search, function ($q, $search) {
            $search = trim($search);
            $q->where(function ($x) use ($search) {
                $x->where('reference', 'like', "%$search%")
                  ->orWhere('description', 'like', "%$search%")
                  ->orWhere('amount', 'like', "%$search%");
            });
        })
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);

    return response()->json($transactions);
}

    /**
     * Store a newly created transaction in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $client = Auth::user();
        $wallet = Wallet::where('client_id', $client->id)->first();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró una billetera para el cliente proporcionado.',
            ], 404);
        }
        
        $validated = $request->validate([
            'type' => 'in:deposit,transfer,withdrawal,payment,refund',
            'amount' => 'numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'image' => 'image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
            'metadata' => 'nullable|array',
        ]);

        DB::beginTransaction();

        try {
            // Generar referencia única
            $reference = $this->generateUniqueReference();

            // Crear la transacción
            $transaction = Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'description' => $validated['description'],
                'reference' => $reference,
                'status' => Transaction::STATUS_PENDING,
                'metadata' => $validated['metadata'] ?? null
            ]);

            // Procesar imagen si se proporcionó
            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                $transaction->uploadImage($request->file('image'));
            }

            // Actualizar balance de la wallet si la transacción está completada
            if ($transaction->status === Transaction::STATUS_COMPLETED) {
                $this->updateWalletBalance($transaction);
            }

            DB::commit();

            // Cargar relaciones para la respuesta
            $transaction->load(['wallet', 'wallet.client']);

            return response()->json([
                'success' => true,
                'message' => 'Transacción creada exitosamente.',
                'data' => $transaction
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la transacción.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load(['wallet', 'wallet.client']);

        return response()->json([
            'success' => true,
            'data' => $transaction
        ]);
    }

    /**
     * Update the specified transaction in storage.
     */
    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'wallet_id' => 'sometimes|required|exists:wallets,id',
            'type' => 'sometimes|required|in:deposit,withdrawal,payment,refund',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'status' => 'sometimes|required|in:pending,completed,failed,cancelled',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'metadata' => 'nullable|array'
        ]);

        DB::beginTransaction();

        try {
            $oldStatus = $transaction->status;
            $oldAmount = $transaction->amount;

            // Actualizar transacción
            $transaction->update([
                'wallet_id' => $validated['wallet_id'] ?? $transaction->wallet_id,
                'type' => $validated['type'] ?? $transaction->type,
                'amount' => $validated['amount'] ?? $transaction->amount,
                'description' => $validated['description'] ?? $transaction->description,
                'status' => $validated['status'] ?? $transaction->status,
                'metadata' => $validated['metadata'] ?? $transaction->metadata
            ]);

            // Procesar nueva imagen si se proporcionó
            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                // Eliminar imagen anterior si existe
                if ($transaction->image_public_id) {
                    $transaction->deleteImage();
                }
                // Subir nueva imagen
                $transaction->uploadImage($request->file('image'));
            }

            // Actualizar balance si cambió el estado o el monto
            if (($oldStatus !== $transaction->status || $oldAmount != $transaction->amount) && 
                $transaction->status === Transaction::STATUS_COMPLETED) {
                $this->recalculateWalletBalance($transaction->wallet);
            }

            DB::commit();

            // Cargar relaciones actualizadas
            $transaction->load(['wallet', 'wallet.client']);

            return response()->json([
                'success' => true,
                'message' => 'Transacción actualizada exitosamente.',
                'data' => $transaction
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la transacción.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified transaction from storage.
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        DB::beginTransaction();

        try {
            $wallet = $transaction->wallet;
            
            // Eliminar transacción (el modelo se encarga de eliminar la imagen de Cloudinary)
            $transaction->delete();

            // Recalcular balance de la wallet
            $this->recalculateWalletBalance($wallet);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transacción eliminada exitosamente.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la transacción.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload image to transaction.
     */
    public function uploadImage(Request $request, Transaction $transaction): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120'
        ]);

        try {
            // Eliminar imagen anterior si existe
            if ($transaction->image_public_id) {
                $transaction->deleteImage();
            }

            // Subir nueva imagen
            $transaction->uploadImage($request->file('image'));

            return response()->json([
                'success' => true,
                'message' => 'Imagen subida exitosamente.',
                'data' => [
                    'image_url' => $transaction->image_url,
                    'thumbnail_url' => $transaction->getThumbnailUrl(300, 300)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al subir la imagen.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove image from transaction.
     */
    public function removeImage(Transaction $transaction): JsonResponse
    {
        try {
            $transaction->deleteImage();
            
            return response()->json([
                'success' => true,
                'message' => 'Imagen eliminada exitosamente.'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la imagen.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique transaction reference.
     */
    private function generateUniqueReference(): string
    {
        do {
            $reference = 'TXN-' . strtoupper(Str::random(8)) . '-' . time();
        } while (Transaction::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Update wallet balance based on transaction.
     */
    private function updateWalletBalance(Transaction $transaction): void
    {
        $wallet = $transaction->wallet;
        
        if ($transaction->type === Transaction::TYPE_DEPOSIT || $transaction->type === Transaction::TYPE_REFUND) {
            $wallet->increment('balance', $transaction->amount);
        } elseif ($transaction->type === Transaction::TYPE_WITHDRAWAL || $transaction->type === Transaction::TYPE_PAYMENT) {
            $wallet->decrement('balance', $transaction->amount);
        }
    }

    /**
     * Recalculate wallet balance from all completed transactions.
     */
    private function recalculateWalletBalance(Wallet $wallet): void
    {
        $balance = $wallet->transactions()
            ->completed()
            ->get()
            ->reduce(function ($carry, $transaction) {
                if ($transaction->type === Transaction::TYPE_DEPOSIT || $transaction->type === Transaction::TYPE_REFUND) {
                    return $carry + $transaction->amount;
                } elseif ($transaction->type === Transaction::TYPE_WITHDRAWAL || $transaction->type === Transaction::TYPE_PAYMENT) {
                    return $carry - $transaction->amount;
                }
                return $carry;
            }, 0);

        $wallet->update(['balance' => $balance]);
    }
}