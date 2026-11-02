<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\GraphQL\Resolvers;

use App\Inventario\Application\Services\ReservarSuministrosService;
use App\Inventario\Application\Services\LiberarSuministrosService;
use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SuministrosResolver
{
    public function __construct(
        private readonly ReservarSuministrosService $reservaService,
        private readonly LiberarSuministrosService $liberacionService,
        private readonly KitInventarioRepositoryInterface $kitRepo,
        private readonly SuministroQuimicoRepositoryInterface $quimicoRepo
    ) {}

    public function auditoriaStockGeneral($_, array $args): array
    {
        $estacionId = $args['estacionId'] ?? null;
        $insumos = $this->quimicoRepo->listarTodos($estacionId);

        return array_map(function ($i) {
            return [
                'id' => $i->getId(),
                'codigo' => $i->getCodigo(),
                'nombre' => $i->getNombre(),
                'tipo' => $i->getTipo(),
                'litrosDisponibles' => $i->getLitrosDisponibles(),
                'lote' => $i->getLote(),
                'fechaCaducidad' => $i->getFechaCaducidad(),
            ];
        }, $insumos);
    }

    public function obtenerInventarioEstacion($_, array $args): ?array
    {
        $estacionId = $args['estacionId'];
        $estacion = DB::table('estaciones_inventario')->where('id', $estacionId)->first();
        if (!$estacion) {
            return null;
        }

        $kits = $this->kitRepo->listarPorEstacion($estacionId);
        $insumos = $this->quimicoRepo->listarTodos($estacionId);

        return [
            'estacionId' => $estacion->id,
            'nombreEstacion' => $estacion->nombre,
            'kitsDisponibles' => array_map(function ($k) {
                return [
                    'id' => $k->getId(),
                    'codigo' => $k->getCodigo(),
                    'presionOxigenoBares' => $k->getPresion()->bares,
                    'esSeguroParaCombate' => $k->getPresion()->esSeguraParaCombate(),
                    'estado' => $k->getEstado(),
                    'camionAsignadoId' => $k->getCamionAsignadoId(),
                ];
            }, $kits),
            'suministrosQuimicos' => array_map(function ($i) {
                return [
                    'id' => $i->getId(),
                    'codigo' => $i->getCodigo(),
                    'nombre' => $i->getNombre(),
                    'tipo' => $i->getTipo(),
                    'litrosDisponibles' => $i->getLitrosDisponibles(),
                    'lote' => $i->getLote(),
                    'fechaCaducidad' => $i->getFechaCaducidad(),
                ];
            }, $insumos),
        ];
    }

    public function consultarKitEPP($_, array $args): ?array
    {
        $kit = $this->kitRepo->buscarPorCodigo($args['codigo']);
        if (!$kit) {
            return null;
        }

        return [
            'id' => $kit->getId(),
            'codigo' => $kit->getCodigo(),
            'presionOxigenoBares' => $kit->getPresion()->bares,
            'esSeguroParaCombate' => $kit->getPresion()->esSeguraParaCombate(),
            'estado' => $kit->getEstado(),
            'camionAsignadoId' => $kit->getCamionAsignadoId(),
        ];
    }

    public function reservarKitParaDespacho($_, array $args): array
    {
        return $this->reservaService->ejecutar(
            (string)$args['incidenteId'],
            (string)$args['camionId'],
            (float)$args['litrosEspumogeno']
        );
    }

    public function liberarReservaCompensatoria($_, array $args): array
    {
        return $this->liberacionService->ejecutar((string)$args['transaccionId']);
    }
}
