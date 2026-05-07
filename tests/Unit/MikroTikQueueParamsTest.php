<?php

use App\Models\Client;
use App\Models\Plan;
use App\Services\IspCapacityService;
use App\Services\MikroTikQueueSyncService;
use App\Services\MikroTikService;

function makeQueueSyncForTests(): MikroTikQueueSyncService
{
    $mk = new MikroTikService(null);
    $capacity = new IspCapacityService($mk);

    return new class($mk, $capacity) extends MikroTikQueueSyncService {
        public function planParams(Plan $plan, array $targets): array
        {
            return $this->buildPlanQueueParams($plan, $targets);
        }

        public function clientParams(Client $client, Plan $plan, string $parentName, string $ip): array
        {
            return $this->buildClientQueueParams($client, $plan, $parentName, $ip);
        }
    };
}

test('cola de plan usa upload_limit/download_limit en max-limit y limit-at y define queue type por defecto', function () {
    $sync = makeQueueSyncForTests();

    $plan = new Plan([
        'name' => 'Plan 10/5',
        'slug' => 'plan-10-5',
        'download_limit' => '10M',
        'upload_limit' => '5M',
        'priority' => 8,
    ]);

    $params = $sync->planParams($plan, ['10.0.0.1']);

    expect($params['max-limit'])->toBe('5M/10M');
    expect($params['limit-at'])->toBe('5M/10M');
    expect($params['queue'])->toBe('pcq-upload-default/pcq-download-default');
});

test('cola de cliente usa upload_limit/download_limit en max-limit, calcula limit-at por reúso y define queue type por defecto', function () {
    $sync = makeQueueSyncForTests();

    $plan = new Plan([
        'name' => 'Plan 10/5',
        'slug' => 'plan-10-5',
        'ratio' => '1:4',
        'download_limit' => '10M',
        'upload_limit' => '5M',
        'priority' => 8,
    ]);

    $client = new Client([
        'full_name' => 'Juan Perez',
        'document_id' => '123',
    ]);
    $client->id = 99;

    $params = $sync->clientParams($client, $plan, 'plan-10-5', '192.168.1.10');

    expect($params['max-limit'])->toBe('5M/10M');
    expect($params['limit-at'])->toBe('1.25M/2.5M');
    expect($params['queue'])->toBe('pcq-upload-default/pcq-download-default');
});

test('valores sin unidad se normalizan a Mbps en colas', function () {
    $sync = makeQueueSyncForTests();

    $plan = new Plan([
        'name' => 'Plan',
        'slug' => 'plan',
        'download_limit' => '10',
        'upload_limit' => '5',
        'priority' => 8,
    ]);

    $params = $sync->planParams($plan, []);

    expect($params['max-limit'])->toBe('5M/10M');
    expect($params['limit-at'])->toBe('5M/10M');
});
