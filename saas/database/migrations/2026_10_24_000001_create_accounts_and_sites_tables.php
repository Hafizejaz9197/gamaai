<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Null means no limit. Tokens per calendar month (UTC).
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role')->default('member')->after('account_id');
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            // Only a SHA-256 hash of the plugin token is stored; the prefix identifies it in lists.
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            $table->string('plugin_version')->nullable();
            $table->string('avada_builder_version')->nullable();
            $table->boolean('avada_verified')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('accounts');
    }
};
