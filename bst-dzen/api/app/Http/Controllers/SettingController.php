<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public const DEFAULTS = [
        'llm' => ['models' => ['classify' => null, 'rewrite' => null, 'evergreen' => null, 'rules' => null, 'default' => null]],
        'parsers' => [],
        'publish_modes' => [
            'evergreen' => 'publish',
            'site_rewrite' => 'publish',
            'external_rewrite' => 'draft',
            'original' => 'draft',
        ],
        'content' => ['daily_limit' => 4, 'max_per_day' => 8],
    ];

    public function index(): JsonResponse
    {
        $stored = Setting::whereNull('own_channel_id')->get()->keyBy('key');

        $merged = [];
        foreach (self::DEFAULTS as $key => $default) {
            $merged[$key] = $stored->has($key)
                ? array_merge(is_array($default) ? $default : [], (array) $stored[$key]->value)
                : $default;
        }

        return response()->json($merged);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'in:' . implode(',', array_keys(self::DEFAULTS))],
            'value' => ['required', 'array'],
        ]);

        Setting::updateOrCreate(
            ['key' => $data['key'], 'own_channel_id' => null],
            ['value' => $data['value']],
        );

        return response()->json(['status' => 'ok']);
    }
}
