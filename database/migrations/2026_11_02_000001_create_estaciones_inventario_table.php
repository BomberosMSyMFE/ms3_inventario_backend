<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('estaciones_inventario')) {
            Schema::create('estaciones_inventario', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('nombre');
                $table->string('direccion');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estaciones_inventario');
    }
};
