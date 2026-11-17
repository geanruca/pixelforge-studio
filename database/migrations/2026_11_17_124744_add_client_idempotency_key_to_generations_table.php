<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `Idempotency-Key` an API client sent when creating the generation.
     * Unique per user, so a retried request can never create (and hold
     * credits for) a second generation.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->string('client_idempotency_key')->nullable();
            $table->unique(['user_id', 'client_idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_idempotency_key']);
            $table->dropColumn('client_idempotency_key');
        });
    }
};
