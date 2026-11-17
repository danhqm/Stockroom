<?php

namespace App\Stockroom;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Receiving
{
    public const PUBLIC_FIELDS = [
        'id', 'purchase_order_id', 'reference', 'status', 'revision', 'created_by', 'created_at', 'updated_at',
        'confirmed_by', 'confirmed_at', 'reversed_by', 'reversed_at', 'reverse_reason',
    ];

    public static function requireOpenOrder(object $order): void
    {
        abort_unless(in_array($order->status, ['issued', 'partially_received'], true), 409, 'This purchase order is not open for receiving.');
    }

    public static function withLockedReceipt(int $receipt, Closure $work): mixed
    {
        $orderId = DB::table('receipts')->where('id', $receipt)->value('purchase_order_id') ?? abort(404);

        return DB::transaction(function () use ($orderId, $receipt, $work) {
            $order = PurchaseOrders::find($orderId, true);
            $record = DB::table('receipts')->where('id', $receipt)->lockForUpdate()->first() ?? abort(404);

            return $work($order, $record);
        }, 3);
    }

    public static function requireRevision(object $receipt, int $revision): void
    {
        abort_unless((int) $receipt->revision === $revision, 409, 'This receipt changed. Reload it and review the latest values.');
    }

    public function confirm(int $receipt, int $actor, int $revision): void
    {
        self::withLockedReceipt($receipt, function ($order, $record) use ($actor, $revision) {
            self::requireRevision($record, $revision);
            if ($record->status === 'confirmed') {
                return;
            }
            abort_unless($record->status === 'draft', 409, 'Only draft receipts can be confirmed.');
            self::requireOpenOrder($order);
            $lines = $this->lockedLines($record->id, $order->id);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one reviewed delivery line.']);
            }
            $outstanding = PurchaseOrders::outstanding($order->id)->keyBy('id');
            foreach ($lines as $line) {
                if ($line->delivered - $line->damaged > $outstanding[$line->purchase_order_line_id]->outstanding) {
                    throw ValidationException::withMessages([
                        'lines' => "Usable quantity exceeds the outstanding quantity for order line {$line->purchase_order_line_id}.",
                    ]);
                }
            }
            $this->recordMovements($lines, $actor, 'receive');
            DB::table('receipts')->where('id', $record->id)->update([
                'status' => 'confirmed', 'confirmed_by' => $actor, 'confirmed_at' => now(), 'updated_at' => now(),
            ]);
            PurchaseOrders::refreshStatus($order);
        });
    }

    public function reverse(int $receipt, int $actor, string $reason): void
    {
        self::withLockedReceipt($receipt, function ($order, $record) use ($actor, $reason) {
            abort_unless($record->status === 'confirmed', 409, 'Only confirmed receipts can be reversed.');
            $lines = $this->lockedLines($record->id, $order->id);
            $balances = DB::table('stock_movements')->whereIn('product_id', $lines->pluck('product_id'))
                ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) AS on_hand')->pluck('on_hand', 'product_id');

            foreach ($lines as $line) {
                if ($line->delivered - $line->damaged > (int) $balances->get($line->product_id, 0)) {
                    abort(409, 'Reversing this receipt would make on-hand inventory negative.');
                }
            }
            $this->recordMovements($lines, $actor, 'reverse');
            DB::table('receipts')->where('id', $record->id)->update([
                'status' => 'reversed', 'reversed_by' => $actor, 'reversed_at' => now(),
                'reverse_reason' => $reason, 'updated_at' => now(),
            ]);
            PurchaseOrders::refreshStatus($order);
        });
    }

    private function lockedLines(int $receipt, int $order): Collection
    {
        $lines = DB::table('receipt_lines')->where('receipt_id', $receipt)->lockForUpdate()->get();
        $orderLines = DB::table('purchase_order_lines')->where('purchase_order_id', $order)->lockForUpdate()->pluck('product_id', 'id');
        foreach ($lines as $line) {
            $line->product_id = $orderLines->get($line->purchase_order_line_id)
                ?? throw ValidationException::withMessages(['lines' => 'Every delivery line must belong to the chosen order.']);
        }

        // Acquire product locks before creating a consistent read snapshot across orders.
        $ids = $lines->pluck('product_id')->unique()->sort()->values();
        foreach ($ids as $id) {
            DB::table('products')->where('id', $id)->lockForUpdate()->first();
        }

        return $lines;
    }

    private function recordMovements(Collection $lines, int $actor, string $type): void
    {
        foreach ($lines as $line) {
            $usable = $line->delivered - $line->damaged;
            if ($usable > 0) {
                DB::table('stock_movements')->insert([
                    'receipt_line_id' => $line->id, 'product_id' => $line->product_id,
                    'actor_id' => $actor, 'type' => $type,
                    'quantity' => $type === 'receive' ? $usable : -$usable, 'created_at' => now(),
                ]);
            }
        }
    }
}
