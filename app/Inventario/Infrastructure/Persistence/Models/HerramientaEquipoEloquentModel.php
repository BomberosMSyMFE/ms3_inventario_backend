<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class HerramientaEquipoEloquentModel extends Model
{
    protected $table = 'herramientas_equipos';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'codigo',
        'nombre',
        'tipo',
        'horas_uso',
        'umbral_fatiga_horas',
        'estado',
        'estacion_id'
    ];

    protected $casts = [
        'horas_uso' => 'float',
        'umbral_fatiga_horas' => 'float',
    ];

    public function requiereMantenimientoPreventivo(): bool
    {
        return $this->horas_uso >= $this->umbral_fatiga_horas;
    }
}
