<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name', 160);
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->enum('status', ['draft', 'issued', 'partially_received', 'fulfilled', 'closed'])->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->string('close_reason', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unique(['purchase_order_id', 'product_id']);
        });
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('reference', 120)->nullable()->index();
            $table->char('request_key', 64);
            $table->char('request_hash', 64);
            $table->enum('status', ['draft', 'confirmed', 'reversed'])->default('draft');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'created_by', 'request_key']);
        });
        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained();
            $table->foreignId('purchase_order_line_id')->constrained();
            $table->unsignedInteger('delivered');
            $table->unsignedInteger('damaged');
            $table->unique(['receipt_id', 'purchase_order_line_id']);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_line_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->enum('type', ['receive', 'reverse']);
            $table->integer('quantity');
            $table->timestamp('created_at');
            $table->unique(['receipt_line_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'receipt_lines', 'receipts', 'purchase_order_lines', 'purchase_orders', 'suppliers', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
