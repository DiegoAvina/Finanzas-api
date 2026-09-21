<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_occurrences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('income_source_id')
                ->constrained('income_sources')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            $table->decimal('expected_amount', 12, 2);
            $table->decimal('received_amount', 12, 2)->nullable();

            // Cuánto de received_amount ya se aplicó a weekly_incomes.amount.
            // Permite que "recibir" sea idempotente: llamar receive() otra
            // vez con el mismo monto no vuelve a sumar al saldo.
            $table->decimal('applied_amount', 12, 2)->default(0);

            $table->date('expected_date');
            $table->date('received_date')->nullable();

            // expected|received|partial|missed|cancelled
            $table->string('status')->default('expected');

            // Semana (WeeklyIncome) a la que se aplicó el monto recibido.
            $table->foreignId('weekly_income_id')
                ->nullable()
                ->constrained('weekly_incomes')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_occurrences');
    }
};
