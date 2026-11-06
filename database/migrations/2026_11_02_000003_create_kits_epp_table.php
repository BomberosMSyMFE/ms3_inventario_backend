<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kits_epp')) {
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
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kits_epp');
    }
};
