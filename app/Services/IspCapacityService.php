<?php

namespace App\Services;

use App\Models\ClientPlan;
use App\Models\IspConnection;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class IspCapacityService
{
    public function __construct(protected MikroTikService $mikrotik) {}

    public function parsePlanRatioDivisor(?string $ratio): int
    {
        $raw = trim((string) ($ratio ?? ''));
        if ($raw === '') {
            return 1;
        }
        if (preg_match('/(\d+)\s*[:\/]\s*(\d+)/', $raw, $m)) {
            $div = (int) ($m[2] ?? 1);
            return max(1, $div);
        }
        return 1;
    }

    public function getPlanReuseRatio(Plan $plan): int
    {
        $div = $this->parsePlanRatioDivisor($plan->ratio ?? null);
        if ($div > 1) {
            return $div;
        }
        return max(1, $div ?: 1);
    }

    public function calculateRatioFromMaxAndGuaranteed(float $maxMbps, float $guaranteedMbps): array
    {
        if (!is_finite($maxMbps) || $maxMbps <= 0) {
            return ['ratio' => '1:1', 'divisor' => 1, 'guaranteed_mbps' => 0.0];
        }
        if (!is_finite($guaranteedMbps) || $guaranteedMbps <= 0) {
            return ['ratio' => '1:1', 'divisor' => 1, 'guaranteed_mbps' => $maxMbps];
        }

        if ($guaranteedMbps > $maxMbps) {
            return ['ratio' => '1:1', 'divisor' => 1, 'guaranteed_mbps' => $maxMbps];
        }

        $divisor = max(1, (int) floor($maxMbps / $guaranteedMbps));
        $effectiveGuaranteed = $divisor > 0 ? ($maxMbps / $divisor) : $maxMbps;

        return [
            'ratio' => '1:' . $divisor,
            'divisor' => $divisor,
            'guaranteed_mbps' => $effectiveGuaranteed,
        ];
    }

    public function getInventoryDownMbps(): float
    {
        try {
            return (float) IspConnection::query()
                ->active()
                ->sum('bandwidth_down');
        } catch (Throwable $e) {
            Log::warning('Unable to compute ISP inventory (bandwidth_down)', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getInventoryUpMbps(): float
    {
        try {
            return (float) IspConnection::query()
                ->active()
                ->sum('bandwidth_up');
        } catch (Throwable $e) {
            Log::warning('Unable to compute ISP inventory (bandwidth_up)', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getEffectiveReuseRatio(): int
    {
        try {
            $ratio = (int) IspConnection::query()
                ->active()
                ->whereNotNull('ratio')
                ->where('ratio', '>', 0)
                ->min('ratio');

            return max(1, $ratio ?: 1);
        } catch (Throwable $e) {
            Log::warning('Unable to compute effective reuse ratio', [
                'error' => $e->getMessage(),
            ]);
            return 1;
        }
    }

    public function calculateParentMbps(float $planMbps, int $clients, int $reuse): float
    {
        if ($planMbps <= 0 || $clients <= 0) {
            return 0.0;
        }
        return ($planMbps / max(1, $reuse)) * $clients;
    }

    public function calculateNextClientDeltaMbps(float $planMbps, int $clientsBefore, int $reuse): float
    {
        $before = $this->calculateParentMbps($planMbps, $clientsBefore, $reuse);
        $after = $this->calculateParentMbps($planMbps, $clientsBefore + 1, $reuse);
        return max(0.0, $after - $before);
    }

    /**
     * Evalúa la capacidad disponible del plan específico para agregar un cliente más.
     * Solo considera los clientes activos del plan y su propio ancho de banda configurado,
     * sin tomar en cuenta las megas físicas globales del ISP.
     */
    public function getPlanCapacity(Plan $plan): array
    {
        $reuse = $this->getPlanReuseRatio($plan);
        $countActive = (int) ClientPlan::query()
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->count();

        $planDownMbps = (float) $plan->download_speed;
        $planUpMbps = (float) $plan->upload_speed;

        $expectedUsedDown = $this->calculateParentMbps($planDownMbps, $countActive, $reuse);
        $deltaDown = $this->calculateNextClientDeltaMbps($planDownMbps, $countActive, $reuse);
        $remainingDownInPlan = max(0.0, $planDownMbps - $expectedUsedDown);

        $hasCapacity = ($deltaDown <= 0.0) || ($remainingDownInPlan >= $deltaDown);

        return [
            'plan_down_mbps'            => $planDownMbps,
            'plan_up_mbps'              => $planUpMbps,
            'active_clients'            => $countActive,
            'reuse_ratio'               => $reuse,
            'expected_used_down_mbps'   => $expectedUsedDown,
            'remaining_down_in_plan_mbps' => $remainingDownInPlan,
            'delta_down_mbps'           => $deltaDown,
            'has_capacity'              => $hasCapacity,
        ];
    }

    public function getPlanActiveClientCounts(): array
    {
        try {
            return ClientPlan::query()
                ->selectRaw('plan_id, COUNT(*) as c')
                ->where('status', 'active')
                ->groupBy('plan_id')
                ->pluck('c', 'plan_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        } catch (Throwable $e) {
            Log::warning('Unable to compute active client counts by plan', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function getDbExpectedUsedDownMbps(): float
    {
        $counts = $this->getPlanActiveClientCounts();
        if (!$counts) {
            return 0.0;
        }

        try {
            $plans = Plan::query()
                ->whereIn('id', array_keys($counts))
                ->get(['id', 'download_speed', 'ratio']);

            $total = 0.0;
            foreach ($plans as $plan) {
                $c = (int) ($counts[$plan->id] ?? 0);
                $reuse = $this->getPlanReuseRatio($plan);
                $total += $this->calculateParentMbps((float) $plan->download_speed, $c, $reuse);
            }
            return $total;
        } catch (Throwable $e) {
            Log::warning('Unable to compute expected DB consumption (down Mbps)', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getDbExpectedUsedUpMbps(): float
    {
        $counts = $this->getPlanActiveClientCounts();
        if (!$counts) {
            return 0.0;
        }

        try {
            $plans = Plan::query()
                ->whereIn('id', array_keys($counts))
                ->get(['id', 'upload_speed', 'ratio']);

            $total = 0.0;
            foreach ($plans as $plan) {
                $c = (int) ($counts[$plan->id] ?? 0);
                $reuse = $this->getPlanReuseRatio($plan);
                $total += $this->calculateParentMbps((float) $plan->upload_speed, $c, $reuse);
            }
            return $total;
        } catch (Throwable $e) {
            Log::warning('Unable to compute expected DB consumption (up Mbps)', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getPlansAssignedDownMbps(): float
    {
        try {
            return (float) Plan::query()->sum('download_speed');
        } catch (Throwable $e) {
            Log::warning('Unable to compute assigned Mbps by plans (down)', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function getPlansAssignedUpMbps(): float
    {
        try {
            return (float) Plan::query()->sum('upload_speed');
        } catch (Throwable $e) {
            Log::warning('Unable to compute assigned Mbps by plans (up)', [
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Fetches queue list from MikroTik and caches it for 60 seconds.
     * Returns null if MikroTik is unreachable, avoiding a blocking hang on every request.
     */
    private function getCachedQueueList(): ?array
    {
        return Cache::remember('mikrotik_queue_list', 60, function () {
            try {
                $queues = $this->mikrotik->getQueueList();
                return is_array($queues) ? $queues : null;
            } catch (Throwable $e) {
                Log::warning('MikroTik getQueueList failed (capacity snapshot)', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    public function getRouterUsedDownMbps(): ?float
    {
        $parents = $this->getCachedQueueList();
        if ($parents === null) {
            return null;
        }
        $sum = 0.0;
        foreach ($parents as $q) {
            $pair = (string) ($q['max-limit'] ?? $q['max_limit'] ?? '0/0');
            [, $down] = $this->parsePairMbps($pair);
            $sum += $down;
        }
        return $sum;
    }

    public function getRouterUsedUpMbps(): ?float
    {
        $parents = $this->getCachedQueueList();
        if ($parents === null) {
            return null;
        }
        $sum = 0.0;
        foreach ($parents as $q) {
            $pair = (string) ($q['max-limit'] ?? $q['max_limit'] ?? '0/0');
            [$up, ] = $this->parsePairMbps($pair);
            $sum += $up;
        }
        return $sum;
    }

    public function getCapacitySnapshot(): array
    {
        $totalDown = $this->getInventoryDownMbps();
        $totalUp = $this->getInventoryUpMbps();

        $plansAssignedDown = $this->getPlansAssignedDownMbps();
        $plansAssignedUp = $this->getPlansAssignedUpMbps();
        $plansRemainingDown = max(0.0, $totalDown - $plansAssignedDown);
        $plansRemainingUp = max(0.0, $totalUp - $plansAssignedUp);
        $plansPercentDown = $totalDown > 0 ? min(100.0, ($plansAssignedDown / $totalDown) * 100.0) : 0.0;
        $plansPercentUp = $totalUp > 0 ? min(100.0, ($plansAssignedUp / $totalUp) * 100.0) : 0.0;

        $expectedDown = $this->getDbExpectedUsedDownMbps();
        $expectedUp = $this->getDbExpectedUsedUpMbps();

        $routerDown = $this->getRouterUsedDownMbps();
        $routerUp = $this->getRouterUsedUpMbps();

        $usedDown = $routerDown === null ? $expectedDown : max($routerDown, $expectedDown);
        $usedUp = $routerUp === null ? $expectedUp : max($routerUp, $expectedUp);

        $remainingDown = max(0.0, $totalDown - $usedDown);
        $remainingUp = max(0.0, $totalUp - $usedUp);

        $percentDown = $totalDown > 0 ? min(100.0, ($usedDown / $totalDown) * 100.0) : 0.0;
        $percentUp = $totalUp > 0 ? min(100.0, ($usedUp / $totalUp) * 100.0) : 0.0;

        $clientsPercentDown = $totalDown > 0 ? min(100.0, ($expectedDown / $totalDown) * 100.0) : 0.0;
        $clientsPercentUp = $totalUp > 0 ? min(100.0, ($expectedUp / $totalUp) * 100.0) : 0.0;

        return [
            'total_down_mbps' => $totalDown,
            'used_down_mbps' => $usedDown,
            'remaining_down_mbps' => $remainingDown,
            'total_up_mbps' => $totalUp,
            'used_up_mbps' => $usedUp,
            'remaining_up_mbps' => $remainingUp,
            'percent_used' => $percentDown,
            'percent_used_down' => $percentDown,
            'percent_used_up' => $percentUp,
            'warn_80' => max($percentDown, $percentUp) >= 80.0,
            'reuse_ratio' => $this->getEffectiveReuseRatio(),
            'plans_assigned_down_mbps' => $plansAssignedDown,
            'plans_assigned_up_mbps' => $plansAssignedUp,
            'plans_remaining_down_mbps' => $plansRemainingDown,
            'plans_remaining_up_mbps' => $plansRemainingUp,
            'plans_percent_assigned_down' => $plansPercentDown,
            'plans_percent_assigned_up' => $plansPercentUp,
            'clients_expected_used_down_mbps' => $expectedDown,
            'clients_expected_used_up_mbps' => $expectedUp,
            'clients_percent_used_down' => $clientsPercentDown,
            'clients_percent_used_up' => $clientsPercentUp,
        ];
    }

    public function getNextClientDeltasByPlanId(array $planIds, ?array $counts = null): array
    {
        $counts = $counts ?? $this->getPlanActiveClientCounts();

        $plans = Plan::query()
            ->whereIn('id', $planIds)
            ->get(['id', 'download_speed', 'upload_speed', 'ratio']);

        $deltas = [];
        foreach ($plans as $plan) {
            $c = (int) ($counts[$plan->id] ?? 0);
            $reuse = $this->getPlanReuseRatio($plan);
            $deltas[$plan->id] = [
                'delta_down_mbps' => $this->calculateNextClientDeltaMbps((float) $plan->download_speed, $c, $reuse),
                'delta_up_mbps' => $this->calculateNextClientDeltaMbps((float) $plan->upload_speed, $c, $reuse),
                'active_clients' => $c,
            ];
        }
        return $deltas;
    }

    protected function parsePairMbps(string $pair): array
    {
        $parts = explode('/', $pair);
        $up = $this->toMbps(trim((string) ($parts[0] ?? '0')));
        $down = $this->toMbps(trim((string) ($parts[1] ?? '0')));
        return [$up, $down];
    }

    protected function toMbps(string $v): float
    {
        $raw = trim($v);
        if ($raw === '' || $raw === '0') return 0.0;

        if (preg_match('/^[0-9.]+$/', $raw)) {
            $num = (float) $raw;
            if ($num >= 10000) {
                return $num / 1000000.0;
            }
            return $num;
        }

        $suffix = strtoupper(substr($raw, -1));
        $num = (float) preg_replace('/[^0-9.]/', '', $raw);

        if ($suffix === 'G') return $num * 1000.0;
        if ($suffix === 'M') return $num;
        if ($suffix === 'K') return $num / 1000.0;
        return $num;
    }
}
