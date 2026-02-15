<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Plan;
use App\Models\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;
use Throwable;

class MikroTikQueueSyncService
{
    public function __construct(protected MikroTikService $mikrotik) {}

    /**
     * Helper to log MikroTik audits manually since this is not an Eloquent model.
     */
    protected function auditMikroTik(string $operation, string $recordId, ?array $oldValues, ?array $newValues): void
    {
        try {
            Audit::create([
                'table_name' => 'mikrotik_queues',
                'operation' => $operation,
                'record_id' => $recordId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'user_id' => Auth::id(), // Might be null if run from CLI/Job
                'ip_address' => Request::ip() ?? '127.0.0.1',
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to audit MikroTik action: ' . $e->getMessage());
        }
    }

    public function normalizeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
        return trim($name, '_');
    }

    public function formatSpeedPair(int $uploadMbps, int $downloadMbps): string
    {
        return $uploadMbps . 'M/' . $downloadMbps . 'M';
    }

    protected function normalizePlanQueueName(Plan $plan): string
    {
        $name = trim((string) ($plan->mikrotik_queue_name ?: $plan->name ?: $plan->slug ?: ''));
        return $name === '' ? 'plan_' . $plan->id : $name;
    }

    /* Funcion funcional */
    protected function normalizeClientQueueName(Client $client): string
    {
        $name = trim((string) ($client->full_name ?: 'cliente_' . $client->id));
        $name = strtolower($name);
        $name = preg_replace('/\s+/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');
        $documentId = trim((string) ($client->document_id ?? ''));
        if ($documentId !== '') {
            return $name . '_' . $documentId;
        }
        return $name;
    }

    protected function normalizeSpeedValue(?string $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || $raw === '0') {
            return '0';
        }
        $raw = strtoupper(str_replace(' ', '', $raw));
        if (preg_match('/^\d+(\.\d+)?[KMG]$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return $raw . 'M';
        }
        $raw = str_replace(['KB', 'MB', 'GB'], ['K', 'M', 'G'], $raw);
        return $raw;
    }

    protected function formatSpeedPairFromStrings(string $upload, string $download): string
    {
        $u = $this->normalizeSpeedValue($upload);
        $d = $this->normalizeSpeedValue($download);
        return $u . '/' . $d;
    }

    protected function formatMbps(float $mbps): string
    {
        if ($mbps <= 0) {
            return '0';
        }
        if ($mbps < 1) {
            $kbps = (int) round($mbps * 1000);
            return max(1, $kbps) . 'K';
        }
        if ($mbps >= 1000) {
            $g = round($mbps / 1000, 2);
            $text = rtrim(rtrim((string) $g, '0'), '.');
            return $text . 'G';
        }
        $m = round($mbps, 2);
        $text = rtrim(rtrim((string) $m, '0'), '.');
        return $text . 'M';
    }

    protected function calculatePercentSpeed(string $value, float $percent): string
    {
        $mbps = $this->toMbps($this->normalizeSpeedValue($value));
        $result = $mbps * ($percent / 100);
        return $this->formatMbps($result);
    }

    protected function buildPlanTargetIps(Plan $plan): array
    {
        $ips = [];
        $list = ClientPlan::query()
            ->with('client')
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->get();
        foreach ($list as $cp) {
            $ip = $cp->ip_address ?: $cp->client?->ip;
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[$ip] = true;
            }
        }
        $targets = array_keys($ips);
        sort($targets);
        return $targets;
    }

    protected function buildPlanQueueParams(Plan $plan, array $targets): array
    {
        $upload = $plan->upload_limit ?: ($plan->upload_speed ? $plan->upload_speed . 'M' : '0');
        $download = $plan->download_limit ?: ($plan->download_speed ? $plan->download_speed . 'M' : '0');
        $target = count($targets) ? implode(',', $targets) : '0.0.0.0/0';
        return [
            'name' => $this->normalizePlanQueueName($plan),
            'target' => $target,
            'parent' => 'none',
            'max-limit' => $this->formatSpeedPairFromStrings($upload, $download),
            'limit-at' => $this->formatSpeedPairFromStrings($upload, $download),
        ];
    }

