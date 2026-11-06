<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Inventario\Infrastructure\Persistence\Models\MovimientoInventarioEloquentModel;
use Illuminate\Support\Str;

class MantenimientoPredictivoIaService
{
    /**
     * Consulta al modelo de Machine Learning en ms5_ia_mcp_backend para evaluar el desgaste del equipo.
     * Si el servicio de IA no responde, ejecuta un fallback determinista seguro.
     */
    public static function evaluarDesgasteEquipo(
        string $codigo,
        string $nombre,
        string $tipo,
        float $horasUso,
        float $umbralFatiga,
        int $diasInspeccion = 30,
        int $sobrepresiones = 0,
        int $incidentes = 5
    ): array {
        $endpointUrl = env('MS05_IA_PREDICTIVE_URL', 'http://127.0.0.1:8005/api/v1/mantenimiento/predecir');

        $payload = [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'tipo_equipo' => strtoupper($tipo),
            'horas_uso_acumuladas' => (float)$horasUso,
            'umbral_fatiga_horas' => (float)$umbralFatiga,
            'dias_desde_inspeccion' => $diasInspeccion,
            'caudal_maximo_lpm' => 1500.0,
            'historial_presiones_altas' => $sobrepresiones,
            'incidentes_atendidos' => $incidentes,
        ];

        try {
            $response = Http::timeout(3)->post($endpointUrl, $payload);

            if ($response->successful()) {
                $datosIa = $response->json();
                Log::info("[IA-MAINTENANCE] Inferencia ML exitosa para {$codigo}: Estado {$datosIa['estado_recomendado']} - Riesgo {$datosIa['riesgo_fallo_porcentaje']}%");

                self::aplicarDecisionIaEnBaseDeDatos($codigo, $datosIa);

                return [
                    'exito' => true,
                    'motor' => $datosIa['modelo_ia'] ?? 'Random Forest Ensemble (ms5)',
                    'estadoRecomendado' => $datosIa['estado_recomendado'],
                    'riesgoFalloPorcentaje' => (float)$datosIa['riesgo_fallo_porcentaje'],
                    'diasVidaUtilRestantes' => (float)$datosIa['dias_vida_util_restantes'],
                    'requiereRetiroInmediato' => (bool)$datosIa['requiere_retiro_inmediato'],
                    'accionRecomendada' => $datosIa['accion_recomendada'],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("[IA-MAINTENANCE] ms5_ia no disponible ({$endpointUrl}): " . $e->getMessage() . ". Aplicando fallback heurístico local.");
        }

        // Fallback heurístico en caso de desconexión temporal de la IA
        $fatiga = round(($horasUso / max($umbralFatiga, 1.0)) * 100.0, 1);
        $requiereRetiro = ($horasUso >= $umbralFatiga);
        $estadoFallback = $requiereRetiro ? 'REQUERIR_MANTENIMIENTO_PREVENTIVO' : 'OPERATIVO';

        $datosFallback = [
            'estado_recomendado' => $estadoFallback,
            'requiere_retiro_inmediato' => $requiereRetiro,
            'accion_recomendada' => $requiereRetiro
                ? "El equipo superó el umbral de fatiga ({$horasUso}h / {$umbralFatiga}h). Se deriva a taller por regla de seguridad local."
                : "Equipo en rango operativo regular ({$fatiga}% fatiga).",
        ];

        if ($requiereRetiro) {
            self::aplicarDecisionIaEnBaseDeDatos($codigo, $datosFallback);
        }

        return [
            'exito' => false,
            'motor' => 'Regla Heurística Local (Fallback de Resiliencia)',
            'estadoRecomendado' => $estadoFallback,
            'riesgoFalloPorcentaje' => min(100.0, $fatiga),
            'diasVidaUtilRestantes' => max(0.0, ($umbralFatiga - $horasUso) * 1.5),
            'requiereRetiroInmediato' => $requiereRetiro,
            'accionRecomendada' => $datosFallback['accion_recomendada'],
        ];
    }

    /**
     * Aplica la transición de estado y registra auditoría en PostgreSQL
     */
    private static function aplicarDecisionIaEnBaseDeDatos(string $codigo, array $datosIa): void
    {
        $estadoRecomendado = $datosIa['estado_recomendado'] ?? 'OPERATIVO';
        $requiereRetiro = (bool)($datosIa['requiere_retiro_inmediato'] ?? false);

        if ($requiereRetiro) {
            $nuevoEstado = ($estadoRecomendado === 'DESCARTE_INMEDIATO')
                ? 'DE_BAJA'
                : 'MANTENIMIENTO_REQUERIDO';

            DB::table('herramientas_equipos')
                ->where('codigo', $codigo)
                ->update([
                    'estado' => $nuevoEstado,
                    'requiere_mantenimiento' => true,
                    'updated_at' => now(),
                ]);

            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => 'SALIDA_MANTENIMIENTO',
                'item_codigo' => $codigo,
                'item_nombre' => 'Equipo bomberil derivado por IA',
                'categoria' => 'HERRAMIENTA',
                'cantidad' => 1.0,
                'motivo' => "Derivación automática por Machine Learning: {$estadoRecomendado}",
                'responsable' => 'Agente IA Predictivo (ms5)',
            ]);
        }
    }
}
