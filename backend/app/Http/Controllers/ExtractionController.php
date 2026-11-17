<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractDeliveryNote;
use App\Stockroom\Receiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\File;

class ExtractionController extends Controller
{
    public function store(Request $request, int $receipt): JsonResponse
    {
        abort_unless(config('stockroom.extraction_enabled'), 503, 'Document extraction is disabled.');
        $request->validate([
            'document' => [
                'required',
                File::types(['jpeg', 'jpg', 'png'])->max(2048),
                'image',
                'dimensions:min_width=32,min_height=32,max_width=2048,max_height=2048',
            ],
        ]);
        $file = $request->file('document');
        $image = base64_encode($file->get());
        Receiving::withLockedReceipt($receipt, function ($order, $record) use ($request, $file, $image) {
            abort_unless($record->status === 'draft', 409, 'Documents can only be attached to draft receipts.');
            Receiving::requireOpenOrder($order);
            abort_if(DB::table('document_extractions')->where('receipt_id', $record->id)->exists(), 409, 'This receipt already has a delivery note.');
            $id = DB::table('document_extractions')->insertGetId([
                'receipt_id' => $record->id, 'uploaded_by' => $request->user()->id,
                'mime_type' => $file->getMimeType(), 'image_base64' => $image, 'created_at' => now(), 'updated_at' => now(),
            ]);
            ExtractDeliveryNote::dispatch($id)->onConnection('database')->beforeCommit();
        });

        return $this->show($receipt)->setStatusCode(202);
    }

    public function show(int $receipt): JsonResponse
    {
        $data = DB::table('document_extractions')->where('receipt_id', $receipt)
            ->first(['id', 'receipt_id', 'uploaded_by', 'status', 'result', 'error', 'created_at', 'updated_at']) ?? abort(404);
        $data->result = $data->result === null ? null : json_decode($data->result, true, flags: JSON_THROW_ON_ERROR);

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function document(int $receipt): Response
    {
        $document = DB::table('document_extractions')->where('receipt_id', $receipt)
            ->first(['image_base64', 'mime_type']) ?? abort(404);

        return response(base64_decode($document->image_base64), 200, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
