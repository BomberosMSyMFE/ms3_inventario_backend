<?php

declare(strict_types=1);

namespace App\Inventario\Domain\Model;

use App\Inventario\Domain\Exceptions\StockInsuficienteException;

class KitProteccionEPP
{
    public function __construct(
        private readonly string $id,
        private readonly string $codigo,
        private PresionOxigeno $presion,
        private string $estado,
        private ?string $camionAsignadoId = null,
        private string $estacionId = 'EST-01'
    ) {}

    public function reservarParaCamion(string $camionId): void
    {
        if ($this->estado !== 'DISPONIBLE') {
            throw new StockInsuficienteException(
                "El kit {$this->codigo} no está disponible (Estado: {$this->estado})."
            );
        }

        if (!$this->presion->esSeguraParaCombate()) {
            throw StockInsuficienteException::presionInsegura($this->codigo, $this->presion->bares);
        }

        $this->estado = 'ASIGNADO_CAMION';
        $this->camionAsignadoId = $camionId;
    }

    public function liberarReserva(): void
    {
        $this->estado = 'DISPONIBLE';
        $this->camionAsignadoId = null;
    }

    public function recargarPresion(int $nuevosBares): void
    {
        $this->presion = new PresionOxigeno($nuevosBares);
        if ($this->presion->esSeguraParaCombate() && $this->estado === 'MANTENIMIENTO_REQUERIDO') {
            $this->estado = 'DISPONIBLE';
        }
    }

    public function getId(): string { return $this->id; }
    public function getCodigo(): string { return $this->codigo; }
    public function getPresion(): PresionOxigeno { return $this->presion; }
    public function getEstado(): string { return $this->estado; }
    public function getCamionAsignadoId(): ?string { return $this->camionAsignadoId; }
    public function getEstacionId(): string { return $this->estacionId; }
}
