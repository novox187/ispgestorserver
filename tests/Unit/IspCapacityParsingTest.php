<?php

use App\Services\IspCapacityService;
use App\Services\MikroTikService;

function makeCapacityForTests(): IspCapacityService
{
    return new IspCapacityService(new MikroTikService(null));
}

test('interpreta valores numéricos sin sufijo como bps cuando son grandes (RouterOS)', function () {
    $svc = makeCapacityForTests();

    $probe = new class($svc) {
        public function __construct(private IspCapacityService $svc) {}
        public function toMbps(string $v): float
        {
            $ref = new ReflectionClass($this->svc);
            $m = $ref->getMethod('toMbps');
            $m->setAccessible(true);
            return (float) $m->invoke($this->svc, $v);
        }
    };

    expect($probe->toMbps('40000000'))->toBe(40.0);
    expect($probe->toMbps('100000000'))->toBe(100.0);
});

test('mantiene sufijos K/M/G correctamente', function () {
    $svc = makeCapacityForTests();

    $probe = new class($svc) {
        public function __construct(private IspCapacityService $svc) {}
        public function toMbps(string $v): float
        {
            $ref = new ReflectionClass($this->svc);
            $m = $ref->getMethod('toMbps');
            $m->setAccessible(true);
            return (float) $m->invoke($this->svc, $v);
        }
    };

    expect($probe->toMbps('1G'))->toBe(1000.0);
    expect($probe->toMbps('40M'))->toBe(40.0);
    expect($probe->toMbps('500K'))->toBe(0.5);
});

