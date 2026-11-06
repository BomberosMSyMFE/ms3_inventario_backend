<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categorias_inventario')) {
            Schema::create('categorias_inventario', function (Blueprint $table) {
                $table->string('id')->primary(); // QUIMICO, EPP, HERRAMIENTA, REPUESTO
                $table->string('nombre');
                $table->text('descripcion')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_inventario');
    }
};
