<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * Return settings, optionally filtered by module.
     * GET /admin/settings?module=facturacion
     */
    public function index(Request $request)
    {
        $query = Setting::query();

        if ($module = $request->query('module')) {
            $query->module($module);
        }

        $settings = $query->orderBy('module')->orderBy('group')->orderBy('key')->get();

        return response()->json($settings);
    }

    /**
     * Create a new setting.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'module'      => ['nullable', 'string', 'max:64'],
            'group'       => ['nullable', 'string', 'max:64'],
            'key'         => ['required', 'string', 'max:128', 'unique:system_settings,key'],
            'value'       => ['nullable'],
            'data_type'   => ['required', Rule::in(['string', 'integer', 'float', 'boolean', 'json', 'text'])],
            'description' => ['nullable', 'string'],
            'is_public'   => ['required', 'boolean'],
        ]);

        if (is_array($data['value'] ?? null)) {
            $data['value'] = json_encode($data['value']);
        }

        return response()->json(Setting::create($data), 201);
    }

    /**
     * Update an existing setting.
     */
    public function update(Request $request, Setting $setting)
    {
        $data = $request->validate([
            'module'      => ['nullable', 'string', 'max:64'],
            'group'       => ['nullable', 'string', 'max:64'],
            'key'         => ['sometimes', 'string', 'max:128', Rule::unique('system_settings', 'key')->ignore($setting->id)],
            'value'       => ['nullable'],
            'data_type'   => ['sometimes', Rule::in(['string', 'integer', 'float', 'boolean', 'json', 'text'])],
            'description' => ['nullable', 'string'],
            'is_public'   => ['sometimes', 'boolean'],
        ]);

        if (is_array($data['value'] ?? null)) {
            $data['value'] = json_encode($data['value']);
        }

        $setting->update($data);

        return response()->json($setting);
    }

    /**
     * Bulk upsert scoped to a module.
     * Body: [{ key, value, module, group?, data_type?, description?, is_public? }, ...]
     */
    public function bulkUpdate(Request $request)
    {
        $items = $request->validate([
            '*.key'         => ['required', 'string', 'max:128'],
            '*.value'       => ['nullable'],
            '*.module'      => ['nullable', 'string', 'max:64'],
            '*.group'       => ['nullable', 'string', 'max:64'],
            '*.data_type'   => ['nullable', Rule::in(['string', 'integer', 'float', 'boolean', 'json', 'text'])],
            '*.description' => ['nullable', 'string'],
            '*.is_public'   => ['nullable', 'boolean'],
        ]);

        foreach ($items as $item) {
            $value = $item['value'];
            if (is_array($value)) {
                $value = json_encode($value);
            }

            Setting::updateOrCreate(
                ['key' => $item['key']],
                array_filter([
                    'value'       => (string) ($value ?? ''),
                    'module'      => $item['module'] ?? null,
                    'group'       => $item['group'] ?? null,
                    'data_type'   => $item['data_type'] ?? null,
                    'description' => $item['description'] ?? null,
                    'is_public'   => $item['is_public'] ?? null,
                ], fn ($v) => $v !== null)
            );
        }

        Setting::flushCache();

        return response()->json(['message' => 'Configuraciones actualizadas']);
    }

    /**
     * Delete a setting.
     */
    public function destroy(Setting $setting)
    {
        $setting->delete();
        return response()->json(['message' => 'Configuración eliminada']);
    }
}
