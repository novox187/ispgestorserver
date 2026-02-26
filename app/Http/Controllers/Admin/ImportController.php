<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportHistory;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ImportController extends Controller
{
    protected $importService;

    public function __construct(ImportService $importService)
    {
        $this->importService = $importService;
    }

    public function downloadTemplate(Request $request, $table)
    {
        if (!Schema::hasTable($table)) {
            return response()->json(['message' => 'Table not found'], 404);
        }

        $headers = $this->importService->generateTemplate($table);
        $filename = "template_{$table}.csv";

        return response()->streamDownload(function () use ($headers) {
            $handle = fopen('php://output', 'w');
            
            // Add BOM for Excel compatibility with UTF-8
            fwrite($handle, "\xEF\xBB\xBF");
            
            // Use semicolon as separator for better Excel compatibility in some regions
            fputcsv($handle, $headers, ';');
            
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function validateImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'table' => 'required|string',
        ]);

        try {
            $result = $this->importService->validateImport($request->file('file'), $request->table);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function processImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'table' => 'required|string',
            'plan_assignments' => 'nullable|string'
        ]);

        try {
            $planAssignments = [];
            if ($request->has('plan_assignments')) {
                $planAssignments = json_decode($request->plan_assignments, true);
            }

            $result = $this->importService->processImport(
                $request->file('file'), 
                $request->table, 
                $request->user()->id,
                $planAssignments
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function history()
    {
        $history = ImportHistory::with('employee')->latest()->paginate(10);
        return response()->json($history);
    }

    public function rollback($id)
    {
        try {
            $this->importService->rollbackImport($id);
            return response()->json(['message' => 'Rollback successful']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
