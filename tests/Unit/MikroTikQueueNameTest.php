<?php

use App\Models\Client;
use App\Services\MikroTikQueueSyncService;
use App\Services\MikroTikService;
use RouterOS\Client as RouterClient;

test('client queue name usa nombre_normalizado_documento', function () {
    $routerClient = Mockery::mock(RouterClient::class);
    $mikrotik = new MikroTikService($routerClient);
    $service = new MikroTikQueueSyncService($mikrotik);

    $client = new Client([
        'full_name' => 'Juan Pérez',
        'document_id' => '12345678',
    ]);

    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('buildClientQueueName');
    $method->setAccessible(true);
    $name = $method->invoke($service, $client);

    expect($name)->toEndWith('_12345678');
    expect($name)->toContain('juan');
    expect($name)->not->toContain('CLIENTE_');
});

test('client queue name preserva sufijo del documento al truncar', function () {
    $routerClient = Mockery::mock(RouterClient::class);
    $mikrotik = new MikroTikService($routerClient);
    $service = new MikroTikQueueSyncService($mikrotik);

    $client = new Client([
        'full_name' => str_repeat('nombre_muy_largo_', 10),
        'document_id' => '9999999999',
    ]);

    $ref = new ReflectionClass($service);
    $method = $ref->getMethod('buildClientQueueName');
    $method->setAccessible(true);
    $name = $method->invoke($service, $client);

    expect(strlen($name))->toBeLessThanOrEqual(64);
    expect($name)->toEndWith('_9999999999');
});

