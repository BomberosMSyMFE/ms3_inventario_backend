<?php

declare(strict_types=1);

namespace App\Inventario\Infrastructure\GraphQL\Resolvers;

use App\Inventario\Application\Services\ReservarSuministrosService;
use App\Inventario\Application\Services\LiberarSuministrosService;
use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Domain\Model\PresionOxigeno;
use App\Inventario\Infrastructure\Persistence\Models\HerramientaEquipoEloquentModel;
use App\Inventario\Infrastructure\Persistence\Models\MovimientoInventarioEloquentModel;
use App\Inventario\Infrastructure\Persistence\Models\TransaccionSagaEloquentModel;
use App\Inventario\Infrastructure\Services\AlertaQuiebreStockN8nService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SuministrosResolver
{
    public function __construct(
        private readonly ReservarSuministrosService $reservaService,
        private readonly LiberarSuministrosService $liberacionService,
        private readonly KitInventarioRepositoryInterface $kitRepo,
        private readonly SuministroQuimicoRepositoryInterface $quimicoRepo
    ) {}

    // =========================================================================
    // 1. CONTRATOS SAGA Y QUERIES BASE EXISTENTES (100% INTACTOS)
    // =========================================================================

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
        Log::info("[GRAPHQL-SAGA] Ejecutando mutation reservarKitParaDespacho | Incidente: {$args['incidenteId']} | Camión: {$args['camionId']} | Espumógeno: {$args['litrosEspumogeno']}L");

        $res = $this->reservaService->ejecutar(
            (string)$args['incidenteId'],
            (string)$args['camionId'],
            (float)$args['litrosEspumogeno']
        );

        if ($res['exito']) {
            Log::info("[GRAPHQL-SAGA-OK] Reserva aprobada exitosamente | Tx: {$res['transaccionId']} | Materiales: " . implode(', ', $res['materialesReservados'] ?? []));
        } else {
            Log::warning("[GRAPHQL-SAGA-FALLO] Reserva rechazada | Error: " . ($res['codigoError'] ?? 'DESCONOCIDO') . " | Mensaje: {$res['mensaje']}");
        }

        // Si fue una reserva exitosa y NO es un reintento idempotente, se registra el movimiento y la alerta
        if ($res['exito'] && !($res['esIdempotente'] ?? false)) {
            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => 'RESERVA_SAGA',
                'item_codigo' => 'ESPUMOGENO_AFFF',
                'item_nombre' => 'Reserva SAGA para Cisterna ' . $args['camionId'],
                'categoria' => 'QUIMICO',
                'cantidad' => (float)$args['litrosEspumogeno'],
                'motivo' => "Despacho SAGA Incidente {$args['incidenteId']}",
                'responsable' => 'MS-01 Comandos Nataly',
            ]);

            // Verificación automatizada n8n: Alerta si el espumógeno cae por debajo del umbral de seguridad
            $quim = DB::table('suministros_quimicos')->where('tipo', 'ESPUMOGENO_AFFF')->first();
            if ($quim) {
                AlertaQuiebreStockN8nService::verificarYNotificarQuiebre(
                    $quim->codigo,
                    $quim->nombre,
                    (float)$quim->litros_disponibles,
                    200.0,
                    $quim->estacion_id
                );
            }
        }

        return $res;
    }

    public function liberarReservaCompensatoria($_, array $args): array
    {
        Log::info("[GRAPHQL-SAGA] Ejecutando mutation liberarReservaCompensatoria | Tx: {$args['transaccionId']}");

        $res = $this->liberacionService->ejecutar((string)$args['transaccionId']);

        if ($res['exito']) {
            Log::info("[GRAPHQL-SAGA-OK] Transacción {$args['transaccionId']} compensada exitosamente.");
        } else {
            Log::warning("[GRAPHQL-SAGA-FALLO] Error en compensación de {$args['transaccionId']}: {$res['mensaje']}");
        }

        // Solo registrar movimiento de auditoría si fue una compensación real nueva (no repetida)
        if ($res['exito'] && !($res['esIdempotente'] ?? false)) {
            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => 'COMPENSACION_SAGA',
                'item_codigo' => 'ESPUMOGENO_AFFF',
                'item_nombre' => 'Compensación SAGA Rollback',
                'categoria' => 'QUIMICO',
                'cantidad' => 0.0,
                'motivo' => "Reversión transaccional para {$args['transaccionId']}",
                'responsable' => 'MS-01 Comandos Nataly',
            ]);
        }

        return $res;
    }

    // =========================================================================
    // 2. KPIS Y DASHBOARD DE LOGÍSTICA
    // =========================================================================

    public function resumenDashboardLogistica(): array
    {
        $kits = DB::table('kits_epp')->get();
        $totalKits = $kits->count();
        $kitsSeguros = $kits->where('presion_oxigeno_bares', '>=', 250)->where('estado', '!=', 'MANTENIMIENTO_REQUERIDO')->count();

        $totalQuimicosLitros = (float)DB::table('suministros_quimicos')->sum('litros_disponibles');

        $herramientas = DB::table('herramientas_equipos')->get();
        $totalHerramientas = $herramientas->count();

        $herramientasFatigadas = $herramientas->filter(function ($h) {
            return $h->horas_uso >= $h->umbral_fatiga_horas || $h->estado === 'MANTENIMIENTO_REQUERIDO';
        })->count();

        $kitsBajaPresion = $kits->filter(function ($k) {
            return $k->presion_oxigeno_bares < 250 || $k->estado === 'MANTENIMIENTO_REQUERIDO';
        })->count();

        $reservasActivas = DB::table('transacciones_saga_inventario')->where('estado', 'RESERVADO')->count();

        return [
            'totalKits' => $totalKits,
            'kitsSeguros' => $kitsSeguros,
            'totalQuimicosLitros' => $totalQuimicosLitros,
            'totalHerramientas' => $totalHerramientas,
            'equiposMantenimiento' => $herramientasFatigadas + $kitsBajaPresion,
            'reservasActivas' => $reservasActivas,
        ];
    }

    // =========================================================================
    // 3. CONSULTAS UNIFICADAS PARA PESTAÑAS (TABS)
    // =========================================================================

    public function listarInventarioGeneral($_, array $args): array
    {
        $catFiltro = $args['categoria'] ?? null;
        $estadoFiltro = $args['estado'] ?? null;

        $items = [];

        // 1. Químicos
        if (!$catFiltro || $catFiltro === 'QUIMICO') {
            $quims = DB::table('suministros_quimicos')->get();
            foreach ($quims as $q) {
                $estado = (strtotime($q->fecha_caducidad) < time()) ? 'VENCIDO' : 'DISPONIBLE';
                if ($estadoFiltro && $estado !== $estadoFiltro) continue;

                $items[] = [
                    'id' => $q->id,
                    'codigo' => $q->codigo,
                    'nombre' => $q->nombre,
                    'categoria' => 'QUIMICO',
                    'estado' => $estado,
                    'stockOVolumen' => (float)$q->litros_disponibles,
                    'unidadMedida' => 'Litros',
                    'detallesExtra' => "Lote: {$q->lote} | Vence: {$q->fecha_caducidad}",
                    'estacionId' => $q->estacion_id,
                    'requiereAtencion' => ($q->litros_disponibles < 250 || $estado === 'VENCIDO'),
                ];
            }
        }

        // 2. Kits EPP
        if (!$catFiltro || $catFiltro === 'EPP') {
            $kits = DB::table('kits_epp')->get();
            foreach ($kits as $k) {
                if ($estadoFiltro && $k->estado !== $estadoFiltro) continue;
                $esSeguro = $k->presion_oxigeno_bares >= 250;

                $items[] = [
                    'id' => $k->id,
                    'codigo' => $k->codigo,
                    'nombre' => 'Equipo de Respiración Autónoma EPP',
                    'categoria' => 'EPP',
                    'estado' => $k->estado,
                    'stockOVolumen' => (float)$k->presion_oxigeno_bares,
                    'unidadMedida' => 'Bares',
                    'detallesExtra' => $esSeguro ? 'Presión Óptima Combate' : 'Presión Insuficiente (<250)',
                    'estacionId' => $k->estacion_id,
                    'requiereAtencion' => (!$esSeguro || $k->estado === 'MANTENIMIENTO_REQUERIDO'),
                ];
            }
        }

        // 3. Herramientas
        if (!$catFiltro || $catFiltro === 'HERRAMIENTA') {
            $herrs = DB::table('herramientas_equipos')->get();
            foreach ($herrs as $h) {
                if ($estadoFiltro && $h->estado !== $estadoFiltro) continue;
                $superoFatiga = $h->horas_uso >= $h->umbral_fatiga_horas;

                $items[] = [
                    'id' => $h->id,
                    'codigo' => $h->codigo,
                    'nombre' => $h->nombre,
                    'categoria' => 'HERRAMIENTA',
                    'estado' => $h->estado,
                    'stockOVolumen' => (float)$h->horas_uso,
                    'unidadMedida' => 'Horas',
                    'detallesExtra' => "Umbral Fatiga: {$h->umbral_fatiga_horas} hrs ({$h->tipo})",
                    'estacionId' => $h->estacion_id,
                    'requiereAtencion' => ($superoFatiga || $h->estado === 'MANTENIMIENTO_REQUERIDO'),
                ];
            }
        }

        return $items;
    }

    public function listarHerramientas($_, array $args): array
    {
        $estacionId = $args['estacionId'] ?? null;
        $q = HerramientaEquipoEloquentModel::query();
        if ($estacionId) {
            $q->where('estacion_id', $estacionId);
        }
        return $q->get()->map(function ($h) {
            return [
                'id' => $h->id,
                'codigo' => $h->codigo,
                'nombre' => $h->nombre,
                'tipo' => $h->tipo,
                'horasUso' => $h->horas_uso,
                'umbralFatigaHoras' => $h->umbral_fatiga_horas,
                'requiereMantenimiento' => $h->requiereMantenimientoPreventivo(),
                'estado' => $h->estado,
                'estacionId' => $h->estacion_id,
            ];
        })->toArray();
    }

    public function listarEquiposMantenimiento(): array
    {
        $items = [];

        // Kits EPP con baja presión o marcados en mantenimiento
        $kits = DB::table('kits_epp')
            ->where('presion_oxigeno_bares', '<', 250)
            ->orWhere('estado', 'MANTENIMIENTO_REQUERIDO')
            ->get();

        foreach ($kits as $k) {
            $items[] = [
                'id' => $k->id,
                'codigo' => $k->codigo,
                'nombre' => 'Kit Respiración EPP',
                'categoria' => 'EPP',
                'estado' => $k->estado,
                'stockOVolumen' => (float)$k->presion_oxigeno_bares,
                'unidadMedida' => 'Bares',
                'detallesExtra' => "Presión: {$k->presion_oxigeno_bares} Bares (Requiere recarga certificada >= 250 bar)",
                'estacionId' => $k->estacion_id,
                'requiereAtencion' => true,
            ];
        }

        // Herramientas que superaron fatiga
        $herrs = DB::table('herramientas_equipos')
            ->whereColumn('horas_uso', '>=', 'umbral_fatiga_horas')
            ->orWhere('estado', 'MANTENIMIENTO_REQUERIDO')
            ->get();

        foreach ($herrs as $h) {
            $items[] = [
                'id' => $h->id,
                'codigo' => $h->codigo,
                'nombre' => $h->nombre,
                'categoria' => 'HERRAMIENTA',
                'estado' => $h->estado,
                'stockOVolumen' => (float)$h->horas_uso,
                'unidadMedida' => 'Horas',
                'detallesExtra' => "Horas acumuladas: {$h->horas_uso} / Umbral: {$h->umbral_fatiga_horas} hrs",
                'estacionId' => $h->estacion_id,
                'requiereAtencion' => true,
            ];
        }

        return $items;
    }

    public function listarMovimientos($_, array $args): array
    {
        $limite = $args['limite'] ?? 50;
        return MovimientoInventarioEloquentModel::orderBy('created_at', 'desc')
            ->take($limite)
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'tipoMovimiento' => $m->tipo_movimiento,
                    'itemCodigo' => $m->item_codigo,
                    'itemNombre' => $m->item_nombre,
                    'categoria' => $m->categoria,
                    'cantidad' => $m->cantidad,
                    'motivo' => $m->motivo,
                    'responsable' => $m->responsable,
                    'createdAt' => $m->created_at ? $m->created_at->toIso8601String() : now()->toIso8601String(),
                ];
            })->toArray();
    }

    public function listarTransaccionesSaga($_, array $args): array
    {
        $q = TransaccionSagaEloquentModel::query()->orderBy('created_at', 'desc');
        if (!empty($args['estado'])) {
            $q->where('estado', $args['estado']);
        }
        return $q->take(30)->get()->map(function ($t) {
            return [
                'transaccionId' => $t->transaccion_id,
                'incidenteId' => $t->incidente_id,
                'camionId' => $t->camion_id,
                'kitReservadoId' => $t->kit_reservado_id,
                'litrosEspumogenoReservados' => (float)$t->litros_espumogeno_reservados,
                'estado' => $t->estado,
                'createdAt' => $t->created_at ? $t->created_at->toIso8601String() : now()->toIso8601String(),
            ];
        })->toArray();
    }

    // =========================================================================
    // 4. MUTACIONES DE GESTIÓN Y CRUD DE INVENTARIO
    // =========================================================================

    public function registrarInsumoQuimico($_, array $args): array
    {
        $id = 'SUM-' . strtoupper(Str::random(8));
        $estacionId = $args['estacionId'] ?? 'EST-01';

        DB::table('suministros_quimicos')->insert([
            'id' => $id,
            'codigo' => $args['codigo'],
            'nombre' => $args['nombre'],
            'tipo' => $args['tipo'],
            'litros_disponibles' => $args['litrosDisponibles'],
            'lote' => $args['lote'],
            'fecha_caducidad' => $args['fechaCaducidad'],
            'estacion_id' => $estacionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MovimientoInventarioEloquentModel::create([
            'id' => 'MOV-' . strtoupper(Str::random(8)),
            'tipo_movimiento' => 'ENTRADA',
            'item_codigo' => $args['codigo'],
            'item_nombre' => $args['nombre'],
            'categoria' => 'QUIMICO',
            'cantidad' => (float)$args['litrosDisponibles'],
            'motivo' => "Alta de nuevo insumo químico (Lote: {$args['lote']})",
            'responsable' => 'Jefa de Logística',
        ]);

        return [
            'id' => $id,
            'codigo' => $args['codigo'],
            'nombre' => $args['nombre'],
            'tipo' => $args['tipo'],
            'litrosDisponibles' => (float)$args['litrosDisponibles'],
            'lote' => $args['lote'],
            'fechaCaducidad' => $args['fechaCaducidad'],
        ];
    }

    public function registrarKitEPP($_, array $args): array
    {
        $id = 'KIT-' . strtoupper(Str::random(8));
        $presionVO = new PresionOxigeno((int)$args['presionOxigenoBares']);
        $estado = $presionVO->esSeguraParaCombate() ? 'DISPONIBLE' : 'MANTENIMIENTO_REQUERIDO';
        $estacionId = $args['estacionId'] ?? 'EST-01';

        DB::table('kits_epp')->insert([
            'id' => $id,
            'codigo' => $args['codigo'],
            'presion_oxigeno_bares' => $presionVO->bares,
            'estado' => $estado,
            'camion_asignado_id' => null,
            'estacion_id' => $estacionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MovimientoInventarioEloquentModel::create([
            'id' => 'MOV-' . strtoupper(Str::random(8)),
            'tipo_movimiento' => 'ENTRADA',
            'item_codigo' => $args['codigo'],
            'item_nombre' => 'Equipo de Respiración Autónoma EPP',
            'categoria' => 'EPP',
            'cantidad' => 1.0,
            'motivo' => "Alta de Kit EPP con {$presionVO->bares} bares",
            'responsable' => 'Jefa de Logística',
        ]);

        return [
            'id' => $id,
            'codigo' => $args['codigo'],
            'presionOxigenoBares' => $presionVO->bares,
            'esSeguroParaCombate' => $presionVO->esSeguraParaCombate(),
            'estado' => $estado,
            'camionAsignadoId' => null,
        ];
    }

    public function registrarHerramienta($_, array $args): array
    {
        $id = 'HERR-' . strtoupper(Str::random(8));
        $horas = (float)$args['horasUso'];
        $umbral = (float)$args['umbralFatigaHoras'];
        $estado = ($horas >= $umbral) ? 'MANTENIMIENTO_REQUERIDO' : 'DISPONIBLE';
        $estacionId = $args['estacionId'] ?? 'EST-01';

        $herr = HerramientaEquipoEloquentModel::create([
            'id' => $id,
            'codigo' => $args['codigo'],
            'nombre' => $args['nombre'],
            'tipo' => $args['tipo'],
            'horas_uso' => $horas,
            'umbral_fatiga_horas' => $umbral,
            'estado' => $estado,
            'estacion_id' => $estacionId,
        ]);

        MovimientoInventarioEloquentModel::create([
            'id' => 'MOV-' . strtoupper(Str::random(8)),
            'tipo_movimiento' => 'ENTRADA',
            'item_codigo' => $args['codigo'],
            'item_nombre' => $args['nombre'],
            'categoria' => 'HERRAMIENTA',
            'cantidad' => 1.0,
            'motivo' => "Alta de equipo con {$horas} hrs acumuladas",
            'responsable' => 'Jefa de Logística',
        ]);

        return [
            'id' => $herr->id,
            'codigo' => $herr->codigo,
            'nombre' => $herr->nombre,
            'tipo' => $herr->tipo,
            'horasUso' => $herr->horas_uso,
            'umbralFatigaHoras' => $herr->umbral_fatiga_horas,
            'requiereMantenimiento' => $herr->requiereMantenimientoPreventivo(),
            'estado' => $herr->estado,
            'estacionId' => $herr->estacion_id,
        ];
    }

    public function registrarMovimientoStock($_, array $args): array
    {
        $codigo = $args['itemCodigo'];
        $tipo = $args['tipoMovimiento']; // ENTRADA, SALIDA, AJUSTE
        $cantidad = (float)$args['cantidad'];
        $motivo = $args['motivo'];
        $responsable = $args['responsable'] ?? 'Jefa de Logística';

        Log::info("[STOCK-MOVIMIENTO] Código: {$codigo} | Tipo: {$tipo} | Cantidad: {$cantidad} | Motivo: {$motivo} | Responsable: {$responsable}");

        // Buscar en químicos
        $quim = DB::table('suministros_quimicos')->where('codigo', $codigo)->first();
        if ($quim) {
            $nuevoStock = ($tipo === 'ENTRADA')
                ? $quim->litros_disponibles + $cantidad
                : max(0, $quim->litros_disponibles - $cantidad);

            DB::table('suministros_quimicos')->where('codigo', $codigo)->update([
                'litros_disponibles' => $nuevoStock,
                'updated_at' => now(),
            ]);

            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => $tipo,
                'item_codigo' => $codigo,
                'item_nombre' => $quim->nombre,
                'categoria' => 'QUIMICO',
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'responsable' => $responsable,
            ]);

            // Disparo de webhook n8n para quiebre de stock crítico
            AlertaQuiebreStockN8nService::verificarYNotificarQuiebre(
                $codigo,
                $quim->nombre,
                (float)$nuevoStock,
                200.0,
                $quim->estacion_id
            );

            return ['exito' => true, 'mensaje' => "Stock de químico actualizado a {$nuevoStock} Litros.", 'itemCodigo' => $codigo];
        }

        // Buscar en herramientas
        $herr = HerramientaEquipoEloquentModel::where('codigo', $codigo)->first();
        if ($herr) {
            $herr->horas_uso = ($tipo === 'ENTRADA')
                ? max(0, $herr->horas_uso - $cantidad) // Mantenimiento restó horas
                : $herr->horas_uso + $cantidad;

            if ($herr->horas_uso >= $herr->umbral_fatiga_horas) {
                $herr->estado = 'MANTENIMIENTO_REQUERIDO';

                // Disparo automático n8n: herramienta superó fatiga y pasó a mantenimiento
                AlertaQuiebreStockN8nService::notificarMantenimientoRequerido(
                    $herr->codigo,
                    $herr->nombre,
                    (float)$herr->horas_uso,
                    (float)$herr->umbral_fatiga_horas,
                    'FATIGA_LIMITE_ALCANZADA',
                    $herr->estacion_id
                );
            }
            $herr->save();

            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => $tipo,
                'item_codigo' => $codigo,
                'item_nombre' => $herr->nombre,
                'categoria' => 'HERRAMIENTA',
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'responsable' => $responsable,
            ]);

            return ['exito' => true, 'mensaje' => "Horas de herramienta actualizadas a {$herr->horas_uso} hrs.", 'itemCodigo' => $codigo];
        }

        // Buscar en kits
        $kit = DB::table('kits_epp')->where('codigo', $codigo)->first();
        if ($kit) {
            $nuevaPresion = (int)$cantidad;
            $esSeguro = $nuevaPresion >= 250;
            DB::table('kits_epp')->where('codigo', $codigo)->update([
                'presion_oxigeno_bares' => $nuevaPresion,
                'estado' => $esSeguro ? 'DISPONIBLE' : 'MANTENIMIENTO_REQUERIDO',
                'updated_at' => now(),
            ]);

            // Disparo automático n8n: Kit EPP con baja presión (<250 bares) pasa a mantenimiento para recarga
            if (!$esSeguro) {
                AlertaQuiebreStockN8nService::notificarKitBajaPresion(
                    $codigo,
                    $nuevaPresion,
                    $kit->estacion_id ?? 'EST-01'
                );
            }

            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => $tipo,
                'item_codigo' => $codigo,
                'item_nombre' => 'Kit EPP',
                'categoria' => 'EPP',
                'cantidad' => $nuevaPresion,
                'motivo' => $motivo,
                'responsable' => $responsable,
            ]);

            return ['exito' => true, 'mensaje' => "Presión de Kit EPP actualizada a {$nuevaPresion} bares.", 'itemCodigo' => $codigo];
        }

        return ['exito' => false, 'mensaje' => "El ítem con código {$codigo} no existe en inventario.", 'itemCodigo' => $codigo];
    }

    public function actualizarHorasUsoHerramienta($_, array $args): array
    {
        Log::info("[HERRAMIENTA-HOROMETRO] Recibida actualización de horas | Código: {$args['codigo']} | Horas a sumar: {$args['horasAdicionales']}");

        $herr = HerramientaEquipoEloquentModel::where('codigo', $args['codigo'])->firstOrFail();
        $herr->horas_uso += (float)$args['horasAdicionales'];
        $herr->save();

        Log::info("[HERRAMIENTA-HOROMETRO] Equipo {$herr->codigo} acumuló un total de {$herr->horas_uso} horas (Umbral: {$herr->umbral_fatiga_horas}h). Evaluando con IA...");

        // Invocación al modelo de Machine Learning en ms5_ia_mcp_backend (Punto 06 / CU-06)
        $evaluacionIa = \App\Inventario\Infrastructure\Services\MantenimientoPredictivoIaService::evaluarDesgasteEquipo(
            codigo: $herr->codigo,
            nombre: $herr->nombre,
            tipo: $herr->tipo,
            horasUso: (float)$herr->horas_uso,
            umbralFatiga: (float)$herr->umbral_fatiga_horas
        );

        if ($evaluacionIa['requiereRetiroInmediato']) {
            $herr->refresh();

            // Automatización n8n: Notificar al encargado de taller que el equipo superó fatiga y pasó a mantenimiento
            AlertaQuiebreStockN8nService::notificarMantenimientoRequerido(
                $herr->codigo,
                $herr->nombre,
                (float)$herr->horas_uso,
                (float)$herr->umbral_fatiga_horas,
                $evaluacionIa['estadoRecomendado'],
                $herr->estacion_id
            );
        }

        MovimientoInventarioEloquentModel::create([
            'id' => 'MOV-' . strtoupper(Str::random(8)),
            'tipo_movimiento' => 'AJUSTE',
            'item_codigo' => $herr->codigo,
            'item_nombre' => $herr->nombre,
            'categoria' => 'HERRAMIENTA',
            'cantidad' => (float)$args['horasAdicionales'],
            'motivo' => "Uso acumulado (+{$args['horasAdicionales']} hrs). IA: {$evaluacionIa['estadoRecomendado']} (Riesgo: {$evaluacionIa['riesgoFalloPorcentaje']}%)",
            'responsable' => 'Jefa de Logística',
        ]);

        return [
            'id' => $herr->id,
            'codigo' => $herr->codigo,
            'nombre' => $herr->nombre,
            'tipo' => $herr->tipo,
            'horasUso' => $herr->horas_uso,
            'umbralFatigaHoras' => $herr->umbral_fatiga_horas,
            'requiereMantenimiento' => $herr->requiereMantenimientoPreventivo(),
            'estado' => $herr->estado,
            'estacionId' => $herr->estacion_id,
        ];
    }

    public function cambiarEstadoItem($_, array $args): array
    {
        $codigo = $args['codigo'];
        $categoria = $args['categoria'];
        $estado = $args['nuevoEstado'];

        Log::info("[ESTADO-ITEM] Solicitud de cambio de estado | Código: {$codigo} | Categoría: {$categoria} | Nuevo Estado: {$estado}");

        if ($categoria === 'QUIMICO') {
            DB::table('suministros_quimicos')->where('codigo', $codigo)->update(['updated_at' => now()]);
        } elseif ($categoria === 'EPP') {
            DB::table('kits_epp')->where('codigo', $codigo)->update(['estado' => $estado, 'updated_at' => now()]);

            if (str_contains($estado, 'MANTENIMIENTO')) {
                $k = DB::table('kits_epp')->where('codigo', $codigo)->first();
                if ($k) {
                    AlertaQuiebreStockN8nService::notificarKitBajaPresion(
                        $k->codigo,
                        (int)$k->presion_oxigeno_bares,
                        $k->estacion_id ?? 'EST-01'
                    );
                }
            }
        } elseif ($categoria === 'HERRAMIENTA') {
            DB::table('herramientas_equipos')->where('codigo', $codigo)->update(['estado' => $estado, 'updated_at' => now()]);

            if (str_contains($estado, 'MANTENIMIENTO')) {
                $h = DB::table('herramientas_equipos')->where('codigo', $codigo)->first();
                if ($h) {
                    AlertaQuiebreStockN8nService::notificarMantenimientoRequerido(
                        $h->codigo,
                        $h->nombre,
                        (float)$h->horas_uso,
                        (float)$h->umbral_fatiga_horas,
                        $estado,
                        $h->estacion_id
                    );
                }
            }
        }

        return ['exito' => true, 'mensaje' => "Estado de {$codigo} cambiado a {$estado}.", 'itemCodigo' => $codigo];
    }

    public function retornarDeMantenimiento($_, array $args): array
    {
        $codigo = $args['codigo'];
        $categoria = $args['categoria'];

        Log::info("[TALLER-RETORNO] Procesando retorno de mantenimiento | Código: {$codigo} | Categoría: {$categoria}");

        if ($categoria === 'EPP') {
            $presion = $args['nuevaPresion'] ?? 300;
            DB::table('kits_epp')->where('codigo', $codigo)->update([
                'presion_oxigeno_bares' => $presion,
                'estado' => ($presion >= 250) ? 'DISPONIBLE' : 'MANTENIMIENTO_REQUERIDO',
                'updated_at' => now(),
            ]);

            MovimientoInventarioEloquentModel::create([
                'id' => 'MOV-' . strtoupper(Str::random(8)),
                'tipo_movimiento' => 'ENTRADA',
                'item_codigo' => $codigo,
                'item_nombre' => 'Kit EPP',
                'categoria' => 'EPP',
                'cantidad' => (float)$presion,
                'motivo' => "Retorno de taller con recarga certificada ({$presion} Bares)",
                'responsable' => 'Jefa de Logística',
            ]);

            return ['exito' => true, 'mensaje' => "Kit {$codigo} calibrado a {$presion} bares y disponible para combate.", 'itemCodigo' => $codigo];
        }

        if ($categoria === 'HERRAMIENTA') {
            $reiniciar = $args['reiniciarHoras'] ?? true;
            $herr = HerramientaEquipoEloquentModel::where('codigo', $codigo)->first();
            if ($herr) {
                if ($reiniciar) $herr->horas_uso = 0.0;
                $herr->estado = 'DISPONIBLE';
                $herr->save();

                MovimientoInventarioEloquentModel::create([
                    'id' => 'MOV-' . strtoupper(Str::random(8)),
                    'tipo_movimiento' => 'ENTRADA',
                    'item_codigo' => $codigo,
                    'item_nombre' => $herr->nombre,
                    'categoria' => 'HERRAMIENTA',
                    'cantidad' => 0.0,
                    'motivo' => 'Mantenimiento preventivo completado en taller oficial',
                    'responsable' => 'Jefa de Logística',
                ]);

                return ['exito' => true, 'mensaje' => "Herramienta {$codigo} mantenida y lista para servicio.", 'itemCodigo' => $codigo];
            }
        }

        return ['exito' => false, 'mensaje' => "No se pudo procesar retorno para {$codigo}.", 'itemCodigo' => $codigo];
    }

    public function emitirAlertaQuiebreStockN8n($_, array $args): array
    {
        $codigo = $args['codigo'];
        Log::info("[N8N-ALERTA-MANUAL] Disparando emisión de alerta de inventario hacia n8n para ítem: {$codigo}");
        $quim = DB::table('suministros_quimicos')->where('codigo', $codigo)->first();
        if ($quim) {
            $res = AlertaQuiebreStockN8nService::verificarYNotificarQuiebre(
                $quim->codigo,
                $quim->nombre,
                (float)$quim->litros_disponibles,
                999999.0, // Forzar emisión inmediata a webhook n8n
                $quim->estacion_id
            );

            return [
                'exito' => true,
                'mensaje' => $res['mensaje'],
                'itemCodigo' => $codigo,
            ];
        }

        // Si es una herramienta o equipo motorizado
        $herr = DB::table('herramientas_equipos')->where('codigo', $codigo)->first();
        if ($herr) {
            $res = AlertaQuiebreStockN8nService::notificarMantenimientoRequerido(
                $herr->codigo,
                $herr->nombre,
                (float)$herr->horas_uso,
                (float)$herr->umbral_fatiga_horas,
                'REQUERIR_MANTENIMIENTO_PREVENTIVO',
                $herr->estacion_id
            );

            return [
                'exito' => true,
                'mensaje' => "Alerta de mantenimiento preventivo para {$herr->nombre} despachada hacia n8n y WhatsApp.",
                'itemCodigo' => $codigo,
            ];
        }

        return [
            'exito' => false,
            'mensaje' => "El recurso con código {$codigo} no fue encontrado.",
            'itemCodigo' => $codigo,
        ];
    }
}
