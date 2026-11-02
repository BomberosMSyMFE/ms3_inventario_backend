<?php

declare(strict_types=1);

namespace App\Inventario\Application\Services;

use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Domain\Exceptions\StockInsuficienteException;
use App\Inventario\Infrastructure\Persistence\Models\TransaccionSagaEloquentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservarSuministrosService
{
    public function __construct(
        private readonly KitInventarioRepositoryInterface $kitRepo,
        private readonly SuministroQuimicoRepositoryInterface $quimicoRepo
    ) {}

    public function ejecutar(string $incidenteId, string $camionId, float $litrosEspumogeno): array
    {
        $txId = 'TX-SAGA-INV-' . strtoupper(Str::random(8));

        return DB::transaction(function () use ($incidenteId, $camionId, $litrosEspumogeno, $txId) {
            $kit = $this->kitRepo->buscarDisponibleParaCombate();
            if (!$kit) {
                return [
                    'exito' => false,
                    'transaccionId' => $txId,
                    'codigoError' => 'ERR_SIN_KITS_SEGUROS',
                    'mensaje' => 'No existen kits EPP con presión segura (>= 250 bares) disponibles en almacén.',
                    'materialesReservados' => []
                ];
            }

            $quimico = $this->quimicoRepo->buscarPorTipo('ESPUMOGENO_AFFF');
            if ($litrosEspumogeno > 0 && (!$quimico || $quimico->getLitrosDisponibles() < $litrosEspumogeno)) {
                $disponible = $quimico ? $quimico->getLitrosDisponibles() : 0.0;
                return [
                    'exito' => false,
                    'transaccionId' => $txId,
                    'codigoError' => 'ERR_STOCK_QUIMICO_INSUFICIENTE',
                    'mensaje' => "Stock insuficiente de espumógeno. Solicitado: {$litrosEspumogeno}L, Disponible: {$disponible}L.",
                    'materialesReservados' => []
                ];
            }

            try {
                $kit->reservarParaCamion($camionId);
                $this->kitRepo->guardar($kit);

                if ($quimico && $litrosEspumogeno > 0) {
                    $quimico->descontarStock($litrosEspumogeno);
                    $this->quimicoRepo->guardar($quimico);
                }

                TransaccionSagaEloquentModel::create([
                    'id' => Str::uuid()->toString(),
                    'transaccion_id' => $txId,
                    'incidente_id' => $incidenteId,
                    'camion_id' => $camionId,
                    'kit_reservado_id' => $kit->getId(),
                    'litros_espumogeno_reservados' => $litrosEspumogeno,
                    'estado' => 'RESERVADO'
                ]);

                return [
                    'exito' => true,
                    'transaccionId' => $txId,
                    'codigoError' => null,
                    'mensaje' => 'Suministros y Kit EPP reservados exitosamente.',
                    'materialesReservados' => [
                        "KIT EPP: {$kit->getCodigo()} ({$kit->getPresion()->bares} bares)",
                        "ESPUMOGENO AFFF: {$litrosEspumogeno} Litros"
                    ]
                ];
            } catch (StockInsuficienteException $e) {
                return [
                    'exito' => false,
                    'transaccionId' => $txId,
                    'codigoError' => 'ERR_REGLA_DOMINIO',
                    'mensaje' => $e->getMessage(),
                    'materialesReservados' => []
                ];
            }
        });
    }
}
