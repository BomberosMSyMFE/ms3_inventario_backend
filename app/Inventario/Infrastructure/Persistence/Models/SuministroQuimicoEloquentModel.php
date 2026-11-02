<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class SuministroQuimicoEloquentModel extends Model
{
    protected $table = 'suministros_quimicos';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'codigo',
        'nombre',
        'tipo',
        'litros_disponibles',
        'lote',
        'fecha_caducidad',
        'estacion_id'
    ];
}
