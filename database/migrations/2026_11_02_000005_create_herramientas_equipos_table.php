<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('herramientas_equipos')) {
            Schema::create('herramientas_equipos', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('codigo')->unique();
                $table->string('nombre');
                $table->string('tipo'); // MOTOBOMBA, MOTOSIERRA, GENERADOR, EXTRACTOR, MANGUERA
                $table->decimal('horas_uso', 8, 2)->default(0.00);
                $table->decimal('umbral_fatiga_horas', 8, 2)->default(100.00);
                $table->string('estado')->default('DISPONIBLE');
                $table->string('estacion_id')->default('EST-01');
                $table->foreign('estacion_id')->references('id')->on('estaciones_inventario')->onDelete('cascade');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('herramientas_equipos');
    }
};
