<?php

namespace App\Http\Controllers;

use App\Support\{DecimalId, OutboxStore};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutboxController extends Controller
{
    public function __construct(private readonly OutboxStore $store) {}

    public function index(Request $request): JsonResponse
    {
        $branches = $this->store->ownerBranches((string) $request->user()->id);
        abort_if($branches === [], 403);
        $data = $request->validate(['status' => ['nullable', 'in:pending,processing,retry,failed,published']]);
        return response()->json(['data' => $this->store->listing($branches, $data['status'] ?? 'failed')]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $id = DecimalId::parse($id, 'id');
        $this->store->retry((string) $request->user()->id, $id);
        return response()->json(['id' => $id, 'status' => 'retry']);
    }
}