    protected function buildClientQueueParams(Client $client, Plan $plan, string $parentName, string $ip): array
    {
        $upload = $plan->upload_limit ?: ($plan->upload_speed ? $plan->upload_speed . 'M' : '0');
        $download = $plan->download_limit ?: ($plan->download_speed ? $plan->download_speed . 'M' : '0');
        $maxLimit = $this->formatSpeedPairFromStrings($upload, $download);
        $limitAt = $this->formatSpeedPairFromStrings(
            $this->calculatePercentSpeed($upload, 10),
            $this->calculatePercentSpeed($download, 10)
        );
        return [
            'name' => $this->normalizeClientQueueName($client),
            'target' => $ip,
            'parent' => $parentName,
            'max-limit' => $maxLimit,
            'limit-at' => $limitAt,
        ];
    }

    protected function upsertQueue(string $name, array $params): array
    {
        $existing = $this->findQueueByName($name);
        if (!$existing) {
            $created = $this->withRetries(function () use ($params) {
                return $this->createSimpleQueue($params);
            });
            $this->auditMikroTik('INSERT', $name, null, $params);
            return [
                'action' => 'created',
                'name' => $name,
                'queue' => $this->findQueueByName($name),
                'router_result' => $created,
            ];
        }
        $changes = [];
        foreach ($params as $key => $value) {
            if ($key === 'name') {
                continue;
            }
            $current = $existing[$key] ?? null;
            if ((string) $current !== (string) $value) {
                $changes[$key] = ['from' => $current, 'to' => $value];
            }
        }
        if (!$changes) {
            return [
                'action' => 'skipped',
                'name' => $name,
                'queue' => $existing,
            ];
        }
        $set = new Query('/queue/simple/set');
        $set->equal('.id', $existing['.id']);
        foreach ($params as $key => $value) {
            if ($key === 'name') {
                continue;
            }
            $set->equal($key, $value);
        }
        $result = $this->mikrotik->runQuery($set);
        $this->auditMikroTik('UPDATE', $name, $existing, $params);
        return [
            'action' => 'updated',
            'name' => $name,
            'changes' => $changes,
            'queue' => $this->findQueueByName($name),
            'router_result' => $result,
        ];
    }

