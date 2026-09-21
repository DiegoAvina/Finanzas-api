<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_distribution_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('income_source_id')
                ->constrained('income_sources')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            // saving_goal|tanda|bill|free ("free" = queda disponible, sin target)
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();

            // percent|fixed
            $table->string('mode');
            $table->decimal('value', 12, 2);

            $table->unsignedInteger('order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_distribution_rules');
    }
};
