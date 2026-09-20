<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\Analysis\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, MetricsService $metrics): JsonResponse
    {
        $own = $request->integer('own')
            ? Channel::own()->findOrFail($request->integer('own'))
            : Channel::own()->firstOrFail();

        $days = min(max($request->integer('days', 21), 7), 90);

        return response()->json($metrics->overview($own, $days));
    }
}
