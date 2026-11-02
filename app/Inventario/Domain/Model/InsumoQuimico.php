<?php

declare(strict_types=1);

namespace App\Inventario\Domain\Model;

use App\Inventario\Domain\Exceptions\StockInsuficienteException;

class InsumoQuimico
{
    public function __construct(
        private readonly string $id,
        private readonly string $codigo,
        private readonly string $nombre,
        private readonly string $tipo,
        private float $litrosDisponibles,
        private readonly string $lote,
        private readonly string $fechaCaducidad,
        private readonly string $estacionId = 'EST-01'
    ) {}

    public function descontarStock(float $litrosADescontar): void
    {
        if ($this->litrosDisponibles < $litrosADescontar) {
            throw StockInsuficienteException::espumogenoInsuficiente(
                $litrosADescontar,
                $this->litrosDisponibles
            );
        }

        $this->litrosDisponibles -= $litrosADescontar;
    }

    public function reponerStock(float $litrosAReponer): void
    {
        $this->litrosDisponibles += $litrosAReponer;
    }

    public function getId(): string { return $this->id; }
    public function getCodigo(): string { return $this->codigo; }
    public function getNombre(): string { return $this->nombre; }
    public function getTipo(): string { return $this->tipo; }
    public function getLitrosDisponibles(): float { return $this->litrosDisponibles; }
    public function getLote(): string { return $this->lote; }
    public function getFechaCaducidad(): string { return $this->fechaCaducidad; }
    public function getEstacionId(): string { return $this->estacionId; }
}
