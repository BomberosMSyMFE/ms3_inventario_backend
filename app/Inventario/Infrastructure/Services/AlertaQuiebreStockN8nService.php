<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertaQuiebreStockN8nService
{
    private static function getWebhookUrl(): string
    {
        return env('N8N_WEBHOOK_STOCK_ALERT_URL', 'http://127.0.0.1:5678/webhook/alerta-quiebre-stock');
    }

    private static function getTelefonoAdmin(): string
    {
        return env('WHATSAPP_ADMIN_PHONE', '+59160495035');
    }

    private static function despacharWebhook(array $payload, string $tipoLog, string $itemCodigo): array
    {
        $webhookUrl = self::getWebhookUrl();
        $emitida = false;
        $statusHttp = null;
        $mensajeFinal = "Alerta procesada.";

        // 1. Despacho hacia orquestador n8n
        try {
            $response = Http::timeout(2)->post($webhookUrl, $payload);
            $statusHttp = $response->status();
            if ($statusHttp >= 200 && $statusHttp < 300) {
                $emitida = true;
                $mensajeFinal = "Notificación despachada exitosamente hacia n8n y WhatsApp.";
                Log::info("[n8n-{$tipoLog}] Webhook enviado exitosamente a n8n {$webhookUrl} (HTTP {$statusHttp})");
            } else {
                Log::warning("[n8n-{$tipoLog}] n8n respondió con código {$statusHttp}");
            }
        } catch (\Throwable $e) {
            Log::warning("[n8n-{$tipoLog}] No se pudo conectar con webhook n8n en {$webhookUrl}: " . $e->getMessage());
        }

        // 2. Fallback Directo de Alta Disponibilidad hacia WhatsApp Gateway si n8n no despachó
        if (!$emitida) {
            $waGatewayUrl = env('WHATSAPP_GATEWAY_URL', 'http://127.0.0.1:8088/enviar');
            try {
                $waResponse = Http::timeout(3)->post($waGatewayUrl, [
                    'numero' => $payload['telefonoDestino'] ?? self::getTelefonoAdmin(),
                    'mensaje' => $payload['mensajeWhatsapp'] ?? "Alerta de {$itemCodigo}"
                ]);
                if ($waResponse->successful()) {
                    $emitida = true;
                    $statusHttp = $waResponse->status();
                    $mensajeFinal = "Notificación despachada directamente vía WhatsApp Gateway.";
                    Log::info("[WhatsApp-Gateway-Direct] Mensaje despachado exitosamente vía gateway {$waGatewayUrl}");
                }
            } catch (\Throwable $e) {
                Log::warning("[WhatsApp-Gateway] No se pudo conectar con gateway local: " . $e->getMessage());
            }
        }

        // 3. Notificación a Comandos MS-01 (Nataly)
        try {
            Http::timeout(3)->post('https://bomberos-uagrm-scz.duckdns.org/api/v1/alarmas/iniciar-triaje', [
                'tipo' => 'ALERTA_LOGISTICA',
                'origen' => 'MS-03 INVENTARIO',
                'mensaje' => $payload['mensajeWhatsapp'] ?? "Alerta de {$itemCodigo}",
                'prioridad' => 'CRITICA'
            ]);
        } catch (\Throwable $e) {
            // Ignorar si Nataly está offline
        }

        return [
            'emitida' => $emitida,
            'webhookUrl' => $webhookUrl,
            'statusHttp' => $statusHttp,
            'mensaje' => $mensajeFinal
        ];
    }

    /**
     * 1. Alerta corta de Quiebre de Stock Químico (<= 200 Litros)
     */
    public static function verificarYNotificarQuiebre(
        string $codigo,
        string $nombre,
        float $stockActual,
        float $umbralMinimo = 200.0,
        string $estacionId = 'EST-01'
    ): array {
        if ($stockActual <= $umbralMinimo) {
            $mensaje = "🚨 *ALERTA: STOCK CRÍTICO DE SUMINISTROS*\n"
                . "────────────────────────\n"
                . "📦 *Insumo:* {$nombre}\n"
                . "🏷️ *Código:* `{$codigo}`\n"
                . "📍 *Estación:* {$estacionId}\n"
                . "⚠️ *Nivel Actual:* *{$stockActual} Litros*\n"
                . "🛡️ *Mínimo Operativo:* {$umbralMinimo} Litros\n"
                . "────────────────────────\n"
                . "📋 *Acción Requerida:* Solicitar reposición urgente de almacén.";

            $payload = [
                'evento' => 'STOCK_CRITICO_DETECTADO',
                'modulo' => 'MS-03 Inventario y Recursos',
                'estacionId' => $estacionId,
                'telefonoDestino' => self::getTelefonoAdmin(),
                'mensajeWhatsapp' => $mensaje,
                'item' => [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'stockActual' => $stockActual,
                    'umbralMinimo' => $umbralMinimo,
                    'unidad' => 'Litros',
                ],
                'timestamp' => now()->toIso8601String(),
            ];

            return self::despacharWebhook($payload, 'STOCK-ALERT', $codigo);
        }

        return [
            'emitida' => false,
            'webhookUrl' => null,
            'statusHttp' => null,
            'mensaje' => "Stock de {$codigo} ({$stockActual}L) superior al umbral crítico ({$umbralMinimo}L)."
        ];
    }

    /**
     * 2. Alerta corta de Mantenimiento Preventivo de Herramienta / Maquinaria (IA)
     */
    public static function notificarMantenimientoRequerido(
        string $codigo,
        string $nombre,
        float $horasUso,
        float $umbralFatiga,
        string $diagnosticoIa = 'MANTENIMIENTO_PREVENTIVO',
        string $estacionId = 'EST-01'
    ): array {
        $mensaje = "🛠️ *ALERTA: MANTENIMIENTO PREDICTIVO (IA)*\n"
            . "────────────────────────\n"
            . "⚙️ *Equipo:* {$nombre}\n"
            . "🏷️ *Código:* `{$codigo}`\n"
            . "📍 *Estación:* {$estacionId}\n"
            . "⏱️ *Horas Acumuladas:* *{$horasUso} hrs* (Límite: {$umbralFatiga} hrs)\n"
            . "🤖 *Diagnóstico IA:* *{$diagnosticoIa}*\n"
            . "────────────────────────\n"
            . "🔧 *Acción Requerida:* Equipo derivado a taller para servicio técnico.";

        $payload = [
            'evento' => 'MANTENIMIENTO_PREVENTIVO_DETECTADO',
            'modulo' => 'MS-03 Inventario y Recursos',
            'estacionId' => $estacionId,
            'telefonoDestino' => self::getTelefonoAdmin(),
            'mensajeWhatsapp' => $mensaje,
            'item' => [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'stockActual' => $horasUso,
                'umbralMinimo' => $umbralFatiga,
                'unidad' => 'Horas de Uso',
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return self::despacharWebhook($payload, 'MANTENIMIENTO-TOOL', $codigo);
    }

    /**
     * 3. Alerta corta de Kit EPP con Baja Presión (< 250 Bares)
     */
    public static function notificarKitBajaPresion(
        string $codigo,
        int $presionBares,
        string $estacionId = 'EST-01'
    ): array {
        $mensaje = "🤿 *ALERTA: KIT EPP NO APTO PARA COMBATE*\n"
            . "────────────────────────\n"
            . "🫁 *Equipo:* Kit Respiración Autónoma (ERA)\n"
            . "🏷️ *Código:* `{$codigo}`\n"
            . "📍 *Estación:* {$estacionId}\n"
            . "📉 *Presión Manómetro:* *{$presionBares} Bares*\n"
            . "🛡️ *Mínimo de Seguridad:* 250 Bares\n"
            . "────────────────────────\n"
            . "⚠️ *Estado:* Retirado de servicio. Enviar a compresor para recarga.";

        $payload = [
            'evento' => 'EPP_BAJA_PRESION_MANTENIMIENTO',
            'modulo' => 'MS-03 Inventario y Recursos',
            'estacionId' => $estacionId,
            'telefonoDestino' => self::getTelefonoAdmin(),
            'mensajeWhatsapp' => $mensaje,
            'item' => [
                'codigo' => $codigo,
                'nombre' => "Kit EPP {$codigo}",
                'stockActual' => $presionBares,
                'umbralMinimo' => 250,
                'unidad' => 'Bares de Oxígeno',
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return self::despacharWebhook($payload, 'EPP-ALERT', $codigo);
    }

    /**
     * 4. Alerta corta de Confirmación de Reserva SAGA
     */
    public static function notificarReservaSagaConfirmada(
        string $camionId,
        string $incidenteId,
        string $kitCodigo,
        int $presionBares,
        float $litrosEspuma,
        string $estacionId = 'EST-01'
    ): array {
        $mensaje = "📦 *RESERVA SAGA CONFIRMADA*\n"
            . "────────────────────────\n"
            . "🚒 *Camión Despachado:* *{$camionId}*\n"
            . "🔥 *Incidente Asignado:* *#{$incidenteId}*\n"
            . "📍 *Estación:* {$estacionId}\n"
            . "🤿 *Kit Asignado:* `{$kitCodigo}` ({$presionBares} Bares)\n"
            . "🧪 *Espumógeno:* *{$litrosEspuma} Litros*\n"
            . "────────────────────────\n"
            . "✅ *Estado:* Recursos bloqueados y listos para salida inmediata.";

        $payload = [
            'evento' => 'SAGA_RESERVA_CONFIRMADA',
            'modulo' => 'MS-03 Inventario y Recursos',
            'estacionId' => $estacionId,
            'telefonoDestino' => self::getTelefonoAdmin(),
            'mensajeWhatsapp' => $mensaje,
            'item' => [
                'codigo' => $kitCodigo,
                'nombre' => "Kit {$kitCodigo} y {$litrosEspuma}L Espumógeno",
                'stockActual' => $litrosEspuma,
                'umbralMinimo' => 200,
                'unidad' => 'Materiales de Combate',
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return self::despacharWebhook($payload, 'SAGA-RESERVA', $kitCodigo);
    }

    /**
     * 5. Alerta corta de Compensación SAGA (Rollback / Cancelación)
     */
    public static function notificarCompensacionSaga(
        string $camionId,
        string $incidenteId,
        string $txId,
        string $estacionId = 'EST-01'
    ): array {
        $mensaje = "↩️ *COMPENSACIÓN SAGA (ROLLBACK)*\n"
            . "────────────────────────\n"
            . "🚒 *Unidad Revertida:* *{$camionId}*\n"
            . "🔥 *Incidente:* #{$incidenteId}\n"
            . "📍 *Estación:* {$estacionId}\n"
            . "🔄 *Transacción:* `{$txId}`\n"
            . "────────────────────────\n"
            . "♻️ *Acción:* Kit EPP y espumógeno reintegrados a stock disponible.";

        $payload = [
            'evento' => 'SAGA_COMPENSACION_EJECUTADA',
            'modulo' => 'MS-03 Inventario y Recursos',
            'estacionId' => $estacionId,
            'telefonoDestino' => self::getTelefonoAdmin(),
            'mensajeWhatsapp' => $mensaje,
            'item' => [
                'codigo' => $txId,
                'nombre' => "Compensación SAGA {$camionId}",
                'stockActual' => 0,
                'umbralMinimo' => 0,
                'unidad' => 'Transacción SAGA',
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return self::despacharWebhook($payload, 'SAGA-ROLLBACK', $txId);
    }
}
