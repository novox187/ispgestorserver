<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Trae todos los clientes con sus relaciones: servicio->plan y soportes
        $clientes = Client::with(['servicio.plan', 'soportes'])->get();
        return response()->json($clientes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $authCliente = $request->user();
        if (! $authCliente instanceof Client) {
            return response()->json(['message' => 'No autenticado'], 401);
        }
        // Leer solo las columnas necesarias desde la base de datos
        $data = Client::query()
            ->whereKey($authCliente->getKey())
            ->selectRaw('full_name, email, contact_phone, installation_address, gps_coordinates')
            ->first();

        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        //
    }
}
