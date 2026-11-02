<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Persistence;

use App\Inventario\Domain\Model\KitProteccionEPP;
use App\Inventario\Domain\Model\PresionOxigeno;
use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Infrastructure\Persistence\Models\KitEPPEloquentModel;

class EloquentKitInventarioRepository implements KitInventarioRepositoryInterface
{
    public function buscarDisponibleParaCombate(?string $estacionId = null): ?KitProteccionEPP
    {
        $query = KitEPPEloquentModel::where('estado', 'DISPONIBLE')
            ->where('presion_oxigeno_bares', '>=', 250);

        if ($estacionId) {
            $query->where('estacion_id', $estacionId);
        }

        $registro = $query->first();
        if (!$registro) {
            return null;
        }

        return $this->toDomain($registro);
    }

    public function buscarPorId(string $id): ?KitProteccionEPP
    {
        $registro = KitEPPEloquentModel::find($id);
        if (!$registro) {
            return null;
        }
        return $this->toDomain($registro);
    }

    public function buscarPorCodigo(string $codigo): ?KitProteccionEPP
    {
        $registro = KitEPPEloquentModel::where('codigo', $codigo)->first();
        if (!$registro) {
            return null;
        }
        return $this->toDomain($registro);
    }

    public function guardar(KitProteccionEPP $kit): void
    {
        KitEPPEloquentModel::updateOrCreate(
            ['id' => $kit->getId()],
            [
                'codigo' => $kit->getCodigo(),
                'presion_oxigeno_bares' => $kit->getPresion()->bares,
                'estado' => $kit->getEstado(),
                'camion_asignado_id' => $kit->getCamionAsignadoId(),
                'estacion_id' => $kit->getEstacionId(),
            ]
        );
    }

    public function listarPorEstacion(string $estacionId): array
    {
        $registros = KitEPPEloquentModel::where('estacion_id', $estacionId)->get();
        return $registros->map(fn($r) => $this->toDomain($r))->all();
    }

    private function toDomain(KitEPPEloquentModel $model): KitProteccionEPP
    {
        return new KitProteccionEPP(
            id: (string)$model->id,
            codigo: (string)$model->codigo,
            presion: new PresionOxigeno((int)$model->presion_oxigeno_bares),
            estado: (string)$model->estado,
            camionAsignadoId: $model->camion_asignado_id,
            estacionId: (string)$model->estacion_id
        );
    }
}
