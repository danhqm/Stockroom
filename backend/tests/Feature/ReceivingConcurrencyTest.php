<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReceivingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_confirmations_cannot_overreceive(): void
    {
        $connection = config('database.connections.mysql');
        $this->assertSame('stockroom_test', $connection['database']);
        $user = User::factory()->create()->id;
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Supplier']);
        $product = DB::table('products')->insertGetId(['sku' => 'MUG', 'name' => 'Mug']);
        $order = DB::table('purchase_orders')->insertGetId(['supplier_id' => $supplier, 'created_by' => $user, 'status' => 'issued']);
        $line = DB::table('purchase_order_lines')->insertGetId(['purchase_order_id' => $order, 'product_id' => $product, 'quantity' => 10]);
        $receipts = [];
        foreach (['first', 'second'] as $key) {
            $id = DB::table('receipts')->insertGetId([
                'purchase_order_id' => $order, 'created_by' => $user, 'request_key' => $key, 'request_hash' => $key,
            ]);
            DB::table('receipt_lines')->insert([
                'receipt_id' => $id, 'purchase_order_line_id' => $line, 'delivered' => 6, 'damaged' => 0,
            ]);
            $receipts[] = $id;
        }

        $worker = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            fwrite(STDOUT, "ready\n");
            try {
                $app->make(App\Stockroom\Receiving::class)->confirm((int) $argv[1], (int) $argv[2], 1);
                echo 'confirmed';
            } catch (Illuminate\Validation\ValidationException) {
                echo 'overreceived';
            }
            PHP;

        $processes = [];
        DB::beginTransaction();
        try {
            DB::table('purchase_orders')->where('id', $order)->lockForUpdate()->first();
            foreach ($receipts as $id) {
                $process = new Process([PHP_BINARY, '-r', $worker, (string) $id, (string) $user], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => $connection['host'], 'DB_PORT' => $connection['port'],
                    'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
                    'DB_PASSWORD' => $connection['password'],
                ], timeout: 35);
                $processes[] = $process;
                $process->start();
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'ready')), $process->getErrorOutput());
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = trim(str_replace("ready\n", '', $process->getOutput()));
            }
            $this->assertEqualsCanonicalizing(['confirmed', 'overreceived'], $results);
            $this->assertDatabaseCount('stock_movements', 1);
            $this->assertSame(6, (int) DB::table('stock_movements')->sum('quantity'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                $process->stop(0);
            }
        }
    }
}
