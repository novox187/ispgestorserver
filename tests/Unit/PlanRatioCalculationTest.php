<?php

use App\Services\IspCapacityService;
use App\Services\MikroTikService;

test('calcula ratio 1:50 cuando max=100 y garantizado=2', function () {
    $service = new IspCapacityService(new MikroTikService(null));
    $out = $service->calculateRatioFromMaxAndGuaranteed(100, 2);

    expect($out['ratio'])->toBe('1:50');
    expect($out['divisor'])->toBe(50);
    expect($out['guaranteed_mbps'])->toBe(2.0);
});

test('calcula divisor por floor para no bajar del garantizado', function () {
    $service = new IspCapacityService(new MikroTikService(null));
    $out = $service->calculateRatioFromMaxAndGuaranteed(100, 3);

    expect($out['ratio'])->toBe('1:33');
    expect($out['divisor'])->toBe(33);
    expect($out['guaranteed_mbps'])->toBeGreaterThanOrEqual(3.0);
});

test('si garantizado supera max, se normaliza a 1:1', function () {
    $service = new IspCapacityService(new MikroTikService(null));
    $out = $service->calculateRatioFromMaxAndGuaranteed(100, 150);

    expect($out['ratio'])->toBe('1:1');
    expect($out['divisor'])->toBe(1);
    expect($out['guaranteed_mbps'])->toBe(100.0);
});

