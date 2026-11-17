<?php

namespace App\Jobs;

use App\Stockroom\DeliveryNoteExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExtractDeliveryNote implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public bool $failOnTimeout = true;

    public function __construct(public int $extractionId) {}

    public function handle(DeliveryNoteExtractor $extractor): void
    {
        $claimed = DB::transaction(function () {
            if (! $this->pending()->where('status', 'queued')->update(['status' => 'processing', 'updated_at' => now()])) {
                return false;
            }
            if (! config('stockroom.extraction_enabled') || ! config('stockroom.openai_key')) {
                $this->failExtraction('extraction_disabled');

                return false;
            }
            $reserved = DB::table('extraction_allowance')->where('id', 1)
                ->where('attempts', '<', config('stockroom.extraction_attempt_limit'))->increment('attempts');
            if (! $reserved) {
                $this->failExtraction('allowance_exhausted');

                return false;
            }

            return true;
        }, 3);

        if (! $claimed) {
            return;
        }
        $document = $this->pending()->first(['mime_type', 'image_base64']);
        try {
            $result = $extractor->extract($document->mime_type, $document->image_base64);
            $this->pending()->where('status', 'processing')->update([
                'status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        } catch (Throwable) {
            $this->failExtraction('extraction_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->failExtraction('worker_failed');
    }

    private function pending(): Builder
    {
        return DB::table('document_extractions')->where('id', $this->extractionId);
    }

    private function failExtraction(string $code): void
    {
        $this->pending()->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'failed', 'error' => $code, 'updated_at' => now(),
        ]);
    }
}
