<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('alertas_quiebre_stock')) {
            Schema::create('alertas_quiebre_stock', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('item_codigo');
                $table->string('item_nombre');
                $table->decimal('stock_remanente', 10, 2);
                $table->decimal('umbral_seguridad', 10, 2)->default(200.00);
                $table->string('estado_webhook')->default('ENVIADO_N8N');
                $table->string('estacion_id')->default('EST-01');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_quiebre_stock');
    }
};
