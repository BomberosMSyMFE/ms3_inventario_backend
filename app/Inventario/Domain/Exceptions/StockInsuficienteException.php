<?php

declare(strict_types=1);

namespace App\Inventario\Domain\Exceptions;

use DomainException;

class StockInsuficienteException extends DomainException
{
    public static function sinKitsDisponibles(): self
    {
        return new self("No existen kits de protección EPP operativos con presión segura en almacén.");
    }

    public static function presionInsegura(string $codigo, int $bares): self
    {
        return new self("Peligro de asfixia: El kit {$codigo} posee solo {$bares} bares (Mínimo: 250 bares).");
    }

    public static function espumogenoInsuficiente(float $solicitado, float $disponible): self
    {
        return new self("Stock insuficiente de espumógeno químico. Solicitado: {$solicitado}L, Disponible: {$disponible}L.");
    }
}
