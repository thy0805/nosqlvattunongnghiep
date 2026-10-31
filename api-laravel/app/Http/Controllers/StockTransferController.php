<?php

namespace App\Http\Controllers;

use App\Support\BranchAccess;
use App\Support\DecimalId;
use App\Support\DecimalLimit;
use App\Support\StockTransferService;
use App\Support\TransferAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\TransferStore;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function __construct(private readonly TransferStore $store) {}

    private function joined(array $row, bool $detail = false): ?array
    {
        $p = $this->store->purchase; $i = $p->identity;
        $from = $p->scope($row['from_warehouse_id']); $to = $p->scope($row['to_warehouse_id']);
        $requester = $i->find('users', $row['requested_by']);
        if (!$from || !$to || !$requester) return null;
        $data = $this->store->present($row);
        if (!$detail) $data = array_intersect_key($data, array_flip(['id','transfer_no','status','requested_at','requested_by','approved_by','from_warehouse_id','to_warehouse_id']));
        $data += ['from_warehouse_name'=>$from['warehouse']['name'],'from_branch_id'=>$from['branch']['_id'],'from_branch_name'=>$from['branch']['name'],
            'to_warehouse_name'=>$to['warehouse']['name'],'to_branch_id'=>$to['branch']['_id'],'to_branch_name'=>$to['branch']['name'],'requester_name'=>$requester['username']];
        if ($detail) $data['chain_id'] = $from['chain']['_id'];
        return $data;
    }

    public function index(Request $request, BranchAccess $branches): JsonResponse
    {
        $filters = $request->validate(['status'=>['nullable','in:requested,approved,rejected,dispatched,received,discrepancy,reconciled'],'per_page'=>['nullable','integer','min:1','max:50']]);
        $allowedIds = $branches->branchesFor($request->user())->pluck('id')->all();
        abort_if($allowedIds === [], 403);
        $rows = $this->store->rows('stock_transfers', isset($filters['status']) ? ['status'=>$filters['status']] : [], ['id_sort'=>-1])
            ->map(fn($r)=>$this->joined($r))->filter(fn($r)=>$r && (in_array($r['from_branch_id'],$allowedIds,true) || in_array($r['to_branch_id'],$allowedIds,true)))->values();
        $size = (int) ($filters['per_page'] ?? 20); $page = max(1, (int) $request->query('page',1));
        return response()->json(['data'=>$rows->slice(($page-1)*$size,$size)->values()->all(),'pagination'=>['current_page'=>$page,'last_page'=>max(1,(int)ceil($rows->count()/$size)),'total'=>$rows->count()]]);
    }

    public function options(Request $request, BranchAccess $branches, TransferAccess $access): JsonResponse
    {
        $allowed = $branches->branchesFor($request->user()); abort_if($allowed->isEmpty(),403);
        $sources = []; $destinations = []; $lots = []; $p = $this->store->purchase;
        foreach ($p->identity->rows('warehouses') as $w) {
            $scope = $p->scope($w['_id']);
            if (!$scope || !$scope['branch']['is_active'] || !$scope['chain']['is_active']) continue;
            $b = $scope['branch'];
            $destinations[] = ['id'=>$w['_id'],'name'=>$w['name'],'branch_id'=>$b['_id'],'branch_name'=>$b['name'],'chain_id'=>$b['chain_id']];
        }
        $destinations = collect($destinations)->sortBy([['branch_name','asc'],['name','asc']])->values();
        $sources = $destinations->filter(fn($w)=>$allowed->contains('id',$w['branch_id']) && $access->canRequest($request->user(),$w['branch_id']))->values();
        $chains = $sources->pluck('chain_id')->unique()->values()->all();
        $destinations = $destinations->whereIn('chain_id',$chains)->values();
        foreach ($p->rows('inventories',['warehouse_id'=>['$in'=>$sources->pluck('id')->all()]]) as $inv) {
            if (!BigDecimal::of((string)$inv['quantity'])->isGreaterThan(0)) continue;
            $lot = app(\App\Support\SaleService::class)->lot($inv['lot_id']);
            if (!$lot || !$lot['product_active'] || !$lot['category_active'] || !$lot['unit_active']) continue;
            $lots[] = ['warehouse_id'=>$inv['warehouse_id'],'lot_id'=>$inv['lot_id'],'quantity'=>(string)BigDecimal::of((string)$inv['quantity'])->toScale(3),
                'lot_no'=>$lot['lot_no'],'product_code'=>$lot['code'],'product_name'=>$lot['name'],'unit_name'=>$lot['unit_name']];
        }
        return response()->json(['sources'=>$sources,'destinations'=>$destinations,'lots'=>collect($lots)->sortBy([['product_name','asc'],['lot_no','asc']])->values()]);
    }

    public function show(Request $request, BranchAccess $branches, TransferAccess $access, string $id): JsonResponse
    {
        $id = DecimalId::parse($id,'id');
        $allowedIds = $branches->branchesFor($request->user())->pluck('id')->all();
        $row = $this->store->find('stock_transfers',$id); $joined = $row ? $this->joined($row,true) : null;
        abort_if(!$joined || (!in_array($joined['from_branch_id'],$allowedIds,true) && !in_array($joined['to_branch_id'],$allowedIds,true)),404);
        $transfer = (object)$joined;
        $approver = $transfer->status === 'requested'
            && (string) $transfer->requested_by !== (string) $request->user()->id
            && $access->canApprove($request->user(), (string) $transfer->from_branch_id, (string) $transfer->chain_id);
        $transfer->can_approve = $approver;
        $transfer->can_reject = $approver;
        $transfer->can_dispatch = $transfer->status === 'approved'
            && $access->canRequest($request->user(), (string) $transfer->from_branch_id);
        $transfer->can_receive = $transfer->status === 'dispatched'
            && $access->canReceive($request->user(), (string) $transfer->to_branch_id);
        $transfer->can_reconcile = $transfer->status === 'discrepancy'
            && $access->canReconcile($request->user(), (string) $transfer->from_branch_id,
                (string) $transfer->to_branch_id, (string) $transfer->chain_id);
        $items = [];
        foreach ($this->store->rows('stock_transfer_items',['transfer_id'=>$id],['id_sort'=>1]) as $row) {
            $lot = app(\App\Support\SaleService::class)->lot($row['lot_id']);
            if (!$lot) continue;
            $items[] = $this->store->present($row,['id','lot_id','requested_quantity','dispatched_quantity','received_quantity','supplemental_received_quantity','returned_quantity','lost_quantity'])
                + ['lot_no'=>$lot['lot_no'],'product_code'=>$lot['code'],'product_name'=>$lot['name'],'unit_name'=>$lot['unit_name']];
        }
        return response()->json(['data'=>$transfer,'items'=>$items]);
    }

    public function store(Request $request, TransferAccess $access): JsonResponse
    {
        $data = $request->validate([
            'requested_by' => ['prohibited'], 'approved_by' => ['prohibited'],
            'transfer_no' => ['prohibited'], 'status' => ['prohibited'],
            'from_warehouse_id' => ['required', 'string'],
            'to_warehouse_id' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.lot_id' => ['required', 'string'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
            'items.*.dispatched_quantity' => ['prohibited'], 'items.*.received_quantity' => ['prohibited'],
        ]);
        $fromId = DecimalId::parse($data['from_warehouse_id'], 'from_warehouse_id');
        $toId = DecimalId::parse($data['to_warehouse_id'], 'to_warehouse_id');
        if ($fromId === $toId) throw ValidationException::withMessages(['to_warehouse_id' => 'Kho nguồn và kho đích phải khác nhau.']);
        foreach ($data['items'] as $index => $item) DecimalId::parse($item['lot_id'], "items.{$index}.lot_id");

        $id = app(StockTransferService::class)->request($data, $fromId, $toId, $request->user(), $access);

        return response()->json(['id' => $id], 201);
    }

    public function approve(Request $request, TransferAccess $access, StockTransferService $service, string $id): JsonResponse
    {
        $service->approve(DecimalId::parse($id, 'id'), $request->user(), $access);

        return response()->json(['status' => 'approved']);
    }

    public function reject(Request $request, TransferAccess $access, StockTransferService $service, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $service->reject(DecimalId::parse($id, 'id'), $request->user(), $access, $data['reason'] ?? null);

        return response()->json(['status' => 'rejected']);
    }

    public function dispatch(Request $request, TransferAccess $access, StockTransferService $service, string $id): JsonResponse
    {
        $service->dispatch(DecimalId::parse($id, 'id'), $request->user(), $access, $this->quantities($request, 'dispatched_quantity'));

        return response()->json(['status' => 'dispatched']);
    }

    public function receive(Request $request, TransferAccess $access, StockTransferService $service, string $id): JsonResponse
    {
        $status = $service->receive(DecimalId::parse($id, 'id'), $request->user(), $access, $this->quantities($request, 'received_quantity'));

        return response()->json(['status' => $status]);
    }

    public function reconcile(Request $request, TransferAccess $access, StockTransferService $service, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.lot_id' => ['required', 'string'],
            'items.*.supplemental_received_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
            'items.*.returned_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
            'items.*.lost_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
        ]);
        if (mb_strlen(trim($data['reason'])) < 3) {
            throw ValidationException::withMessages(['reason' => 'Lý do đối soát cần ít nhất 3 ký tự.']);
        }
        $quantities = [];
        foreach ($data['items'] as $index => $item) {
            $lotId = DecimalId::parse($item['lot_id'], "items.{$index}.lot_id");
            if (array_key_exists($lotId, $quantities)) throw ValidationException::withMessages(['items' => 'Một lô chỉ được gửi một lần.']);
            $quantities[$lotId] = [
                'supplemental_received_quantity' => (string) $item['supplemental_received_quantity'],
                'returned_quantity' => (string) $item['returned_quantity'],
                'lost_quantity' => (string) $item['lost_quantity'],
            ];
        }
        $service->reconcile(DecimalId::parse($id, 'id'), $request->user(), $access, $quantities, trim($data['reason']));

        return response()->json(['status' => 'reconciled']);
    }

    private function quantities(Request $request, string $field): array
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.lot_id' => ['required', 'string'],
            "items.*.{$field}" => ['required', 'numeric', 'min:0', 'decimal:0,3', DecimalLimit::rule(15, 3)],
        ]);
        $quantities = [];
        foreach ($data['items'] as $index => $item) {
            $lotId = DecimalId::parse($item['lot_id'], "items.{$index}.lot_id");
            if (array_key_exists($lotId, $quantities)) {
                throw ValidationException::withMessages(['items' => 'Một lô chỉ được gửi một lần.']);
            }
            $quantities[$lotId] = (string) $item[$field];
        }

        return $quantities;
    }

}
