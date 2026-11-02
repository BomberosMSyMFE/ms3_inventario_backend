<?php

declare(strict_types=1);

namespace App\Inventario\Domain\Repositories;

use App\Inventario\Domain\Model\KitProteccionEPP;

interface KitInventarioRepositoryInterface
{
    public function buscarDisponibleParaCombate(?string $estacionId = null): ?KitProteccionEPP;

    public function buscarPorId(string $id): ?KitProteccionEPP;

    public function buscarPorCodigo(string $codigo): ?KitProteccionEPP;

    public function guardar(KitProteccionEPP $kit): void;

    /**
     * @return KitProteccionEPP[]
     */
    public function listarPorEstacion(string $estacionId): array;
}
