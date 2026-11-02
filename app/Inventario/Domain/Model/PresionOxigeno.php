<?php

declare(strict_types=1);

namespace App\Inventario\Domain\Model;

use InvalidArgumentException;

final readonly class PresionOxigeno
{
    public function __construct(public int $bares)
    {
        if ($this->bares < 0 || $this->bares > 350) {
            throw new InvalidArgumentException(
                "Presión fuera de rango operativo (0 a 350 bares): {$this->bares}"
            );
        }
    }

    public function esSeguraParaCombate(): bool
    {
        return $this->bares >= 250;
    }

    public function porcentajeCapacidad(): float
    {
        return round(($this->bares / 300.0) * 100, 1);
    }
}
