<?php

namespace App\Services;

use App\Models\ClientPlan;
use App\Models\IspConnection;
use App\Models\Plan;
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

    public function getRouterUsedDownMbps(): ?float
    {
        try {
            $parents = $this->mikrotik->getQueueList();
            $sum = 0.0;
            foreach ($parents as $q) {
                $pair = (string) ($q['max-limit'] ?? $q['max_limit'] ?? '0/0');
                [, $down] = $this->parsePairMbps($pair);
                $sum += $down;
            }
            return $sum;
        } catch (Throwable $e) {
            Log::warning('Unable to compute router queue consumption', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getRouterUsedUpMbps(): ?float
    {
        try {
            $parents = $this->mikrotik->getQueueList();
            $sum = 0.0;
            foreach ($parents as $q) {
                $pair = (string) ($q['max-limit'] ?? $q['max_limit'] ?? '0/0');
                [$up, ] = $this->parsePairMbps($pair);
                $sum += $up;
            }
            return $sum;
        } catch (Throwable $e) {
            Log::warning('Unable to compute router queue consumption (up)', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getCapacitySnapshot(): array
    {
        $totalDown = $this->getInventoryDownMbps();
        $totalUp = $this->getInventoryUpMbps();

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
