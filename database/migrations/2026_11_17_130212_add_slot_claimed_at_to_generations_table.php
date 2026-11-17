<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a worker took one of the provider's concurrency slots for this
     * generation, before Pixelforge has confirmed accepting it.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->timestamp('slot_claimed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('slot_claimed_at');
        });
    }
};
