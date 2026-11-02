<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class KitEPPEloquentModel extends Model
{
    protected $table = 'kits_epp';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'codigo',
        'presion_oxigeno_bares',
        'estado',
        'camion_asignado_id',
        'estacion_id'
    ];
}
