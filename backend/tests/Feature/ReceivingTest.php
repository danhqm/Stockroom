<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivingTest extends TestCase
{
    use RefreshDatabase;

    private function order(): array
    {
        $manager = User::factory()->create();
        $manager->role = 'manager';
        $manager->save();
        $this->actingAs($manager);
        $product = $this->postJson('/api/products', ['sku' => 'MUG-01', 'name' => 'Mug'])->assertCreated()->json('data.id');
        $this->postJson('/api/products', ['sku' => 'MUG-01', 'name' => 'Duplicate'])->assertUnprocessable();
        $supplier = $this->postJson('/api/suppliers', ['name' => 'Ceramics Supply'])->assertCreated()->json('data.id');
        $payload = ['supplier_id' => $supplier, 'lines' => [['product_id' => $product, 'quantity' => 10]]];
        $this->postJson('/api/purchase-orders', [
            ...$payload, 'lines' => [...$payload['lines'], ...$payload['lines']],
        ])->assertUnprocessable();
        $order = $this->postJson('/api/purchase-orders', $payload)->assertCreated()->json('data.id');
        $line = $this->postJson("/api/purchase-orders/$order/issue")->assertOk()
            ->assertJsonPath('data.status', 'issued')->json('data.lines.0.id');
        $this->putJson("/api/purchase-orders/$order", $payload)->assertConflict();

        return [$manager, $order, $line, $product];
    }

    private function draft(int $order, int $line, int $delivered, int $damaged, string $key): array
    {
        return [
            'purchase_order_id' => $order,
            'reference' => 'NOTE-1',
            'idempotency_key' => $key,
            'lines' => [['purchase_order_line_id' => $line, 'delivered' => $delivered, 'damaged' => $damaged]],
        ];
    }

    public function test_partial_delivery_updates_stock_once_and_can_be_reversed(): void
    {
        [$manager, $order, $line, $product] = $this->order();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/products', ['sku' => 'NO', 'name' => 'Forbidden'])->assertForbidden();
        $first = $this->draft($order, $line, 6, 1, 'receive-note-one');
        $receipt = $this->postJson('/api/receipts', $first)->assertCreated()->json('data.id');
        $this->postJson('/api/receipts', $first)->assertOk()->assertJsonPath('data.id', $receipt);
        $this->postJson('/api/receipts', [...$first, 'reference' => 'Changed'])->assertConflict();
        $this->getJson('/api/inventory')->assertJsonPath('data.0.on_hand', 0);
        $this->putJson("/api/receipts/$receipt", [...$first, 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->putJson("/api/receipts/$receipt", [...$first, 'revision' => 1])->assertConflict();
        $this->postJson("/api/receipts/$receipt/confirm", ['revision' => 1])->assertConflict();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson("/api/receipts/$receipt/confirm", ['revision' => 2])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/receipts/$receipt/confirm", ['revision' => 2])->assertOk();
        $this->putJson("/api/receipts/$receipt", [...$first, 'revision' => 2])->assertConflict();
        $this->getJson("/api/purchase-orders/$order")->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.lines.0.outstanding', 5);
        $this->getJson('/api/inventory')->assertJsonPath('data.0.on_hand', 5);
        $this->assertDatabaseCount('stock_movements', 1);

        $extra = $this->postJson('/api/receipts', $this->draft($order, $line, 6, 0, 'receive-extra'))->assertCreated()
            ->assertJsonPath('data.duplicate_reference', true)->json('data.id');
        $this->postJson("/api/receipts/$extra/confirm", ['revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('receipts', ['id' => $extra, 'status' => 'draft']);
        $last = $this->postJson('/api/receipts', $this->draft($order, $line, 5, 0, 'receive-note-two'))->assertCreated()->json('data.id');
        $this->postJson("/api/receipts/$last/confirm", ['revision' => 1])->assertOk();
        $this->getJson("/api/purchase-orders/$order")->assertJsonPath('data.status', 'fulfilled');
        $this->postJson("/api/receipts/$last/reverse", ['reason' => 'Wrong delivery'])->assertForbidden();
        $this->actingAs($manager);
        $this->postJson("/api/receipts/$last/reverse", [])->assertUnprocessable();
        $this->postJson("/api/receipts/$last/reverse", ['reason' => 'Wrong delivery'])->assertOk();
        $this->postJson("/api/receipts/$last/reverse", ['reason' => 'Wrong delivery'])->assertConflict();
        $this->getJson("/api/inventory/$product/movements")->assertJsonPath('data.0.quantity', -5);
        $this->getJson('/api/inventory')->assertJsonPath('data.0.on_hand', 5);
        $this->getJson("/api/purchase-orders/$order")->assertJsonPath('data.status', 'partially_received');
    }

    public function test_damage_and_order_boundaries(): void
    {
        [$manager, $order, $line] = $this->order();
        $this->postJson('/api/receipts', $this->draft($order, $line + 999, 4, 0, 'invalid-line'))->assertUnprocessable();
        $this->postJson('/api/receipts', $this->draft($order, $line, 4, 5, 'invalid-damage'))->assertUnprocessable();
        $receipt = $this->postJson('/api/receipts', $this->draft($order, $line, 4, 4, 'damaged-note'))->assertCreated()->json('data.id');
        $this->postJson("/api/receipts/$receipt/confirm", ['revision' => 1])->assertOk();
        $this->assertDatabaseCount('stock_movements', 0);
        $this->getJson("/api/purchase-orders/$order")->assertJsonPath('data.status', 'issued');
        $this->postJson("/api/purchase-orders/$order/close", ['reason' => 'Supplier cancelled'])->assertOk();
        $this->postJson("/api/receipts/$receipt/reverse", ['reason' => 'Duplicate note'])->assertOk();
        $this->getJson("/api/purchase-orders/$order")->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.lines.0.outstanding', 10);
        $this->postJson('/api/receipts', $this->draft($order, $line, 4, 0, 'closed-note'))->assertConflict();
    }
}
