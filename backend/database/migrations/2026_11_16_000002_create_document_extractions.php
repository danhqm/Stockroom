<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->unique()->constrained();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('mime_type', 32);
            $table->mediumText('image_base64');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->json('result')->nullable();
            $table->string('error', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('extraction_allowance', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('attempts')->default(0);
        });
        DB::table('extraction_allowance')->insert(['id' => 1, 'attempts' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_allowance');
        Schema::dropIfExists('document_extractions');
    }
};