    public function syncQueues(bool $cleanup = false): array
    {
        if (!$this->mikrotik->getClient()) {
            throw new \RuntimeException('MikroTik no conectado: verifica MIKROTIK_HOST/USER/PASS y permisos');
        }
        $planResults = [];
        $clientResults = [];
        $expectedPlanNames = [];
        $expectedClientNames = [];
        $plans = Plan::query()->where('is_active', true)->get();
        foreach ($plans as $plan) {
            $targets = $this->buildPlanTargetIps($plan);
            $params = $this->buildPlanQueueParams($plan, $targets);
            $name = $params['name'];
            $expectedPlanNames[] = $name;
            $planResults[] = [
                'plan_id' => $plan->id,
                'name' => $name,
                'result' => $this->upsertQueue($name, $params),
            ];
        }
        $list = ClientPlan::query()
            ->with(['client', 'plan'])
            ->where('status', 'active')
            ->get();
        foreach ($list as $cp) {
            $client = $cp->client;
            $plan = $cp->plan;
            if (!$client || !$plan) {
                $clientResults[] = [
                    'client_plan_id' => $cp->id,
                    'error' => 'Relaciones faltantes',
                ];
                continue;
            }
            $ip = $cp->ip_address ?: $client->ip;
            if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $clientResults[] = [
                    'client_id' => $client->id,
                    'plan_id' => $plan->id,
                    'ip' => $ip,
                    'skipped_reason' => 'IP inválida o vacía',
                ];
                continue;
            }
            $parentName = $this->normalizePlanQueueName($plan);
            $params = $this->buildClientQueueParams($client, $plan, $parentName, $ip);
            $name = $params['name'];
            $expectedClientNames[] = $name;
            $clientResults[] = [
                'client_id' => $client->id,
                'plan_id' => $plan->id,
                'name' => $name,
                'result' => $this->upsertQueue($name, $params),
            ];
        }
        $cleanupResult = null;
        if ($cleanup) {
            $cleanupResult = $this->cleanupQueues($expectedPlanNames, $expectedClientNames);
        }
        return [
            'plans' => $planResults,
            'clients' => $clientResults,
            'cleanup' => $cleanupResult,
        ];
    }

    protected function cleanupQueues(array $expectedPlanNames, array $expectedClientNames): array
    {
        $query = new Query('/queue/simple/print');
        $query->equal('.proplist', '.id,name,parent');
        $queues = $this->withRetries(function () use ($query) {
            return $this->mikrotik->runQuery($query);
        });
        $deleted = [];
        foreach ($queues as $queue) {
            $name = $queue['name'] ?? '';
            $parent = $queue['parent'] ?? 'none';
            if ($parent !== 'none' && in_array($parent, $expectedPlanNames, true)) {
                if (!in_array($name, $expectedClientNames, true)) {
                    if (!empty($queue['.id'])) {
                        $del = new Query('/queue/simple/remove');
                        $del->equal('.id', $queue['.id']);
                        $this->mikrotik->runQuery($del);
                        $deleted[] = $name;
                        $this->auditMikroTik('DELETE', $name, $queue, null);
                    }
                }
            }
            if ($parent === 'none' && !in_array($name, $expectedPlanNames, true)) {
                if (!empty($queue['.id'])) {
                    $del = new Query('/queue/simple/remove');
                    $del->equal('.id', $queue['.id']);
                    $this->mikrotik->runQuery($del);
                    $deleted[] = $name;
                    $this->auditMikroTik('DELETE', $name, $queue, null);
                }
            }
        }
        return [
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
        ];
    }

    protected function parsePair(string $pair): array
    {
        $parts = explode('/', $pair);
        $u = $this->toMbps(trim($parts[0] ?? '0'));
        $d = $this->toMbps(trim($parts[1] ?? '0'));
        return [$u, $d];
    }

    protected function toMbps(string $v): float
    {
        if ($v === '' || $v === '0') return 0.0;
        $suffix = strtoupper(substr($v, -1));
        $num = (float) preg_replace('/[^0-9.]/', '', $v);
        if ($suffix === 'G') return $num * 1000.0;
        if ($suffix === 'M') return $num;
        if ($suffix === 'K') return $num / 1000.0;
        return $num; // assume Mbps
    }

    protected function normalizeBurst(string $burst, string $max): string
    {
        [$bu, $bd] = $this->parsePair($burst);
        [$mu, $md] = $this->parsePair($max);
        $nu = max($bu, $mu);
        $nd = max($bd, $md);
        return (int) round($nu) . 'M/' . (int) round($nd) . 'M';
    }

    public function findQueueByName(string $name): ?array
    {
        $query = new Query('/queue/simple/print');
        $query->where('name', $name);
        $result = $this->mikrotik->runQuery($query);
        return $result[0] ?? null;
    }

    public function createSimpleQueue(array $params): array
    {
        $query = new Query('/queue/simple/add');
        foreach ($params as $key => $value) {
            $query->equal($key, $value);
        }
        return $this->mikrotik->runQuery($query);
    }

    public function deleteQueueByName(string $name): void
    {
        $queue = $this->findQueueByName($name);
        if (!$queue || empty($queue['.id'])) {
            return;
        }
        $del = new Query('/queue/simple/remove');
        $del->equal('.id', $queue['.id']);
        $this->mikrotik->runQuery($del);

        $this->auditMikroTik('DELETE', $name, $queue, null);
    }

    public function ensurePlanQueue(Plan $plan): array
    {
        if (!$this->mikrotik->getClient()) {
            throw new \RuntimeException('MikroTik no conectado: verifica MIKROTIK_HOST/USER/PASS y permisos');
        }
        $start = microtime(true);
        $normalized = $this->normalizeName($plan->mikrotik_queue_name ?: $plan->slug ?: $plan->name);
        $existing = $this->findQueueByName($normalized);

        if ($existing) {
            $valid = $this->validatePlanQueue($plan, $existing);
            Log::info('Plan queue validated', [
                'plan_id' => $plan->id,
                'queue_name' => $normalized,
                'valid' => $valid,
                'duration_ms' => (microtime(true) - $start) * 1000,
            ]);
            return [
                'action' => 'validated',
                'valid' => $valid,
                'name' => $normalized,
                'queue' => $existing,
            ];
        }

        $params = [
            'name' => $normalized,
            'target' => '0.0.0.0/0',
            'parent' => 'none',
            'priority' => (string) max(1, min(8, (int) $plan->priority)),
            'max-limit' => $this->formatSpeedPair(
                (int) $plan->upload_speed,
                (int) $plan->download_speed
            ),
            'limit-at' => $this->formatSpeedPair(
                (int) $plan->upload_speed,
                (int) $plan->download_speed
            ),
        ];

        if (!empty($plan->burst_limit)) {
            $params['burst-limit'] = $this->normalizeBurst($plan->burst_limit, $params['max-limit']);
            $params['burst-threshold'] = $params['max-limit'];
            $params['burst-time'] = '8s/8s';
        }

        $created = $this->withRetries(function () use ($params) {
            return $this->createSimpleQueue($params);
        });

        $this->auditMikroTik('INSERT', $normalized, null, $params);

        Log::info('Plan queue created', [
            'plan_id' => $plan->id,
            'queue_name' => $normalized,
            'params' => $params,
            'router_result' => $created,
            'duration_ms' => (microtime(true) - $start) * 1000,
        ]);

        $plan->update(['mikrotik_queue_name' => $normalized]);

        $createdQueue = $this->findQueueByName($normalized);
        if (!$createdQueue) {
            Log::warning('Plan queue not found after creation', [
                'plan_id' => $plan->id,
                'queue_name' => $normalized,
            ]);
            return [
                'action' => 'failed',
                'name' => $normalized,
                'queue' => null,
                'reason' => 'Queue no visible tras creación. Verifica conexión/credenciales.',
            ];
        }
        return [
            'action' => 'created',
            'name' => $normalized,
            'queue' => $createdQueue,
        ];
    }

    public function validatePlanQueue(Plan $plan, array $queue): bool
    {
        $priorityOk = ((string) ($queue['priority'] ?? '8')) === (string) max(1, min(8, (int) $plan->priority));
        $expectedMax = $this->formatSpeedPair(
            (int) $plan->upload_speed,
            (int) $plan->download_speed
        );
        $maxLimitOk = ($queue['max-limit'] ?? '') === $expectedMax;
        $limitAtOk = ($queue['limit-at'] ?? '') === $expectedMax;

        $burstOk = true;
        if (!empty($plan->burst_limit)) {
            $expectedBurst = $this->normalizeBurst($plan->burst_limit, $expectedMax);
            $burstOk = ($queue['burst-limit'] ?? '') === $expectedBurst
                && ($queue['burst-threshold'] ?? '') === $expectedMax
                && ($queue['burst-time'] ?? '') === '8s/8s';
        }

        return $priorityOk && $maxLimitOk && $limitAtOk && $burstOk;
    }

    public function createClientAndQueue(Client $client, ?ClientPlan $clientPlan, Plan $plan): array
    {
        if (!$this->mikrotik->getClient()) {
            throw new \RuntimeException('MikroTik no conectado: verifica MIKROTIK_HOST/USER/PASS y permisos');
        }
        $start = microtime(true);
        $queueName = $this->normalizeName('CLIENTE_' . $client->id . '_' . $client->document_id);
        try {
        return DB::transaction(function () use ($client, $clientPlan, $plan, $start, $queueName) {
            $planEnsured = $this->ensurePlanQueue($plan);

            if ($this->findQueueByName($queueName)) {
                Log::warning('Client queue already exists', [
                    'client_id' => $client->id,
                    'queue_name' => $queueName,
                ]);
                return [
                    'success' => true,
                    'message' => 'Queue ya existe',
                    'queue_name' => $queueName,
                    'plan_queue' => $planEnsured,
                ];
            }

            $params = [
                'name' => $queueName,
                'target' => $clientPlan?->ip_address ?: $client->ip ?: '0.0.0.0',
                'parent' => $planEnsured['name'],
                'priority' => (string) max(1, min(8, (int) $plan->priority)),
                'max-limit' => $this->formatSpeedPair(
                    (int) ($plan->upload_speed),
                    (int) ($plan->download_speed)
                ),
                'limit-at' => $this->formatSpeedPair(
                    (int) ($plan->upload_speed),
                    (int) ($plan->download_speed)
                ),
            ];

            if (!empty($plan->burst_limit)) {
                $params['burst-limit'] = $this->normalizeBurst($plan->burst_limit, $params['max-limit']);
                $params['burst-threshold'] = $params['max-limit'];
                $params['burst-time'] = '8s/8s';
            }

            $routerResult = null;
            try {
                $routerResult = $this->withRetries(function () use ($params) {
                    return $this->createSimpleQueue($params);
                });
            } catch (Throwable $e) {
                Log::error('Crear queue cliente fallo', [
                    'client_id' => $client->id,
                    'queue_name' => $queueName,
                    'params' => $params,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            if ($clientPlan) {
                $clientPlan->update([
                    'mikrotik_queue_id' => $queueName,
                ]);
            }

            // Agregar IP del cliente al target del plan
            $clientIp = $clientPlan?->ip_address ?: $client->ip;
            if ($clientIp && filter_var($clientIp, FILTER_VALIDATE_IP)) {
                $this->appendIpToPlanTarget($planEnsured['name'], $clientIp);
            }

            Log::info('Cliente y queue creados', [
                'client_id' => $client->id,
                'queue_name' => $queueName,
                'plan_queue' => $planEnsured['name'],
                'router_result' => $routerResult,
                'duration_ms' => (microtime(true) - $start) * 1000,
            ]);

            return [
                'success' => true,
                'message' => 'Cliente sincronizado con MikroTik',
                'queue_name' => $queueName,
                'plan_queue_name' => $planEnsured['name'],
            ];
        });
        } catch (Throwable $e) {
            try {
                $this->deleteQueueByName($queueName);
                Log::warning('Rollback: queue eliminada por fallo de BD', [
                    'client_id' => $client->id,
                    'queue_name' => $queueName,
                ]);
            } catch (Throwable $te) {
                Log::error('Rollback: error eliminando queue en MikroTik', [
                    'client_id' => $client->id,
                    'queue_name' => $queueName,
                    'error' => $te->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    protected function appendIpToPlanTarget(string $planQueueName, string $ip): void
    {
        try {
            $queue = $this->findQueueByName($planQueueName);
            if (!$queue || empty($queue['.id'])) {
                return;
            }
            $current = trim($queue['target'] ?? '');
            $targets = array_filter(array_map('trim', $current !== '' ? explode(',', $current) : []));
            if (!in_array($ip, $targets, true)) {
                $targets[] = $ip;
            }
            $newTarget = implode(',', $targets);
            if ($newTarget === '') {
                $newTarget = $ip;
            }
            $set = new Query('/queue/simple/set');
            $set->equal('.id', $queue['.id']);
            $set->equal('target', $newTarget);
            $this->mikrotik->runQuery($set);

            $this->auditMikroTik('UPDATE', $planQueueName, ['target' => $current], ['target' => $newTarget]);

            Log::info('Plan queue target updated', [
                'queue_name' => $planQueueName,
                'old_target' => $current,
                'new_target' => $newTarget,
                'added_ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            Log::error('Append IP to plan target failed', [
                'queue_name' => $planQueueName,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function withRetries(callable $fn)
    {
        $delays = [2, 4, 8];
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (Throwable $e) {
                if ($attempt >= 2) {
                    Log::error('Retry failed after attempts', [
                        'attempts' => $attempt + 1,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e;
                }
                sleep($delays[$attempt]);
                $attempt++;
            }
        }
    }

    public function syncAllClients(): array
    {
        $items = [];
        $processed = 0;
        $created = 0;
        $skipped = 0;
        $errors = 0;
        $plansMap = [];
        $list = \App\Models\ClientPlan::query()
            ->with(['client', 'plan'])
            ->where('status', 'active')
            ->get();
        foreach ($list as $cp) {
            $processed++;
            $client = $cp->client;
            $plan = $cp->plan;
            if (!$client || !$plan) {
                $errors++;
                $items[] = [
                    'client_plan_id' => $cp->id,
                    'error' => 'Relaciones faltantes',
                ];
                continue;
            }
            $ip = $cp->ip_address ?: $client->ip;
            if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $skipped++;
                $items[] = [
                    'client_id' => $client->id,
                    'document_id' => $client->document_id,
                    'plan_id' => $plan->id,
                    'ip' => $ip,
                    'skipped_reason' => 'IP inválida o vacía',
                ];
                continue;
            }
            try {
                if (!isset($plansMap[$plan->id])) {
                    $plansMap[$plan->id] = $this->ensurePlanQueue($plan);
                }
                $res = $this->createClientAndQueue($client, $cp, $plan);
                $created++;
                $items[] = [
                    'client_id' => $client->id,
                    'document_id' => $client->document_id,
                    'ip' => $ip,
                    'plan_id' => $plan->id,
                    'result' => $res,
                ];
            } catch (Throwable $e) {
                $errors++;
                $items[] = [
                    'client_id' => $client->id,
                    'document_id' => $client->document_id,
                    'ip' => $ip,
                    'plan_id' => $plan->id,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return [
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'errors_count' => $errors,
            'items' => $items,
        ];
    }
}
