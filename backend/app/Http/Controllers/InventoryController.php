<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        $balances = DB::table('stock_movements')->selectRaw('product_id, SUM(quantity) AS on_hand')->groupBy('product_id');
        $page = DB::table('products')->leftJoinSub($balances, 'balance', 'balance.product_id', '=', 'products.id')
            ->select('products.*')->selectRaw('CAST(COALESCE(balance.on_hand, 0) AS SIGNED) AS on_hand')
            ->orderBy('products.id')->paginate(25);

        return response()->json($page);
    }

    public function movements(int $product): JsonResponse
    {
        abort_unless(DB::table('products')->where('id', $product)->exists(), 404);
        $page = DB::table('stock_movements as movement')->join('receipt_lines as line', 'line.id', '=', 'movement.receipt_line_id')
            ->where('movement.product_id', $product)->orderByDesc('movement.id')
            ->select('movement.*', 'line.receipt_id')->paginate(25);

        return response()->json($page);
    }
}
