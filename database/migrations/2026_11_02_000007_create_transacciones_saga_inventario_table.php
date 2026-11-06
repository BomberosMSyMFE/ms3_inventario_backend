<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transacciones_saga_inventario')) {
            Schema::create('transacciones_saga_inventario', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('transaccion_id')->unique();
                $table->string('incidente_id');
                $table->string('camion_id');
                $table->string('kit_reservado_id')->nullable();
                $table->decimal('litros_espumogeno_reservados', 10, 2)->default(0.00);
                $table->string('estado')->default('RESERVADO');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transacciones_saga_inventario');
    }
};
