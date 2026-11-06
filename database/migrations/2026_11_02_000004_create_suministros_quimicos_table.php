<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('suministros_quimicos')) {
            Schema::create('suministros_quimicos', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('codigo')->unique();
                $table->string('nombre');
                $table->string('tipo');
                $table->decimal('litros_disponibles', 10, 2)->default(0.00);
                $table->string('lote');
                $table->date('fecha_caducidad');
                $table->string('estacion_id')->default('EST-01');
                $table->foreign('estacion_id')->references('id')->on('estaciones_inventario')->onDelete('cascade');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suministros_quimicos');
    }
};
