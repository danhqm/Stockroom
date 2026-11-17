<?php

namespace Tests\Feature;

use App\Jobs\ExtractDeliveryNote;
use App\Models\User;
use App\Stockroom\DeliveryNoteExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(): int
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Demo supplier']);
        $order = DB::table('purchase_orders')->insertGetId([
            'supplier_id' => $supplier, 'created_by' => $user->id, 'status' => 'issued',
        ]);

        return $this->postJson('/api/receipts', [
            'purchase_order_id' => $order, 'idempotency_key' => 'extraction-test', 'lines' => [],
        ])->assertCreated()->json('data.id');
    }

    private function file(): UploadedFile
    {
        $headerA = pack('NNCCCCC', 32, 32, 8, 0, 0, 0, 0);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', $headerA)
            .$chunk('IDAT', gzcompress(str_repeat("\0", 32 * 33))).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('delivery.png', $png);
    }

    public function test_private_upload_drafts_extraction_without_changing_stock(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['stockroom.extraction_enabled' => true, 'stockroom.openai_key' => 'test-only', 'stockroom.extraction_attempt_limit' => 1]);
        $note = ['supplier' => 'Demo supplier', 'reference' => null, 'lines' => [['source_text' => 'Unmapped mugs', 'quantity' => null]]];
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($note)]]]],
        ])]);

        $receipt = $this->receipt();
        $this->postJson("/api/receipts/$receipt/document", [
            'document' => UploadedFile::fake()->createWithContent('fake.png', 'not an image'),
        ])->assertUnprocessable();
        $uploaded = $this->postJson("/api/receipts/$receipt/document", ['document' => $this->file()])->assertStatus(202);
        $id = $uploaded->json('data.id');
        Queue::assertPushed(ExtractDeliveryNote::class, 1);
        $this->postJson("/api/receipts/$receipt/document", ['document' => $this->file()])->assertConflict();

        $job = new ExtractDeliveryNote($id);
        $job->handle(app(DeliveryNoteExtractor::class));
        $job->handle(app(DeliveryNoteExtractor::class));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['store'] === false && $request['text']['format']['strict'] === true);
        $this->getJson("/api/receipts/$receipt/extraction")->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.lines.0.source_text', 'Unmapped mugs')
            ->assertJsonPath('data.result.lines.0.quantity', null)
            ->assertJsonMissingPath('data.image_base64');
        $this->get("/api/receipts/$receipt/document")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson("/api/receipts/$receipt/confirm", ['revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('receipt_lines', 0);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/receipts/$receipt/document")->assertUnauthorized();
    }

    public function test_malformed_provider_response_and_exhausted_allowance_leave_receipts_editable(): void
    {
        Http::preventStrayRequests();
        config(['stockroom.extraction_enabled' => true, 'stockroom.openai_key' => 'test-only', 'stockroom.extraction_attempt_limit' => 1]);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed', 'output' => [['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => '{"supplier":null,"reference":null,"lines":[{"source_text":"Mugs","quantity":-2}]}'],
            ]]],
        ])]);

        foreach (['extraction_failed', 'allowance_exhausted'] as $error) {
            $receipt = $this->receipt();
            $this->postJson("/api/receipts/$receipt/document", ['document' => $this->file()])->assertStatus(202);
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--once' => true]));
            $this->assertDatabaseCount('jobs', 0);
            $this->getJson("/api/receipts/$receipt/extraction")->assertJsonPath('data.error', $error)
                ->assertJsonPath('data.status', 'failed');
            $this->putJson("/api/receipts/$receipt", ['lines' => [], 'reference' => 'Manual review', 'revision' => 1])->assertOk();
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
