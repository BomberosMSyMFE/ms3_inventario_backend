<?php

declare(strict_types=1);

namespace App\Inventario\Application\Services;

use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Infrastructure\Persistence\Models\TransaccionSagaEloquentModel;
use Illuminate\Support\Facades\DB;

class LiberarSuministrosService
{
    public function __construct(
        private readonly KitInventarioRepositoryInterface $kitRepo,
        private readonly SuministroQuimicoRepositoryInterface $quimicoRepo
    ) {}

    public function ejecutar(string $transaccionId): array
    {
        return DB::transaction(function () use ($transaccionId) {
            $tx = TransaccionSagaEloquentModel::where('transaccion_id', $transaccionId)->first();
            if (!$tx) {
                return [
                    'exito' => false,
                    'mensaje' => "Transacción {$transaccionId} no encontrada."
                ];
            }

            if ($tx->estado === 'COMPENSADO_LIBERADO') {
                return [
                    'exito' => true,
                    'mensaje' => "La transacción {$transaccionId} ya fue compensada previamente."
                ];
            }

            if ($tx->kit_reservado_id) {
                $kit = $this->kitRepo->buscarPorId($tx->kit_reservado_id);
                if ($kit) {
                    $kit->liberarReserva();
                    $this->kitRepo->guardar($kit);
                }
            }

            if ($tx->litros_espumogeno_reservados > 0) {
                $quimico = $this->quimicoRepo->buscarPorTipo('ESPUMOGENO_AFFF');
                if ($quimico) {
                    $quimico->reponerStock((float)$tx->litros_espumogeno_reservados);
                    $this->quimicoRepo->guardar($quimico);
                }
            }

            $tx->estado = 'COMPENSADO_LIBERADO';
            $tx->save();

            return [
                'exito' => true,
                'mensaje' => "Compensación SAGA ejecutada: materiales liberados."
            ];
        });
    }
}
