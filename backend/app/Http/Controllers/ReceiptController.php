<?php

namespace App\Http\Controllers;

use App\Stockroom\PurchaseOrders;
use App\Stockroom\Receiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['purchase_order_id' => 'sometimes|integer|min:1']);
        $rows = DB::table('receipts')->select(Receiving::PUBLIC_FIELDS)->orderByDesc('id');
        if (isset($filters['purchase_order_id'])) {
            $rows->where('purchase_order_id', $filters['purchase_order_id']);
        }

        return response()->json($rows->paginate(25)->withQueryString());
    }

    public function show(int $receipt): JsonResponse
    {
        return DB::transaction(function () use ($receipt) {
            $data = DB::table('receipts')->where('id', $receipt)->first(Receiving::PUBLIC_FIELDS) ?? abort(404);
            $data->lines = DB::table('receipt_lines')->where('receipt_id', $receipt)->orderBy('id')
                ->selectRaw('receipt_lines.*, CAST(delivered AS SIGNED) - damaged AS usable')->get();
            $data->duplicate_reference = $data->reference !== null && DB::table('receipts as other')
                ->join('purchase_orders as other_order', 'other_order.id', '=', 'other.purchase_order_id')
                ->where('other_order.supplier_id', PurchaseOrders::find($data->purchase_order_id)->supplier_id)
                ->where('other.reference', $data->reference)->where('other.id', '!=', $receipt)->exists();

            return response()->json(['data' => $data]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => 'required|integer:strict|min:1',
            'idempotency_key' => 'required|string|min:8|max:128',
        ]);
        $draft = $this->validatedDraft($request);
        $canonicalDraft = $draft;
        usort($canonicalDraft['lines'], fn ($a, $b) => $a['purchase_order_line_id'] <=> $b['purchase_order_line_id']);
        $requestHash = hash('sha256', json_encode($canonicalDraft, JSON_THROW_ON_ERROR));
        $requestKey = hash('sha256', $data['idempotency_key']);
        [$id, $created] = DB::transaction(function () use ($request, $data, $draft, $requestHash, $requestKey) {
            $order = PurchaseOrders::find($data['purchase_order_id'], true);
            $identity = [
                'purchase_order_id' => $order->id, 'created_by' => $request->user()->id,
                'request_key' => $requestKey,
            ];
            $existing = DB::table('receipts')->where($identity)->first();
            if ($existing) {
                abort_unless($existing->request_hash === $requestHash, 409, 'This idempotency key was used with different receipt data.');

                return [$existing->id, false];
            }
            Receiving::requireOpenOrder($order);
            $id = DB::table('receipts')->insertGetId([
                ...$identity, 'reference' => $draft['reference'], 'request_hash' => $requestHash,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->replaceLines($id, $order->id, $draft['lines']);

            return [$id, true];
        }, 3);

        return $this->show($id)->setStatusCode($created ? 201 : 200);
    }

    public function update(Request $request, int $receipt): JsonResponse
    {
        $draft = $this->validatedDraft($request);
        $revision = $request->validate(['revision' => 'required|integer:strict|min:1'])['revision'];
        Receiving::withLockedReceipt($receipt, function ($order, $record) use ($draft, $revision) {
            abort_unless($record->status === 'draft', 409, 'Only draft receipts can be edited.');
            Receiving::requireRevision($record, $revision);
            Receiving::requireOpenOrder($order);
            DB::table('receipts')->where('id', $record->id)->update([
                'reference' => $draft['reference'], 'revision' => $revision + 1, 'updated_at' => now(),
            ]);
            $this->replaceLines($record->id, $order->id, $draft['lines']);
        });

        return $this->show($receipt);
    }

    public function confirm(Request $request, int $receipt, Receiving $receiving): JsonResponse
    {
        $revision = $request->validate(['revision' => 'required|integer:strict|min:1'])['revision'];
        $receiving->confirm($receipt, $request->user()->id, $revision);

        return $this->show($receipt);
    }

    public function reverse(Request $request, int $receipt, Receiving $receiving): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $receiving->reverse($receipt, $request->user()->id, $data['reason']);

        return $this->show($receipt);
    }

    private function validatedDraft(Request $request): array
    {
        $data = $request->validate([
            'reference' => 'nullable|string|max:120',
            'lines' => 'present|array|list|max:100',
            'lines.*' => 'required|array:purchase_order_line_id,delivered,damaged',
            'lines.*.purchase_order_line_id' => 'required|integer:strict|min:1|distinct',
            'lines.*.delivered' => 'required|integer:strict|min:1|max:1000000',
            'lines.*.damaged' => 'required|integer:strict|min:0|lte:lines.*.delivered',
        ]);
        $lines = array_map(fn ($line) => [
            'purchase_order_line_id' => (int) $line['purchase_order_line_id'],
            'delivered' => (int) $line['delivered'], 'damaged' => (int) $line['damaged'],
        ], $data['lines']);

        return ['reference' => $data['reference'] ?? null, 'lines' => $lines];
    }

    private function replaceLines(int $receipt, int $order, array $lines): void
    {
        $orderLines = DB::table('purchase_order_lines')->where('purchase_order_id', $order)->pluck('id');
        foreach ($lines as $index => $line) {
            if (! $orderLines->containsStrict($line['purchase_order_line_id'])) {
                throw ValidationException::withMessages(["lines.$index.purchase_order_line_id" => 'Choose a line on this purchase order.']);
            }
        }
        DB::table('receipt_lines')->where('receipt_id', $receipt)->delete();
        if ($lines !== []) {
            DB::table('receipt_lines')->insert(array_map(fn ($line) => [...$line, 'receipt_id' => $receipt], $lines));
        }
    }
}
