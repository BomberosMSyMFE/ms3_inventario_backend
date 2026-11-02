<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence;

use App\Inventario\Domain\Model\InsumoQuimico;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Infrastructure\Persistence\Models\SuministroQuimicoEloquentModel;

class EloquentSuministroQuimicoRepository implements SuministroQuimicoRepositoryInterface
{
    public function buscarPorTipo(string $tipo, ?string $estacionId = null): ?InsumoQuimico
    {
        $query = SuministroQuimicoEloquentModel::where('tipo', $tipo)
            ->where('litros_disponibles', '>', 0);

        if ($estacionId) {
            $query->where('estacion_id', $estacionId);
        }

        $registro = $query->first();
        if (!$registro) {
            return null;
        }

        return $this->toDomain($registro);
    }

    public function buscarPorId(string $id): ?InsumoQuimico
    {
        $registro = SuministroQuimicoEloquentModel::find($id);
        if (!$registro) {
            return null;
        }
        return $this->toDomain($registro);
    }

    public function guardar(InsumoQuimico $insumo): void
    {
        SuministroQuimicoEloquentModel::updateOrCreate(
            ['id' => $insumo->getId()],
            [
                'codigo' => $insumo->getCodigo(),
                'nombre' => $insumo->getNombre(),
                'tipo' => $insumo->getTipo(),
                'litros_disponibles' => $insumo->getLitrosDisponibles(),
                'lote' => $insumo->getLote(),
                'fecha_caducidad' => $insumo->getFechaCaducidad(),
                'estacion_id' => $insumo->getEstacionId(),
            ]
        );
    }

    public function listarTodos(?string $estacionId = null): array
    {
        $query = SuministroQuimicoEloquentModel::query();
        if ($estacionId) {
            $query->where('estacion_id', $estacionId);
        }

        return $query->get()->map(fn($r) => $this->toDomain($r))->all();
    }

    private function toDomain(SuministroQuimicoEloquentModel $model): InsumoQuimico
    {
        return new InsumoQuimico(
            id: (string)$model->id,
            codigo: (string)$model->codigo,
            nombre: (string)$model->nombre,
            tipo: (string)$model->tipo,
            litrosDisponibles: (float)$model->litros_disponibles,
            lote: (string)$model->lote,
            fechaCaducidad: (string)$model->fecha_caducidad,
            estacionId: (string)$model->estacion_id
        );
    }
}
