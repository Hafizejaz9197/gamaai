<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The AI provider chosen in the admin dashboard. The newest row is the
     * one in use; with no row, the provider comes from .env (Claude).
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32); // anthropic or openai_compatible
            $table->string('base_url')->nullable();
            $table->string('model', 100);
            $table->text('api_key')->nullable(); // encrypted with APP_KEY
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
