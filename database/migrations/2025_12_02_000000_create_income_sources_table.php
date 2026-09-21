<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            $table->string('name');

            // salary|freelance|business|sale|investment|bonus|gift|refund|other
            $table->string('type')->default('other');

            // Nulo para fuentes variables (ej. freelance sin monto fijo).
            $table->decimal('default_amount', 12, 2)->nullable();
            $table->decimal('estimated_min_amount', 12, 2)->nullable();
            $table->decimal('estimated_max_amount', 12, 2)->nullable();

            // weekly|biweekly|monthly|yearly|irregular
            $table->string('frequency')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->boolean('active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_sources');
    }
};
