<?php

namespace App\Http\Controllers;

use App\Models\Setting;

class SettingController extends Controller
{
    /**
     * Return only is_public settings as a flat key => typed_value map.
     * Safe for unauthenticated or client-authenticated requests.
     */
    public function public()
    {
        $settings = Setting::public()
            ->get()
            ->mapWithKeys(fn (Setting $s) => [$s->key => $s->typed_value]);

        return response()->json($settings);
    }
}
