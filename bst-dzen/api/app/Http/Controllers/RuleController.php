<?php

namespace App\Http\Controllers;

use App\Models\RuleVersion;
use App\Services\Feedback\PublicationTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $versions = RuleVersion::query()
            ->when($request->integer('own'), fn ($q, $own) => $q->where(
                fn ($w) => $w->whereNull('own_channel_id')->orWhere('own_channel_id', $own),
            ))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'versions' => $versions,
            'pattern_stats' => PublicationTracker::patternStats($request->integer('own', 1)),
        ]);
    }

    public function activate(RuleVersion $rule): JsonResponse
    {
        // активна может быть только одна версия в слое
        RuleVersion::where('layer', $rule->layer)
            ->where(fn ($q) => $rule->own_channel_id
                ? $q->where('own_channel_id', $rule->own_channel_id)
                : $q->whereNull('own_channel_id'))
            ->whereKeyNot($rule->id)
            ->update(['is_active' => false]);

        $rule->update(['is_active' => true, 'activated_at' => now()]);

        return response()->json($rule->fresh());
    }
}
