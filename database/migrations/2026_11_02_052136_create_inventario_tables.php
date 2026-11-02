<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estaciones_inventario', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('nombre');
            $table->string('direccion');
            $table->timestamps();
        });

        Schema::create('kits_epp', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('codigo')->unique();
            $table->integer('presion_oxigeno_bares')->default(300);
            $table->string('estado')->default('DISPONIBLE');
            $table->string('camion_asignado_id')->nullable();
            $table->string('estacion_id')->default('EST-01');
            $table->foreign('estacion_id')->references('id')->on('estaciones_inventario')->onDelete('cascade');
            $table->timestamps();
        });

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

    public function down(): void
    {
        Schema::dropIfExists('transacciones_saga_inventario');
        Schema::dropIfExists('suministros_quimicos');
        Schema::dropIfExists('kits_epp');
        Schema::dropIfExists('estaciones_inventario');
    }
};
