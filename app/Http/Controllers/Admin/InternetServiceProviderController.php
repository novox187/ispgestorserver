<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternetServiceProvider;
use Illuminate\Http\Request;

class InternetServiceProviderController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = strtolower((string) $request->query('status', 'all'));

        $query = InternetServiceProvider::query()->withCount('connections');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', '%' . $search . '%')
                    ->orWhere('technical_support_contact', 'like', '%' . $search . '%')
                    ->orWhere('support_phone', 'like', '%' . $search . '%')
                    ->orWhere('support_email', 'like', '%' . $search . '%');
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $items = $query->orderBy('company_name')->get();

        $data = $items->map(function (InternetServiceProvider $isp) {
            return [
                'id' => $isp->id,
                'company_name' => $isp->company_name,
                'technical_support_contact' => $isp->technical_support_contact,
                'support_phone' => $isp->support_phone,
                'support_email' => $isp->support_email,
                'address' => $isp->address,
                'payment_method' => $isp->payment_method,
                'account_number' => $isp->account_number,
                'is_active' => (bool) $isp->is_active,
                'status' => $isp->is_active ? 'active' : 'inactive',
                'connections_count' => (int) ($isp->connections_count ?? 0),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'technical_support_contact' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $isp = new InternetServiceProvider();
        $isp->fill($validated);
        if (!array_key_exists('is_active', $validated)) $isp->is_active = true;
        $isp->save();

        $isp->loadCount('connections');

        return response()->json([
            'data' => [
                'id' => $isp->id,
                'company_name' => $isp->company_name,
                'technical_support_contact' => $isp->technical_support_contact,
                'support_phone' => $isp->support_phone,
                'support_email' => $isp->support_email,
                'address' => $isp->address,
                'payment_method' => $isp->payment_method,
                'account_number' => $isp->account_number,
                'is_active' => (bool) $isp->is_active,
                'status' => $isp->is_active ? 'active' : 'inactive',
                'connections_count' => (int) ($isp->connections_count ?? 0),
            ],
        ], 201);
    }

    public function show(int $id)
    {
        $isp = InternetServiceProvider::withCount('connections')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $isp->id,
                'company_name' => $isp->company_name,
                'technical_support_contact' => $isp->technical_support_contact,
                'support_phone' => $isp->support_phone,
                'support_email' => $isp->support_email,
                'address' => $isp->address,
                'payment_method' => $isp->payment_method,
                'account_number' => $isp->account_number,
                'is_active' => (bool) $isp->is_active,
                'status' => $isp->is_active ? 'active' : 'inactive',
                'connections_count' => (int) ($isp->connections_count ?? 0),
                'created_at' => optional($isp->created_at)->toISOString(),
                'updated_at' => optional($isp->updated_at)->toISOString(),
            ],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $isp = InternetServiceProvider::findOrFail($id);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'technical_support_contact' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $isp->fill($validated);
        $isp->save();
        $isp->loadCount('connections');

        return response()->json([
            'data' => [
                'id' => $isp->id,
                'company_name' => $isp->company_name,
                'technical_support_contact' => $isp->technical_support_contact,
                'support_phone' => $isp->support_phone,
                'support_email' => $isp->support_email,
                'address' => $isp->address,
                'payment_method' => $isp->payment_method,
                'account_number' => $isp->account_number,
                'is_active' => (bool) $isp->is_active,
                'status' => $isp->is_active ? 'active' : 'inactive',
                'connections_count' => (int) ($isp->connections_count ?? 0),
            ],
        ]);
    }

    public function destroy(int $id)
    {
        $isp = InternetServiceProvider::findOrFail($id);
        $isp->delete();

        return response()->json(['data' => true]);
    }
}

