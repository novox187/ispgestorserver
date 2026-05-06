<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternetServiceProvider;
use App\Models\IspConnection;
use Illuminate\Http\Request;

class IspConnectionController extends Controller
{
    public function index(Request $request)
    {
        $ispId = $request->query('isp_id');
        $status = strtolower((string) $request->query('status', 'all'));

        $query = IspConnection::query()->with('isp');

        if ($ispId !== null && $ispId !== '') {
            $query->where('isp_id', (int) $ispId);
        }

        if ($status !== 'all' && $status !== '') {
            $query->where('status', $status);
        }

        $items = $query->orderByDesc('id')->get();

        $data = $items->map(fn (IspConnection $c) => $this->mapConnection($c));

        return response()->json(['data' => $data]);
    }

    public function indexByIsp(int $ispId)
    {
        $isp = InternetServiceProvider::findOrFail($ispId);

        $items = IspConnection::query()
            ->where('isp_id', $isp->id)
            ->orderByDesc('id')
            ->get();

        $data = $items->map(fn (IspConnection $c) => $this->mapConnection($c));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'isp_id' => ['required', 'integer', 'exists:internet_service_providers,id'],
            'bandwidth_down' => ['required', 'numeric', 'min:0.01'],
            'bandwidth_up' => ['required', 'numeric', 'min:0.01'],
            'ratio' => ['nullable', 'string', 'max:50'],
            'contract_date' => ['required', 'date'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'billing_cycle' => ['nullable', 'string', 'max:50'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'interface_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,maintenance,suspended,canceled'],
        ]);

        $conn = new IspConnection();
        $conn->fill($validated);
        if (!array_key_exists('ratio', $validated) || $validated['ratio'] === null) $conn->ratio = '1:1';
        if (!array_key_exists('billing_cycle', $validated) || $validated['billing_cycle'] === null) $conn->billing_cycle = 'monthly';
        if (!array_key_exists('status', $validated) || $validated['status'] === null) $conn->status = 'active';
        $conn->save();
        $conn->load('isp');

        return response()->json(['data' => $this->mapConnection($conn)], 201);
    }

    public function storeForIsp(Request $request, int $ispId)
    {
        $isp = InternetServiceProvider::findOrFail($ispId);

        $validated = $request->validate([
            'bandwidth_down' => ['required', 'numeric', 'min:0.01'],
            'bandwidth_up' => ['required', 'numeric', 'min:0.01'],
            'ratio' => ['nullable', 'string', 'max:50'],
            'contract_date' => ['required', 'date'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'billing_cycle' => ['nullable', 'string', 'max:50'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'interface_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,maintenance,suspended,canceled'],
        ]);

        $conn = new IspConnection();
        $conn->fill($validated);
        $conn->isp_id = $isp->id;
        if (!array_key_exists('ratio', $validated) || $validated['ratio'] === null) $conn->ratio = '1:1';
        if (!array_key_exists('billing_cycle', $validated) || $validated['billing_cycle'] === null) $conn->billing_cycle = 'monthly';
        if (!array_key_exists('status', $validated) || $validated['status'] === null) $conn->status = 'active';
        $conn->save();
        $conn->load('isp');

        return response()->json(['data' => $this->mapConnection($conn)], 201);
    }

    public function show(int $id)
    {
        $conn = IspConnection::with('isp')->findOrFail($id);
        return response()->json(['data' => $this->mapConnection($conn)]);
    }

    public function update(Request $request, int $id)
    {
        $conn = IspConnection::with('isp')->findOrFail($id);

        $validated = $request->validate([
            'isp_id' => ['required', 'integer', 'exists:internet_service_providers,id'],
            'bandwidth_down' => ['required', 'numeric', 'min:0.01'],
            'bandwidth_up' => ['required', 'numeric', 'min:0.01'],
            'ratio' => ['nullable', 'string', 'max:50'],
            'contract_date' => ['required', 'date'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'billing_cycle' => ['nullable', 'string', 'max:50'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'interface_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,maintenance,suspended,canceled'],
        ]);

        $conn->fill($validated);
        if (!array_key_exists('ratio', $validated) || $validated['ratio'] === null) $conn->ratio = '1:1';
        if (!array_key_exists('billing_cycle', $validated) || $validated['billing_cycle'] === null) $conn->billing_cycle = 'monthly';
        $conn->save();
        $conn->load('isp');

        return response()->json(['data' => $this->mapConnection($conn)]);
    }

    public function destroy(int $id)
    {
        $conn = IspConnection::findOrFail($id);
        $conn->delete();

        return response()->json(['data' => true]);
    }

    private function mapConnection(IspConnection $c): array
    {
        return [
            'id' => $c->id,
            'isp_id' => (int) $c->isp_id,
            'isp' => $c->relationLoaded('isp') && $c->isp ? [
                'id' => $c->isp->id,
                'company_name' => $c->isp->company_name,
                'status' => $c->isp->is_active ? 'active' : 'inactive',
            ] : null,
            'bandwidth_down' => (float) $c->bandwidth_down,
            'bandwidth_up' => (float) $c->bandwidth_up,
            'ratio' => $c->ratio,
            'contract_date' => $c->contract_date,
            'billing_day' => (int) $c->billing_day,
            'billing_cycle' => $c->billing_cycle,
            'monthly_price' => (float) $c->monthly_price,
            'interface_name' => $c->interface_name,
            'status' => $c->status,
            'price_per_mb' => (float) $c->price_per_mb,
        ];
    }
}

