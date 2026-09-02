<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use App\Models\NetworkSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sitios, enlaces y el mapa.
 *
 * El endpoint `map()` devuelve todo de una vez a propósito. Pintar un mapa
 * exige sitios, equipos y enlaces a la vez, y resolverlo con tres peticiones
 * dejaría un estado intermedio en el que se dibujan líneas hacia nodos que aún
 * no existen.
 */
class NetworkTopologyController extends Controller
{
    // ── Sitios ───────────────────────────────────────────────────────────────

    public function sites(): JsonResponse
    {
        $sites = NetworkSite::query()
            ->withCount('devices')
            ->orderBy('name')
            ->get()
            ->map(fn (NetworkSite $s) => $this->mapSite($s));

        return response()->json(['data' => $sites]);
    }

    public function storeSite(Request $request): JsonResponse
    {
        $site = NetworkSite::create($request->validate($this->siteRules()));

        return response()->json(['data' => $this->mapSite($site)], 201);
    }

    public function updateSite(Request $request, int $id): JsonResponse
    {
        $site = NetworkSite::findOrFail($id);
        $validated = $request->validate($this->siteRules(forUpdate: true));

        // Un sitio no puede ser su propio padre ni descender de sí mismo: eso
        // haría un ciclo y el mapa entraría en bucle al plegar por zonas.
        if (($validated['parent_site_id'] ?? null) !== null
            && $this->wouldCycle($site, (int) $validated['parent_site_id'])) {
            return response()->json([
                'error' => [
                    'code'    => 'SITE_CYCLE',
                    'message' => 'Ese sitio no puede depender de sí mismo ni de uno de los suyos.',
                ],
            ], 422);
        }

        $site->update($validated);

        return response()->json(['data' => $this->mapSite($site->fresh())]);
    }

