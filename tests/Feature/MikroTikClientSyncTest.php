<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Plan;
use App\Services\MikroTikQueueSyncService;
use App\Services\MikroTikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RouterOS\Client as RouterClient;
use RouterOS\Query;

uses(RefreshDatabase::class);

class FakeMikroTikService extends MikroTikService
{
    private array $queues = [];
    private int $id = 1;

    public function runQuery(Query $query): array
    {
        $endpoint = $query->getEndpoint();
        $attrs = $query->getAttributes();

        $equals = [];
        $wheres = [];

        foreach ($attrs as $a) {
            if (is_string($a) && str_starts_with($a, '=')) {
                $kv = substr($a, 1);
                $parts = explode('=', $kv, 2);
                $equals[$parts[0]] = $parts[1] ?? '';
            } elseif (is_string($a) && str_starts_with($a, '?')) {
                $kv = substr($a, 1);
                $parts = explode('=', $kv, 2);
                $wheres[$parts[0]] = $parts[1] ?? '';
            }
        }

        if ($endpoint === '/queue/simple/print') {
            if (isset($wheres['name'])) {
                $name = $wheres['name'];
                return array_values(array_filter($this->queues, fn ($q) => ($q['name'] ?? null) === $name));
            }
            return array_values($this->queues);
        }

        if ($endpoint === '/queue/simple/add') {
            $name = $equals['name'] ?? null;
            if (!$name) {
                return [];
            }
            $qid = '*' . $this->id++;
            $queue = array_merge([
                '.id' => $qid,
                'name' => $name,
                'parent' => $equals['parent'] ?? 'none',
                'target' => $equals['target'] ?? '0.0.0.0/0',
            ], $equals);

            $this->queues[$qid] = $queue;
            return [$queue];
        }

        if ($endpoint === '/queue/simple/set') {
            $qid = $equals['.id'] ?? null;
            if (!$qid || !isset($this->queues[$qid])) {
                return [];
            }
            foreach ($equals as $k => $v) {
                if ($k === '.id') {
                    continue;
                }
                $this->queues[$qid][$k] = $v;
            }
            return [$this->queues[$qid]];
        }

        if ($endpoint === '/queue/simple/remove') {
            $qid = $equals['.id'] ?? null;
            if ($qid && isset($this->queues[$qid])) {
                unset($this->queues[$qid]);
            }
            return [];
        }

        return [];
    }

    public function getQueuesByName(): array
    {
        $byName = [];
        foreach ($this->queues as $q) {
            $byName[$q['name']] = $q;
        }
        return $byName;
    }
}

it('crea la cola del cliente con formato nombre_normalizado_documento', function () {
    $routerClient = Mockery::mock(RouterClient::class);
    $mikrotik = new FakeMikroTikService($routerClient);
    $service = new MikroTikQueueSyncService($mikrotik);

    $plan = Plan::factory()->create([
        'name' => 'Plan Test',
        'priority' => 8,
        'upload_speed' => 10,
        'download_speed' => 20,
        'upload_limit' => null,
        'download_limit' => null,
        'burst_limit' => null,
    ]);

    $client = Client::factory()->create([
        'full_name' => 'Carlos Ramirez',
        'document_id' => '1234',
        'ip' => '192.168.20.10',
    ]);

    $clientPlan = ClientPlan::factory()->create([
        'client_id' => $client->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ip_address' => $client->ip,
        'mikrotik_queue_id' => null,
    ]);

    $service->createClientAndQueue($client, $clientPlan, $plan);

    $expected = 'carlos_ramirez_1234';
    expect($clientPlan->refresh()->mikrotik_queue_id)->toBe($expected);

    $queues = $mikrotik->getQueuesByName();
    expect($queues)->toHaveKey($expected);
    expect($queues[$expected]['parent'])->toBe($plan->refresh()->mikrotik_queue_name);
});

it('renombra y actualiza la cola del cliente al editar nombre/documento', function () {
    $routerClient = Mockery::mock(RouterClient::class);
    $mikrotik = new FakeMikroTikService($routerClient);
    $service = new MikroTikQueueSyncService($mikrotik);

    $plan = Plan::factory()->create([
        'name' => 'Plan Edit',
        'priority' => 8,
        'upload_speed' => 10,
        'download_speed' => 20,
    ]);

    $client = Client::factory()->create([
        'full_name' => 'Cliente Uno',
        'document_id' => '111',
        'ip' => '192.168.20.11',
    ]);

    $clientPlan = ClientPlan::factory()->create([
        'client_id' => $client->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ip_address' => $client->ip,
        'mikrotik_queue_id' => 'CLIENTE_1_111',
    ]);

    $oldQueue = new Query('/queue/simple/add');
    $oldQueue->equal('name', 'CLIENTE_1_111');
    $oldQueue->equal('target', $client->ip);
    $oldQueue->equal('parent', $service->normalizeName($plan->name));
    $mikrotik->runQuery($oldQueue);

    $client->update([
        'full_name' => 'Cliente Dos',
        'document_id' => '222',
    ]);

    $result = $service->syncClientQueueForPlan($client, $clientPlan->refresh(), $plan, 'CLIENTE_1_111', $client->ip, $plan);

    expect($result['queue_name'])->toBe('cliente_dos_222');
    expect($clientPlan->refresh()->mikrotik_queue_id)->toBe('cliente_dos_222');

    $queues = $mikrotik->getQueuesByName();
    expect($queues)->toHaveKey('cliente_dos_222');
    expect($queues)->not->toHaveKey('CLIENTE_1_111');
});

