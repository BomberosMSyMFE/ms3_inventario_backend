<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class TransaccionSagaEloquentModel extends Model
{
    protected $table = 'transacciones_saga_inventario';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transaccion_id',
        'incidente_id',
        'camion_id',
        'kit_reservado_id',
        'litros_espumogeno_reservados',
        'estado'
    ];
}
