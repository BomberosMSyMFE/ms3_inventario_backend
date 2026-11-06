<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('movimientos_inventario')) {
            Schema::create('movimientos_inventario', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('tipo_movimiento'); // ENTRADA, SALIDA, AJUSTE, RESERVA_SAGA, COMPENSACION_SAGA
                $table->string('item_codigo');
                $table->string('item_nombre');
                $table->string('categoria'); // QUIMICO, EPP, HERRAMIENTA
                $table->decimal('cantidad', 10, 2);
                $table->text('motivo');
                $table->string('responsable')->default('Jefa de Logística');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
