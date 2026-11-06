<?php

declare(strict_types=1);

namespace App\Inventario\Application\Services;

use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Domain\Exceptions\StockInsuficienteException;
use App\Inventario\Infrastructure\Persistence\Models\TransaccionSagaEloquentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReservarSuministrosService
{
    public function __construct(
        private readonly KitInventarioRepositoryInterface $kitRepo,
        private readonly SuministroQuimicoRepositoryInterface $quimicoRepo
    ) {}

    public function ejecutar(string $incidenteId, string $camionId, float $litrosEspumogeno): array
    {
        Log::info("[SAGA-RESERVA-INICIO] Petición de reserva recibida | Incidente: {$incidenteId} | Camión: {$camionId} | Espumógeno: {$litrosEspumogeno}L");

        return DB::transaction(function () use ($incidenteId, $camionId, $litrosEspumogeno) {
            // 1. BLINDAJE DE IDEMPOTENCIA (PUNTO_05_IA_LOCAL_OFFLINE.md Sección 6):
            // Si el orquestador reintenta por micro-corte de red con el mismo incidente y la reserva ya
            // está activa, se retorna el estado previo sin descontar doble stock ni bloquear otro kit.
            $txExistente = TransaccionSagaEloquentModel::where('incidente_id', $incidenteId)
                ->where('estado', 'RESERVADO')
                ->lockForUpdate()
                ->first();

            if ($txExistente) {
                Log::info("[SAGA-IDEMPOTENCIA] Petición duplicada interceptada para incidente '{$incidenteId}'. Reutilizando transacción '{$txExistente->transaccion_id}' sin doble descuento.");

                $kit = $txExistente->kit_reservado_id ? $this->kitRepo->buscarPorId($txExistente->kit_reservado_id) : null;
                $codigoKit = $kit ? $kit->getCodigo() : ($txExistente->kit_reservado_id ?? 'KIT-EPP-ACTIVO');
                $presionKit = $kit ? $kit->getPresion()->bares : 300;

                return [
                    'exito' => true,
                    'esIdempotente' => true,
                    'transaccionId' => $txExistente->transaccion_id,
                    'codigoError' => null,
                    'mensaje' => "Reserva recuperada de forma idempotente para el incidente {$incidenteId} (Transacción {$txExistente->transaccion_id}).",
                    'materialesReservados' => [
                        "KIT EPP: {$codigoKit} ({$presionKit} bares) [IDEMPOTENTE]",
                        "ESPUMOGENO AFFF: {$txExistente->litros_espumogeno_reservados} Litros [IDEMPOTENTE]"
                    ]
                ];
            }

            // 2. GENERACIÓN DE NUEVA RESERVA TRANSACCIONAL
            $txId = 'TX-SAGA-INV-' . strtoupper(Str::random(8));

            $kit = $this->kitRepo->buscarDisponibleParaCombate();
            if (!$kit) {
                Log::warning("[SAGA-RESERVA-RECHAZO] Incidente '{$incidenteId}': Rechazada por falta de kits EPP seguros (presión >= 250 bares).");
                return [
                    'exito' => false,
                    'esIdempotente' => false,
                    'transaccionId' => $txId,
                    'codigoError' => 'ERR_SIN_KITS_SEGUROS',
                    'mensaje' => 'No existen kits EPP con presión segura (>= 250 bares) disponibles en almacén.',
                    'materialesReservados' => []
                ];
            }

            $quimico = $this->quimicoRepo->buscarPorTipo('ESPUMOGENO_AFFF');
            if ($litrosEspumogeno > 0 && (!$quimico || $quimico->getLitrosDisponibles() < $litrosEspumogeno)) {
                $disponible = $quimico ? $quimico->getLitrosDisponibles() : 0.0;
                Log::warning("[SAGA-RESERVA-RECHAZO] Incidente '{$incidenteId}': Rechazada por stock insuficiente de espumógeno. Solicitado: {$litrosEspumogeno}L, Disponible: {$disponible}L.");
                return [
                    'exito' => false,
                    'esIdempotente' => false,
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

                    // Automatización n8n: Notificar quiebre si el stock post-reserva es crítico (<= 200 L)
                    \App\Inventario\Infrastructure\Services\AlertaQuiebreStockN8nService::verificarYNotificarQuiebre(
                        $quimico->getCodigo(),
                        $quimico->getNombre(),
                        $quimico->getLitrosDisponibles(),
                        (float) env('STOCK_CRITICO_UMBRAL_LITROS', 200.0),
                        $quimico->getEstacionId() ?? 'EST-01'
                    );
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

                // Automatización n8n: Notificar confirmación de reserva SAGA a WhatsApp
                \App\Inventario\Infrastructure\Services\AlertaQuiebreStockN8nService::notificarReservaSagaConfirmada(
                    $camionId,
                    $incidenteId,
                    $kit->getCodigo(),
                    (int)$kit->getPresion()->bares,
                    (float)$litrosEspumogeno,
                    $quimico ? ($quimico->getEstacionId() ?? 'EST-01') : 'EST-01'
                );

                Log::info("[SAGA-RESERVA-CONSOLIDADA] Transacción '{$txId}' consolidada. Kit '{$kit->getCodigo()}' ({$kit->getPresion()->bares} bar) asignado a '{$camionId}'. Espumógeno descontado: {$litrosEspumogeno}L.");

                return [
                    'exito' => true,
                    'esIdempotente' => false,
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
                    'esIdempotente' => false,
                    'transaccionId' => $txId,
                    'codigoError' => 'ERR_REGLA_DOMINIO',
                    'mensaje' => $e->getMessage(),
                    'materialesReservados' => []
                ];
            }
        });
    }
}
