<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The usage ledger is append-only: rows are never updated or deleted.
     * A request reserves tokens before calling an AI provider, then settles
     * with the real usage or releases the reservation.
     */
    public function up(): void
    {
        Schema::create('usage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id');
            $table->string('kind', 16); // reserve, settle, release, adjust
            $table->string('purpose', 64)->nullable();
            $table->string('model', 100)->nullable();
            $table->bigInteger('tokens'); // reserved tokens for reserve, charged tokens for settle/adjust, 0 for release
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            // Costs in millionths of a US dollar. Estimated from our price table;
            // provider cost is what the provider reported, when it does.
            $table->bigInteger('estimated_cost_micros')->default(0);
            $table->bigInteger('provider_cost_micros')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
            $table->unique(['request_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_entries');
    }
};
