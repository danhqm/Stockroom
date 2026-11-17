<?php

namespace App\Http\Controllers;

use App\Stockroom\PurchaseOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DB::table('purchase_orders')->orderByDesc('id')->paginate(25));
    }

    public function show(int $order): JsonResponse
    {
        return DB::transaction(function () use ($order) {
            $data = PurchaseOrders::find($order);
            $data->lines = PurchaseOrders::outstanding($order);

            return response()->json(['data' => $data]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $order = DB::transaction(function () use ($request, $data) {
            $id = DB::table('purchase_orders')->insertGetId([
                'supplier_id' => $data['supplier_id'], 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->replaceLines($id, $data['lines']);

            return $id;
        }, 3);

        return $this->show($order)->setStatusCode(201);
    }

    public function update(Request $request, int $order): JsonResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($order, $data) {
            abort_unless(PurchaseOrders::find($order, true)->status === 'draft', 409, 'Only draft orders can be edited.');
            DB::table('purchase_orders')->where('id', $order)->update(['supplier_id' => $data['supplier_id'], 'updated_at' => now()]);
            $this->replaceLines($order, $data['lines']);
        }, 3);

        return $this->show($order);
    }

    public function issue(int $order): JsonResponse
    {
        DB::transaction(function () use ($order) {
            abort_unless(PurchaseOrders::find($order, true)->status === 'draft', 409, 'Only draft orders can be issued.');
            $this->requireActiveProducts(PurchaseOrders::lines($order)->pluck('product_id')->all());
            DB::table('purchase_orders')->where('id', $order)->update([
                'status' => 'issued', 'issued_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);

        return $this->show($order);
    }

    public function close(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $order, $data) {
            $state = PurchaseOrders::find($order, true)->status;
            abort_unless(in_array($state, ['issued', 'partially_received'], true), 409, 'Only open, issued orders can be closed.');
            DB::table('purchase_orders')->where('id', $order)->update([
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id,
                'close_reason' => $data['reason'], 'updated_at' => now(),
            ]);
        }, 3);

        return $this->show($order);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer:strict|min:1|exists:suppliers,id',
            'lines' => 'required|array|list|min:1|max:100',
            'lines.*' => 'required|array:product_id,quantity',
            'lines.*.product_id' => 'required|integer:strict|min:1|distinct',
            'lines.*.quantity' => 'required|integer:strict|min:1|max:1000000',
        ]);
    }

    private function replaceLines(int $order, array $lines): void
    {
        $this->requireActiveProducts(array_column($lines, 'product_id'));
        DB::table('purchase_order_lines')->where('purchase_order_id', $order)->delete();
        DB::table('purchase_order_lines')->insert(array_map(
            fn ($line) => [...$line, 'purchase_order_id' => $order], $lines,
        ));
    }

    private function requireActiveProducts(array $ids): void
    {
        $products = DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($products->count() !== count($ids) || $products->contains(fn ($product) => $product->archived)) {
            throw ValidationException::withMessages(['lines' => 'Every order line must reference an active product.']);
        }
    }
}
