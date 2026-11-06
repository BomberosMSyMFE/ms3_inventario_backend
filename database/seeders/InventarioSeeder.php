<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventarioSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Estaciones
        DB::table('estaciones_inventario')->delete();
        DB::table('estaciones_inventario')->insert([
            [
                'id' => 'EST-01',
                'nombre' => 'Estación Central N° 1 (Centro)',
                'direccion' => 'Av. Cristóbal de Mendoza y Calle Libertad',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'EST-02',
                'nombre' => 'Estación Norte N° 2 (Parque Industrial)',
                'direccion' => 'Av. Banzer 4to Anillo',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 1.1 Categorías Maestras
        if (\Illuminate\Support\Facades\Schema::hasTable('categorias_inventario')) {
            DB::table('categorias_inventario')->delete();
            DB::table('categorias_inventario')->insert([
                [
                    'id' => 'QUIMICO',
                    'nombre' => 'Insumos y Agentes Químicos',
                    'descripcion' => 'Espumógenos AFFF, retardantes químicos forestales y agentes extintores',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'EPP',
                    'nombre' => 'Equipos de Protección Personal y O2',
                    'descripcion' => 'Kits de respiración autónoma ERA con cilindros de oxígeno presurizados',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'HERRAMIENTA',
                    'nombre' => 'Herramientas y Maquinaria Pesada',
                    'descripcion' => 'Motobombas, generadores eléctricos, motosierras y equipos de extricación',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'REPUESTO',
                    'nombre' => 'Repuestos y Acoples Hidráulicos',
                    'descripcion' => 'Mangueras de alta presión, válvulas, pitones y uniones Storz',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);
        }

        // 2. Kits EPP
        DB::table('kits_epp')->delete();
        DB::table('kits_epp')->insert([
            [
                'id' => 'KIT-A-101',
                'codigo' => 'KIT-A-101',
                'presion_oxigeno_bares' => 300,
                'estado' => 'DISPONIBLE',
                'camion_asignado_id' => null,
                'estacion_id' => 'EST-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'KIT-A-102',
                'codigo' => 'KIT-A-102',
                'presion_oxigeno_bares' => 280,
                'estado' => 'DISPONIBLE',
                'camion_asignado_id' => null,
                'estacion_id' => 'EST-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'KIT-A-103',
                'codigo' => 'KIT-A-103',
                'presion_oxigeno_bares' => 190,
                'estado' => 'MANTENIMIENTO_REQUERIDO',
                'camion_asignado_id' => null,
                'estacion_id' => 'EST-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'KIT-B-201',
                'codigo' => 'KIT-B-201',
                'presion_oxigeno_bares' => 310,
                'estado' => 'DISPONIBLE',
                'camion_asignado_id' => null,
                'estacion_id' => 'EST-02',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 3. Suministros Químicos
        DB::table('suministros_quimicos')->delete();
        DB::table('suministros_quimicos')->insert([
            [
                'id' => 'SUM-AFFF-01',
                'codigo' => 'QUIM-AFFF-01',
                'nombre' => 'Espumógeno AFFF 3%',
                'tipo' => 'ESPUMOGENO_AFFF',
                'litros_disponibles' => 1500.00,
                'lote' => 'LT-2026-SCZ-01',
                'fecha_caducidad' => '2028-12-31',
                'estacion_id' => 'EST-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'SUM-RET-02',
                'codigo' => 'QUIM-RET-02',
                'nombre' => 'Retardante Forestal Phos-Chek',
                'tipo' => 'RETARDANTE_FORESTAL',
                'litros_disponibles' => 3200.00,
                'lote' => 'LT-2026-FOR-09',
                'fecha_caducidad' => '2027-08-15',
                'estacion_id' => 'EST-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'SUM-MANG-03',
                'codigo' => 'EQUIP-MANG-03',
                'nombre' => 'Manguera Alta Presión 2.5 pulg',
                'tipo' => 'MANGUERA_ALTA_PRESION',
                'litros_disponibles' => 50.00,
                'lote' => 'LT-2025-EQUIP',
                'fecha_caducidad' => '2030-01-01',
                'estacion_id' => 'EST-02',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // 4. Herramientas, Motobombas y Equipamiento con Umbral de Fatiga
        if (\Illuminate\Support\Facades\Schema::hasTable('herramientas_equipos')) {
            DB::table('herramientas_equipos')->delete();
            DB::table('herramientas_equipos')->insert([
                [
                    'id' => 'HERR-001',
                    'codigo' => 'BOMB-HONDA-01',
                    'nombre' => 'Motobomba Alta Presión Honda GX390',
                    'tipo' => 'MOTOBOMBA',
                    'horas_uso' => 108.50, // Superó umbral de 100 horas -> Requiere mantenimiento
                    'umbral_fatiga_horas' => 100.00,
                    'estado' => 'MANTENIMIENTO_REQUERIDO',
                    'estacion_id' => 'EST-01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'HERR-002',
                    'codigo' => 'BOMB-HONDA-02',
                    'nombre' => 'Motobomba Portátil Honda WX15',
                    'tipo' => 'MOTOBOMBA',
                    'horas_uso' => 45.00,
                    'umbral_fatiga_horas' => 100.00,
                    'estado' => 'DISPONIBLE',
                    'estacion_id' => 'EST-01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'HERR-003',
                    'codigo' => 'MOTOSI-ST-01',
                    'nombre' => 'Motosierra de Rescate Stihl MS 462',
                    'tipo' => 'MOTOSIERRA',
                    'horas_uso' => 112.00, // Superó fatiga
                    'umbral_fatiga_horas' => 80.00,
                    'estado' => 'MANTENIMIENTO_REQUERIDO',
                    'estacion_id' => 'EST-01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'HERR-004',
                    'codigo' => 'GEN-CUMMINS-01',
                    'nombre' => 'Generador Auxiliar Diésel 5kVA',
                    'tipo' => 'GENERADOR',
                    'horas_uso' => 38.00,
                    'umbral_fatiga_horas' => 120.00,
                    'estado' => 'DISPONIBLE',
                    'estacion_id' => 'EST-02',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 'HERR-005',
                    'codigo' => 'VENT-RAMFAN-01',
                    'nombre' => 'Ventilador Táctico de Presión Positiva TurboVent',
                    'tipo' => 'EXTRACTOR',
                    'horas_uso' => 22.00,
                    'umbral_fatiga_horas' => 60.00,
                    'estado' => 'DISPONIBLE',
                    'estacion_id' => 'EST-01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);
        }

        // 5. Historial de Movimientos de Almacén
        if (\Illuminate\Support\Facades\Schema::hasTable('movimientos_inventario')) {
            DB::table('movimientos_inventario')->delete();
            DB::table('movimientos_inventario')->insert([
                [
                    'id' => 'MOV-001',
                    'tipo_movimiento' => 'ENTRADA',
                    'item_codigo' => 'QUIM-AFFF-01',
                    'item_nombre' => 'Espumógeno AFFF 3%',
                    'categoria' => 'QUIMICO',
                    'cantidad' => 1500.00,
                    'motivo' => 'Adquisición anual de insumos químicos para extinción clase B',
                    'responsable' => 'Jefa de Logística',
                    'created_at' => now()->subDays(5),
                    'updated_at' => now()->subDays(5),
                ],
                [
                    'id' => 'MOV-002',
                    'tipo_movimiento' => 'RESERVA_SAGA',
                    'item_codigo' => 'QUIM-AFFF-01',
                    'item_nombre' => 'Espumógeno AFFF 3%',
                    'categoria' => 'QUIMICO',
                    'cantidad' => 150.00,
                    'motivo' => 'Reserva automática por despacho táctico SAGA (Incidente INC-SCZ-101 a cisterna B-04)',
                    'responsable' => 'Orquestador MS-01 Nataly',
                    'created_at' => now()->subHours(8),
                    'updated_at' => now()->subHours(8),
                ],
                [
                    'id' => 'MOV-003',
                    'tipo_movimiento' => 'ENTRADA',
                    'item_codigo' => 'KIT-A-101',
                    'item_nombre' => 'Equipo de Respiración Autónoma EPP (300 Bar)',
                    'categoria' => 'EPP',
                    'cantidad' => 1.00,
                    'motivo' => 'Recarga de cilindro de oxígeno en estación de compresión certificada',
                    'responsable' => 'Jefa de Logística',
                    'created_at' => now()->subDays(2),
                    'updated_at' => now()->subDays(2),
                ],
                [
                    'id' => 'MOV-004',
                    'tipo_movimiento' => 'AJUSTE',
                    'item_codigo' => 'BOMB-HONDA-01',
                    'item_nombre' => 'Motobomba Alta Presión Honda GX390',
                    'categoria' => 'HERRAMIENTA',
                    'cantidad' => 1.00,
                    'motivo' => 'Registro de horas tras combate de incendio estructural en Parque Industrial',
                    'responsable' => 'Maquinista de Guardia',
                    'created_at' => now()->subHours(4),
                    'updated_at' => now()->subHours(4),
                ]
            ]);
        }
    }
}
