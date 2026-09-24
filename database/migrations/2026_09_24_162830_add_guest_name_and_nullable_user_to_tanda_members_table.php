<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite que un turno de tanda se asigne a alguien sin cuenta en la
     * app (guest_name), no solo a un usuario registrado (user_id).
     */
    public function up(): void
    {
        Schema::table('tanda_members', function (Blueprint $table) {
            $table->string('guest_name')->nullable()->after('user_id');
        });

        Schema::table('tanda_members', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tanda_members', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->dropColumn('guest_name');
        });
    }
};
