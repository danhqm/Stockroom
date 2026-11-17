<?php

namespace App\Stockroom;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrders
{
    public static function find(int $id, bool $lock = false): object
    {
        $query = DB::table('purchase_orders')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    public static function lines(int $id): Collection
    {
        return DB::table('purchase_order_lines as line')
            ->join('products', 'products.id', '=', 'line.product_id')
            ->where('line.purchase_order_id', $id)
            ->orderBy('line.id')->get(['line.*', 'products.sku', 'products.name']);
    }

    public static function outstanding(int $id): Collection
    {
        $received = DB::table('receipt_lines as line')->join('receipts as receipt', 'receipt.id', '=', 'line.receipt_id')
            ->where('receipt.purchase_order_id', $id)->where('receipt.status', 'confirmed')
            ->groupBy('line.purchase_order_line_id')
            ->selectRaw('line.purchase_order_line_id, SUM(CAST(line.delivered AS SIGNED) - line.damaged) AS received')
            ->pluck('received', 'line.purchase_order_line_id');

        return self::lines($id)->map(function ($line) use ($received) {
            $line->received = (int) $received->get($line->id, 0);
            $line->outstanding = $line->quantity - $line->received;

            return $line;
        });
    }

    public static function refreshStatus(object $order): void
    {
        if ($order->status === 'closed') {
            return;
        }
        $lines = self::outstanding($order->id);
        $status = match (true) {
            $lines->sum('outstanding') === 0 => 'fulfilled',
            $lines->sum('received') > 0 => 'partially_received',
            default => 'issued',
        };
        DB::table('purchase_orders')->where('id', $order->id)->update(['status' => $status, 'updated_at' => now()]);
    }
}
