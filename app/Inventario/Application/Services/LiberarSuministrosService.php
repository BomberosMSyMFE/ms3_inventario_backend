<?php

declare(strict_types=1);

namespace App\Inventario\Application\Services;

use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Infrastructure\Persistence\Models\TransaccionSagaEloquentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiberarSuministrosService
{
    public function __construct(
        private readonly KitInventarioRepositoryInterface $kitRepo,
        private readonly SuministroQuimicoRepositoryInterface $quimicoRepo
    ) {}

    public function ejecutar(string $transaccionId): array
    {
        Log::info("[SAGA-COMPENSACION-INICIO] Solicitud de liberación compensatoria para transacción '{$transaccionId}'.");

        return DB::transaction(function () use ($transaccionId) {
            $tx = TransaccionSagaEloquentModel::where('transaccion_id', $transaccionId)
                ->lockForUpdate()
                ->first();

            if (!$tx) {
                Log::warning("[SAGA-COMPENSACION-FALLO] Transacción '{$transaccionId}' no existe en inventario.");
                return [
                    'exito' => false,
                    'esIdempotente' => false,
                    'mensaje' => "Transacción SAGA '{$transaccionId}' no encontrada en el registro de inventario."
                ];
            }

            // BLINDAJE DE IDEMPOTENCIA: Si ya fue compensada, responder éxito sin duplicar la reposición de químicos
            if ($tx->estado === 'COMPENSADO_LIBERADO') {
                Log::info("[SAGA-IDEMPOTENCIA] Compensación repetida interceptada para transacción '{$transaccionId}'. No se duplica reposición de stock.");
                return [
                    'exito' => true,
                    'esIdempotente' => true,
                    'mensaje' => "La transacción {$transaccionId} ya fue compensada previamente (Idempotente)."
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

            // Automatización n8n: Notificar compensación SAGA a WhatsApp
            \App\Inventario\Infrastructure\Services\AlertaQuiebreStockN8nService::notificarCompensacionSaga(
                $tx->camion_id ?? 'CAM-01',
                $tx->incidente_id ?? 'INC-01',
                $tx->transaccion_id
            );

            Log::info("[SAGA-COMPENSACION] Transacción '{$transaccionId}' compensada exitosamente: Materiales y stock reincorporados.");

            return [
                'exito' => true,
                'esIdempotente' => false,
                'mensaje' => "Compensación SAGA ejecutada: materiales liberados y stock reincorporado."
            ];
        });
    }
}
