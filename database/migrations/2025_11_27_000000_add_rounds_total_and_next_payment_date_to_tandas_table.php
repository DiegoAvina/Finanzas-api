<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tandas', function (Blueprint $table) {
            $table->unsignedInteger('rounds_total')->nullable()->after('num_members');
            $table->date('next_payment_date')->nullable()->after('current_round');
        });

        // Backfill para tandas ya existentes: rounds_total = num_members,
        // next_payment_date = start_date (la app las irá avanzando desde el primer pago registrado).
        DB::table('tandas')->update([
            'rounds_total' => DB::raw('num_members'),
            'next_payment_date' => DB::raw('start_date'),
        ]);
    }

    public function down(): void
    {
        Schema::table('tandas', function (Blueprint $table) {
            $table->dropColumn(['rounds_total', 'next_payment_date']);
        });
    }
};
