<?php

declare(strict_types=1);

namespace App\Inventario\Domain\Repositories;

use App\Inventario\Domain\Model\InsumoQuimico;

interface SuministroQuimicoRepositoryInterface
{
    public function buscarPorTipo(string $tipo, ?string $estacionId = null): ?InsumoQuimico;

    public function buscarPorId(string $id): ?InsumoQuimico;

    public function guardar(InsumoQuimico $insumo): void;

    /**
     * @return InsumoQuimico[]
     */
    public function listarTodos(?string $estacionId = null): array;
}
