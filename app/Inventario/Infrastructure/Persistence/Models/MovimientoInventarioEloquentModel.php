<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoInventarioEloquentModel extends Model
{
    protected $table = 'movimientos_inventario';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tipo_movimiento',
        'item_codigo',
        'item_nombre',
        'categoria',
        'cantidad',
        'motivo',
        'responsable'
    ];

    protected $casts = [
        'cantidad' => 'float',
    ];
}
