<?php

namespace App\Http\Controllers;

use App\Models\OwnPublication;
use App\Models\SourceMaterial;
use App\Services\Content\Rewriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = OwnPublication::with('ownChannel:id,dzen_key,title')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('own'), fn ($q, $own) => $q->where('own_channel_id', $own))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->integer('per_page', 30)));
    }

    public function show(OwnPublication $draft): JsonResponse
    {
        return response()->json($draft->load('ownChannel:id,dzen_key,title'));
    }

    /** Правка редактором: заголовок (в т.ч. выбор варианта), тело, рубрика */
    public function update(OwnPublication $draft, Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'body' => ['sometimes', 'string'],
            'rubric' => ['sometimes', 'string', 'max:64'],
            'title_variant' => ['sometimes', 'nullable', 'in:A,B,C'],
        ]);

        if (isset($data['title'])) {
            $data['headline_pattern'] = Rewriter::headlinePattern($data['title']);
        }

        if ($draft->status === 'generated') {
            $data['status'] = 'edited';
        }
        $draft->update($data);

        return response()->json($draft->fresh());
    }

    public function approve(OwnPublication $draft): JsonResponse
    {
        $draft->update(['status' => 'approved']);

        return response()->json($draft);
    }

    public function reject(OwnPublication $draft): JsonResponse
    {
        $draft->update(['status' => 'rejected']);

        if ($materialId = data_get($draft->experiment_tags, 'material_id')) {
            SourceMaterial::whereKey($materialId)->update(['status' => 'skipped']);
        }

        return response()->json($draft);
    }
}
