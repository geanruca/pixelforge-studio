<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nanos');
            $table->bigInteger('balance_after_nanos');
            $table->string('reference')->nullable();
            $table->timestamp('created_at');

            // The database, not the code, is the last line of defence: a
            // generation can be held, released and charged once each, and a
            // purchase reference can be credited once per user.
            $table->unique(['generation_id', 'type']);
            $table->unique(['user_id', 'type', 'reference']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