    public function destroySite(int $id): JsonResponse
    {
        // Los equipos que había en él quedan sin ubicar, a la vista, en vez de
        // borrarse con el sitio: lo dice la clave foránea, y esto solo lo hace
        // explícito para quien lea el controlador.
        NetworkSite::findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── Enlaces ──────────────────────────────────────────────────────────────

    public function links(Request $request): JsonResponse
    {
        $links = NetworkLink::query()
            ->with(['endpointA:id,name,vendor,role', 'endpointB:id,name,vendor,role'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id')
            ->get()
            ->map(fn (NetworkLink $l) => $this->mapLink($l));

        return response()->json(['data' => $links]);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'a_device_id' => ['required', 'integer', 'exists:network_devices,id', 'different:b_device_id'],
            'b_device_id' => ['required', 'integer', 'exists:network_devices,id'],
            'type'        => ['required', Rule::in(['wireless_ptp', 'wireless_ptmp', 'fiber', 'utp', 'vpn'])],
            'expected_capacity_mbps' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ]);

        $link = NetworkLink::record(
            (int) $validated['a_device_id'],
            (int) $validated['b_device_id'],
            NetworkLink::SOURCE_MANUAL,
            array_diff_key($validated, array_flip(['a_device_id', 'b_device_id'])),
        );

        if ($link === null) {
            return response()->json([
                'error' => ['code' => 'LINK_INVALID', 'message' => 'Un equipo no se enlaza consigo mismo.'],
            ], 422);
        }

        // Un enlace que declara una persona nace ya confirmado: no hay nada que
        // confirmar sobre lo que acaba de afirmar.
        $link->update(['status' => NetworkLink::STATUS_CONFIRMED]);

        return response()->json(['data' => $this->mapLink($link->fresh(['endpointA', 'endpointB']))], 201);
    }

    /** Confirmar o archivar un enlace descubierto. */
    public function updateLink(Request $request, int $id): JsonResponse
    {
        $link = NetworkLink::findOrFail($id);

        $link->update($request->validate([
            'status' => ['sometimes', Rule::in([
                NetworkLink::STATUS_DISCOVERED,
                NetworkLink::STATUS_CONFIRMED,
                NetworkLink::STATUS_ARCHIVED,
            ])],
            'type'  => ['sometimes', Rule::in(['wireless_ptp', 'wireless_ptmp', 'fiber', 'utp', 'vpn'])],
            'expected_capacity_mbps' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]));

        return response()->json(['data' => $this->mapLink($link->fresh(['endpointA', 'endpointB']))]);
    }

    public function destroyLink(int $id): JsonResponse
    {
        NetworkLink::findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── Mapa ─────────────────────────────────────────────────────────────────

    /**
     * Todo lo que el mapa necesita, en una sola respuesta.
     *
     * Los equipos sin coordenadas propias heredan las de su sitio. Los que no
     * tienen ninguna de las dos se devuelven igual, marcados: el mapa los
     * enseña en una lista aparte en vez de esconderlos, porque un equipo sin
     * ubicar es algo que hay que arreglar, no algo que ignorar.
     */
    public function map(): JsonResponse
    {
        $sites = NetworkSite::query()->get();

        $devices = NetworkDevice::query()
            ->active()
            ->with('site:id,name,latitude,longitude')
            ->get();

        $located = $devices->filter(fn (NetworkDevice $d) => $this->coordsFor($d) !== null);

        $links = NetworkLink::query()
            ->visible()
            ->whereIn('a_device_id', $located->pluck('id'))
            ->whereIn('b_device_id', $located->pluck('id'))
            ->with(['endpointA:id,name,last_signal_dbm,last_ccq_percent', 'endpointB:id,name,last_signal_dbm,last_ccq_percent'])
            ->get();

        return response()->json(['data' => [
            'sites' => $sites->map(fn (NetworkSite $s) => $this->mapSite($s))->values(),

            'devices' => $devices->map(function (NetworkDevice $d) {
                $coords = $this->coordsFor($d);

                return [
                    'id'        => $d->id,
                    'name'      => $d->name,
                    'vendor'    => $d->vendor?->value,
                    'role'      => $d->role?->value,
                    'role_label' => $d->role?->label(),
                    'host'      => $d->host,
                    'site_id'   => $d->site_id,
                    'site_name' => $d->site?->name,
                    'latitude'  => $coords['lat'] ?? null,
                    'longitude' => $coords['lng'] ?? null,
                    // Distingue «tiene coordenadas propias» de «las hereda del
                    // sitio»: mover el sitio movería a los segundos y no a los
                    // primeros, y conviene que se vea.
                    'located_by' => $coords === null ? null : $coords['source'],
                    'connectivity_status' => $d->connectivity_status,
                    'last_signal_dbm'  => $d->last_signal_dbm,
                    'last_ccq_percent' => $d->last_ccq_percent,
                ];
            })->values(),

            'links' => $links->map(fn (NetworkLink $l) => $this->mapLink($l))->values(),
        ]]);
    }

    /**
     * Coordenadas efectivas de un equipo.
     *
     * @return array{lat: string, lng: string, source: string}|null
     */
    private function coordsFor(NetworkDevice $device): ?array
    {
        if ($device->latitude !== null && $device->longitude !== null) {
            return ['lat' => $device->latitude, 'lng' => $device->longitude, 'source' => 'device'];
        }

        $site = $device->site;

        if ($site?->latitude !== null && $site?->longitude !== null) {
            return ['lat' => $site->latitude, 'lng' => $site->longitude, 'source' => 'site'];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function siteRules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'name'           => [$required, 'string', 'max:100'],
            'type'           => [$required, Rule::in(NetworkSite::TYPES)],
            'address'        => ['nullable', 'string', 'max:255'],
            'latitude'       => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'      => ['nullable', 'numeric', 'between:-180,180'],
            'elevation_m'    => ['nullable', 'integer', 'between:-500,9000'],
            'parent_site_id' => ['nullable', 'integer', 'exists:network_sites,id'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** ¿Poner a `$parentId` como padre de `$site` crearía un ciclo? */
    private function wouldCycle(NetworkSite $site, int $parentId): bool
    {
        if ($parentId === $site->id) {
            return true;
        }

        $visitados = [];
        $actual    = NetworkSite::find($parentId);

        while ($actual !== null && !isset($visitados[$actual->id])) {
            if ($actual->id === $site->id) {
                return true;
            }

            $visitados[$actual->id] = true;
            $actual = $actual->parent;
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function mapSite(NetworkSite $site): array
    {
        return [
            'id'             => $site->id,
            'name'           => $site->name,
            'type'           => $site->type,
            'type_label'     => $site->typeLabel(),
            'address'        => $site->address,
            'latitude'       => $site->latitude,
            'longitude'      => $site->longitude,
            'elevation_m'    => $site->elevation_m,
            'parent_site_id' => $site->parent_site_id,
            'notes'          => $site->notes,
            'devices_count'  => $site->devices_count ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function mapLink(NetworkLink $link): array
    {
        return [
            'id'          => $link->id,
            'a_device_id' => $link->a_device_id,
            'b_device_id' => $link->b_device_id,
            'a_name'      => $link->endpointA?->name,
            'b_name'      => $link->endpointB?->name,
            'type'        => $link->type,
            'status'      => $link->status,
            'discovery_source' => $link->discovery_source,
            'last_seen_at'     => $link->last_seen_at?->toIso8601String(),
            'expected_capacity_mbps' => $link->expected_capacity_mbps,
            'notes'       => $link->notes,
            /*
             * La calidad del enlace NO se guarda: se deriva de la peor señal de
             * sus dos extremos. Duplicarla en la fila del enlace obligaría a
             * mantenerla sincronizada con cada muestra, y acabaría desfasada.
             */
            'signal_dbm'  => $this->worstSignal($link),
        ];
    }

    private function worstSignal(NetworkLink $link): ?int
    {
        $señales = array_filter([
            $link->endpointA?->last_signal_dbm,
            $link->endpointB?->last_signal_dbm,
        ], fn ($v) => $v !== null);

        return $señales === [] ? null : min($señales);
    }
}
