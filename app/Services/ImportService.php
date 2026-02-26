<?php

namespace App\Services;

use App\Models\ImportHistory;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Plan;
use App\Models\Wallet;
use App\Services\MikroTikQueueSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\UploadedFile;

class ImportService
{
    protected $mikrotikSync;

    public function __construct(MikroTikQueueSyncService $mikrotikSync)
    {
        $this->mikrotikSync = $mikrotikSync;
    }
    public function generateTemplate(string $tableName)
    {
        if (!Schema::hasTable($tableName)) {
            throw new \Exception("Table {$tableName} does not exist.");
        }

        $columns = Schema::getColumnListing($tableName);
        $headers = [];
        
        foreach ($columns as $column) {
            // Skip internal columns
            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            $headers[] = $column;
        }

        return $headers;
    }

    /**
     * Validate the uploaded CSV file.
     */
    public function validateImport(UploadedFile $file, string $tableName)
    {
        if (!Schema::hasTable($tableName)) {
            throw ValidationException::withMessages(['table' => "Table {$tableName} does not exist."]);
        }

        $rows = $this->parseCsv($file);

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => "The file is empty or invalid."]);
        }

        $errors = [];
        $validRows = [];
        $columns = Schema::getColumnListing($tableName);
        $requiredColumns = array_filter($columns, fn($c) => $this->isColumnRequired($tableName, $c) && !in_array($c, ['id', 'created_at', 'updated_at', 'deleted_at']));

        foreach ($rows as $index => $row) {
            $rowErrors = [];
            
            // Check required fields
            foreach ($requiredColumns as $col) {
                if (empty($row[$col])) {
                    $rowErrors[] = "Column '{$col}' is required.";
                }
            }
            
            if (!empty($rowErrors)) {
                $errors[] = [
                    'row' => $index + 2, // 1-based + header
                    'errors' => $rowErrors
                ];
            } else {
                $validRows[] = $row;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'preview' => array_slice($validRows, 0, 5),
            'total_rows' => count($rows),
            'valid_count' => count($validRows),
            'error_count' => count($errors)
        ];
    }

    /**
     * Process the import and save to database.
     */
    public function processImport(UploadedFile $file, string $tableName, int $employeeId, array $planAssignments = [])
    {
        $validation = $this->validateImport($file, $tableName);

        if (!$validation['valid']) {
             throw ValidationException::withMessages(['file' => "File contains errors. Please fix them before importing."]);
        }

        $rows = $this->parseCsv($file);
        $createdIds = [];
        $summary = [
            'total' => count($rows),
            'success' => 0,
            'failed' => 0
        ];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $validData = array_intersect_key($row, array_flip(Schema::getColumnListing($tableName)));
                
                // Convert dates if necessary
                if (isset($validData['contract_date']) && !empty($validData['contract_date'])) {
                    try {
                        // Try to parse d/m/Y or d-m-Y to Y-m-d
                        $date = \DateTime::createFromFormat('d/m/Y', $validData['contract_date']);
                        if (!$date) $date = \DateTime::createFromFormat('d-m-Y', $validData['contract_date']);
                        if (!$date) $date = \DateTime::createFromFormat('j/n/Y', $validData['contract_date']);
                        
                        if ($date) {
                            $validData['contract_date'] = $date->format('Y-m-d');
                        }
                    } catch (\Exception $e) {
                        // Keep original value if parsing fails
                    }
                }

                if (Schema::hasColumn($tableName, 'created_at')) {
                    $validData['created_at'] = now();
                    $validData['updated_at'] = now();
                }

                $id = DB::table($tableName)->insertGetId($validData);
                $createdIds[] = $id;
                $summary['success']++;

                // Lógica de sincronización con Mikrotik y Asignación de Planes
                if ($tableName === 'clients') {
                    $this->syncClientToMikrotik($id);
                    
                    // Si hay un plan asignado para esta fila (index 0-based)
                    if (isset($planAssignments[$index])) {
                        $this->assignPlanToClient($id, $planAssignments[$index]);
                    }

                } elseif ($tableName === 'plans') {
                    $this->syncPlanToMikrotik($id);
                } elseif ($tableName === 'clients_plans') {
                    $this->syncClientPlanToMikrotik($id);
                }
            }

            // Check if employee exists before assigning
            $employeeExists = DB::table('employees')->where('id', $employeeId)->exists();

            ImportHistory::create([
                'employee_id' => $employeeExists ? $employeeId : null,
                'table_name' => $tableName,
                'file_name' => $file->getClientOriginalName(),
                'status' => 'success',
                'summary' => $summary,
                'created_ids' => $createdIds,
            ]);

            DB::commit();

            return [
                'success' => true,
                'summary' => $summary
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Import failed: " . $e->getMessage());
            
            // Check if employee exists before assigning for error log too
            $employeeExists = DB::table('employees')->where('id', $employeeId)->exists();

            ImportHistory::create([
                'employee_id' => $employeeExists ? $employeeId : null,
                'table_name' => $tableName,
                'file_name' => $file->getClientOriginalName(),
                'status' => 'failed',
                'summary' => $summary,
                'errors' => [['error' => $e->getMessage()]],
            ]);

            throw $e;
        }
    }

    private function assignPlanToClient($clientId, $planId)
    {
        try {
            $client = Client::find($clientId);
            $plan = Plan::find($planId);

            if ($client && $plan) {
                // Crear relación en clients_plans
                $clientPlan = ClientPlan::create([
                    'client_id' => $client->id,
                    'plan_id' => $plan->id,
                    'status' => 'active', // Asumimos activo por defecto al importar
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Sincronizar con Mikrotik
                $this->mikrotikSync->createClientAndQueue($client, $clientPlan, $plan);
            }
        } catch (\Exception $e) {
            Log::error("Error assigning plan {$planId} to client {$clientId} during import: " . $e->getMessage());
        }
    }

    private function syncClientToMikrotik($clientId)
    {
        try {
            $client = Client::find($clientId);
            if (!$client) return;

            // Crear Wallet si no existe
            if (!Wallet::where('client_id', $client->id)->exists()) {
                Wallet::create(['client_id' => $client->id, 'balance' => 0.00, 'status' => 'active']);
            }
            
            // Nota: No podemos sincronizar con Mikrotik aquí porque falta el Plan.
            // La sincronización ocurrirá cuando se importen los datos en 'client_plans'.
            
        } catch (\Exception $e) {
            Log::error("Error syncing client {$clientId} auxiliary data: " . $e->getMessage());
        }
    }

    private function syncClientPlanToMikrotik($clientPlanId)
    {
        try {
            $clientPlan = ClientPlan::with(['client', 'plan'])->find($clientPlanId);
            
            if ($clientPlan && $clientPlan->client && $clientPlan->plan) {
                // Sincronizar con Mikrotik usando el servicio existente
                $this->mikrotikSync->createClientAndQueue(
                    $clientPlan->client,
                    $clientPlan,
                    $clientPlan->plan
                );
            }
        } catch (\Exception $e) {
            Log::error("Error syncing client_plan {$clientPlanId} to Mikrotik: " . $e->getMessage());
        }
    }

    private function syncPlanToMikrotik($planId)
    {
        try {
            $plan = Plan::find($planId);
            if ($plan) {
                $this->mikrotikSync->ensurePlanQueue($plan);
            }
        } catch (\Exception $e) {
            Log::error("Error syncing plan {$planId} to Mikrotik: " . $e->getMessage());
        }
    }

    public function rollbackImport(int $historyId)
    {
        $history = ImportHistory::findOrFail($historyId);

        if ($history->status !== 'success' || empty($history->created_ids)) {
            throw new \Exception("Cannot rollback this import.");
        }

        DB::beginTransaction();
        try {
            DB::table($history->table_name)->whereIn('id', $history->created_ids)->delete();
            $history->update(['status' => 'rolled_back']);
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function parseCsv(UploadedFile $file)
    {
        $path = $file->getRealPath();
        
        // Detect delimiter
        $handle = fopen($path, "r");
        $firstLine = fgets($handle);
        fclose($handle);
        
        $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';

        $data = [];
        if (($handle = fopen($path, "r")) !== FALSE) {
            // Skip BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                $data[] = $row;
            }
            fclose($handle);
        }
        
        if (count($data) < 2) return [];

        $header = array_map('trim', $data[0]);
        // Remove BOM if present (legacy check)
        $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
        
        $rows = [];
        for ($i = 1; $i < count($data); $i++) {
            if (count($header) === count($data[$i])) {
                $rows[] = array_combine($header, $data[$i]);
            }
        }
        
        return $rows;
    }

    private function isColumnRequired($table, $column)
    {
        return false; 
    }
}
