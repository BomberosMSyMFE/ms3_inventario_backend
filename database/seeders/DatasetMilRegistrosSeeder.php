<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatasetMilRegistrosSeeder extends Seeder
{
    /**
     * Dataset oficial de 1,000 registros para el Subsistema MS-03 de Inventario y Logística de Bomberos.
     * 
     * Composición exacta de los 1,000 registros:
     * - kits_epp:             150 registros (Equipos ERA de respiración autónoma con control de bares)
     * - suministros_quimicos:  50 registros (Espumógenos AFFF, retardantes forestales, PQS, gases limpios)
     * - herramientas_equipos: 150 registros (Motobombas, generadores, motosierras, ventiladores, equipos hidráulicos)
     * - movimientos_inventario: 650 registros (Historial de entradas, salidas, reservas SAGA y compensaciones)
     * -------------------------------------------------------------------------------------------------------
     * TOTAL:                1,000 registros
     */
    public function run(): void
    {
        $this->command?->info("Iniciando la siembra del Dataset Maestro de 1,000 registros en MS-03...");

        // 1. Estaciones de Bomberos (3 Estaciones Estratégicas en Santa Cruz)
        $estaciones = [
            [
                'id' => 'EST-01',
                'nombre' => 'Estación Central N° 1 (Centro)',
                'direccion' => 'Av. Cristóbal de Mendoza y Calle Libertad',
                'created_at' => Carbon::now()->subMonths(6),
                'updated_at' => Carbon::now()->subMonths(6),
            ],
            [
                'id' => 'EST-02',
                'nombre' => 'Estación Norte N° 2 (Parque Industrial)',
                'direccion' => 'Av. Banzer y 4to Anillo',
                'created_at' => Carbon::now()->subMonths(6),
                'updated_at' => Carbon::now()->subMonths(6),
            ],
            [
                'id' => 'EST-03',
                'nombre' => 'Estación Sur N° 3 (Plan 3000)',
                'direccion' => 'Av. El Mechero y Rotonda Central',
                'created_at' => Carbon::now()->subMonths(6),
                'updated_at' => Carbon::now()->subMonths(6),
            ],
        ];

        foreach ($estaciones as $est) {
            DB::table('estaciones_inventario')->updateOrInsert(['id' => $est['id']], $est);
        }

        // 1.1 Categorías Maestras
        $categorias = [
            ['id' => 'QUIMICO', 'nombre' => 'Insumos y Agentes Químicos', 'descripcion' => 'Espumógenos AFFF, retardantes químicos forestales y agentes extintores'],
            ['id' => 'EPP', 'nombre' => 'Equipos de Protección Personal y O2', 'descripcion' => 'Kits de respiración autónoma ERA con cilindros de oxígeno presurizados'],
            ['id' => 'HERRAMIENTA', 'nombre' => 'Herramientas y Maquinaria Pesada', 'descripcion' => 'Motobombas, generadores eléctricos, motosierras y equipos de extricación'],
            ['id' => 'REPUESTO', 'nombre' => 'Repuestos y Acoples Hidráulicos', 'descripcion' => 'Mangueras de alta presión, válvulas, pitones y uniones Storz'],
        ];

        foreach ($categorias as $cat) {
            DB::table('categorias_inventario')->updateOrInsert(['id' => $cat['id']], array_merge($cat, [
                'created_at' => Carbon::now()->subMonths(6),
                'updated_at' => Carbon::now()->subMonths(6),
            ]));
        }

        // Limpiar tablas dependientes para garantizar dataset limpio y sin duplicados
        DB::table('movimientos_inventario')->delete();
        DB::table('kits_epp')->delete();
        DB::table('suministros_quimicos')->delete();
        DB::table('herramientas_equipos')->delete();

        $estacionIds = ['EST-01', 'EST-02', 'EST-03'];
        $camiones = ['B-01', 'B-02', 'B-03', 'B-04', 'C-01', 'R-01', 'R-02', 'AMB-01', null];

        // =========================================================================
        // 2. KITS DE RESPIRACIÓN AUTÓNOMA EPP (150 registros)
        // =========================================================================
        $kits = [];
        // Kits base de demostración reconocidos por frontend/pruebas
        $kits[] = [
            'id' => 'KIT-A-101',
            'codigo' => 'KIT-A-101',
            'presion_oxigeno_bares' => 300,
            'estado' => 'DISPONIBLE',
            'camion_asignado_id' => 'B-01',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(5),
            'updated_at' => Carbon::now()->subHours(2),
        ];
        $kits[] = [
            'id' => 'KIT-A-102',
            'codigo' => 'KIT-A-102',
            'presion_oxigeno_bares' => 280,
            'estado' => 'DISPONIBLE',
            'camion_asignado_id' => 'B-01',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(5),
            'updated_at' => Carbon::now()->subHours(3),
        ];
        $kits[] = [
            'id' => 'KIT-A-103',
            'codigo' => 'KIT-A-103',
            'presion_oxigeno_bares' => 190,
            'estado' => 'MANTENIMIENTO_REQUERIDO',
            'camion_asignado_id' => null,
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(5),
            'updated_at' => Carbon::now()->subDays(1),
        ];
        $kits[] = [
            'id' => 'KIT-B-201',
            'codigo' => 'KIT-B-201',
            'presion_oxigeno_bares' => 310,
            'estado' => 'DISPONIBLE',
            'camion_asignado_id' => 'B-02',
            'estacion_id' => 'EST-02',
            'created_at' => Carbon::now()->subMonths(4),
            'updated_at' => Carbon::now()->subHours(1),
        ];

        // Generar desde 5 hasta 150 kits (146 kits adicionales)
        for ($i = 5; $i <= 150; $i++) {
            $num = str_pad($i, 3, '0', STR_PAD_LEFT);
            $estacion = $estacionIds[($i - 1) % count($estacionIds)];
            
            // 85% disponibles (presión entre 255 y 310 bar), 15% en mantenimiento (presión < 240 bar)
            $esOptimo = ($i % 7 !== 0);
            $presion = $esOptimo ? rand(260, 310) : rand(140, 230);
            $estado = $esOptimo ? 'DISPONIBLE' : 'MANTENIMIENTO_REQUERIDO';
            $camion = $esOptimo ? $camiones[array_rand($camiones)] : null;
            $diasAtras = rand(1, 150);

            $kits[] = [
                'id' => "KIT-EPP-{$num}",
                'codigo' => "KIT-EPP-{$num}",
                'presion_oxigeno_bares' => $presion,
                'estado' => $estado,
                'camion_asignado_id' => $camion,
                'estacion_id' => $estacion,
                'created_at' => Carbon::now()->subDays($diasAtras + 30),
                'updated_at' => Carbon::now()->subDays(rand(0, $diasAtras)),
            ];
        }

        DB::table('kits_epp')->insert($kits);
        $this->command?->info("✓ Kits EPP insertados: " . count($kits));

        // =========================================================================
        // 3. SUMINISTROS QUÍMICOS (50 registros)
        // =========================================================================
        $quimicos = [];
        // Base de demostración
        $quimicos[] = [
            'id' => 'SUM-AFFF-01',
            'codigo' => 'QUIM-AFFF-01',
            'nombre' => 'Espumógeno AFFF 3% Chemguard',
            'tipo' => 'ESPUMOGENO_AFFF',
            'litros_disponibles' => 1500.00,
            'lote' => 'LT-2026-SCZ-01',
            'fecha_caducidad' => '2028-12-31',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(5),
            'updated_at' => Carbon::now()->subHours(2),
        ];
        $quimicos[] = [
            'id' => 'SUM-RET-02',
            'codigo' => 'QUIM-RET-02',
            'nombre' => 'Retardante Forestal Phos-Chek MVP-Fx',
            'tipo' => 'RETARDANTE_FORESTAL',
            'litros_disponibles' => 3200.00,
            'lote' => 'LT-2026-FOR-09',
            'fecha_caducidad' => '2027-08-15',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(5),
            'updated_at' => Carbon::now()->subHours(5),
        ];
        $quimicos[] = [
            'id' => 'SUM-MANG-03',
            'codigo' => 'EQUIP-MANG-03',
            'nombre' => 'Manguera Alta Presión Kevlar 2.5 pulg',
            'tipo' => 'MANGUERA_ALTA_PRESION',
            'litros_disponibles' => 50.00,
            'lote' => 'LT-2025-EQUIP',
            'fecha_caducidad' => '2030-01-01',
            'estacion_id' => 'EST-02',
            'created_at' => Carbon::now()->subMonths(4),
            'updated_at' => Carbon::now()->subDays(10),
        ];

        $quimicosPrototipos = [
            ['nombre' => 'Espumógeno AFFF 1% Bio-Ex Fluor Free', 'tipo' => 'ESPUMOGENO_AFFF', 'litros' => 1200.00, 'caducidad' => '2029-05-20'],
            ['nombre' => 'Espumógeno Antialcohol AR-AFFF 3x6 Ansulite', 'tipo' => 'ESPUMOGENO_AFFF', 'litros' => 1800.00, 'caducidad' => '2028-11-10'],
            ['nombre' => 'Espumógeno AFFF 6% Solberg Arctic Foam', 'tipo' => 'ESPUMOGENO_AFFF', 'litros' => 950.00, 'caducidad' => '2027-09-30'],
            ['nombre' => 'Retardante Forestal Phos-Chek LC95A', 'tipo' => 'RETARDANTE_FORESTAL', 'litros' => 4500.00, 'caducidad' => '2028-03-15'],
            ['nombre' => 'Retardante de Espuma Forestal FireFoam 103', 'tipo' => 'RETARDANTE_FORESTAL', 'litros' => 2400.00, 'caducidad' => '2027-12-01'],
            ['nombre' => 'Polvo Químico Seco PQS ABC (Fosfato Monoamónico 90%)', 'tipo' => 'POLVO_QUIMICO_ABC', 'litros' => 850.00, 'caducidad' => '2029-08-25'],
            ['nombre' => 'Polvo Químico PQS Púrpura K (Bicarbonato Potasio)', 'tipo' => 'POLVO_QUIMICO_BC', 'litros' => 600.00, 'caducidad' => '2030-04-12'],
            ['nombre' => 'Agente Limpio Gas Heptafluoropropano FM-200', 'tipo' => 'GAS_LIMPIO_FM200', 'litros' => 380.00, 'caducidad' => '2031-10-05'],
            ['nombre' => 'Dióxido de Carbono Líquido CO2 Criogénico Táctico', 'tipo' => 'CO2_CRIOGENICO', 'litros' => 520.00, 'caducidad' => '2030-06-18'],
            ['nombre' => 'Agente Penetrante Humectante Clase A FireAde 2000', 'tipo' => 'AGENTE_PENETRANTE', 'litros' => 1600.00, 'caducidad' => '2028-07-22'],
            ['nombre' => 'Espuma de Alta Expansión Hi-Ex Synthetic Foam', 'tipo' => 'ESPUMA_ALTA_EXPANSION', 'litros' => 2100.00, 'caducidad' => '2027-04-30'],
            ['nombre' => 'Neutralizante Táctico para Derrames Químicos HAZMAT', 'tipo' => 'NEUTRALIZANTE_HAZMAT', 'litros' => 420.00, 'caducidad' => '2026-12-31'],
            ['nombre' => 'Polvo Sofocante Met-L-X para Metales Combustibles Clase D', 'tipo' => 'POLVO_CLASE_D', 'litros' => 310.00, 'caducidad' => '2032-01-15'],
        ];

        for ($i = 4; $i <= 50; $i++) {
            $num = str_pad($i, 3, '0', STR_PAD_LEFT);
            $proto = $quimicosPrototipos[($i - 4) % count($quimicosPrototipos)];
            $estacion = $estacionIds[($i - 1) % count($estacionIds)];
            $litros = round($proto['litros'] * (0.6 + (rand(0, 80) / 100)), 2);

            $quimicos[] = [
                'id' => "SUM-QUIM-{$num}",
                'codigo' => "QUIM-SCZ-{$num}",
                'nombre' => "{$proto['nombre']} (Lote SCZ-{$num})",
                'tipo' => $proto['tipo'],
                'litros_disponibles' => $litros,
                'lote' => "LT-2026-SCZ-{$num}",
                'fecha_caducidad' => $proto['caducidad'],
                'estacion_id' => $estacion,
                'created_at' => Carbon::now()->subMonths(5),
                'updated_at' => Carbon::now()->subDays(rand(1, 45)),
            ];
        }

        DB::table('suministros_quimicos')->insert($quimicos);
        $this->command?->info("✓ Suministros Químicos insertados: " . count($quimicos));

        // =========================================================================
        // 4. HERRAMIENTAS Y EQUIPOS CON CONTROL DE HORÓMETRO (150 registros)
        // =========================================================================
        $herramientas = [];
        // Base de demostración
        $herramientas[] = [
            'id' => 'HERR-001',
            'codigo' => 'BOMB-HONDA-01',
            'nombre' => 'Motobomba Alta Presión Honda GX390',
            'tipo' => 'MOTOBOMBA',
            'horas_uso' => 108.50,
            'umbral_fatiga_horas' => 100.00,
            'estado' => 'MANTENIMIENTO_REQUERIDO',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(6),
            'updated_at' => Carbon::now()->subDays(2),
        ];
        $herramientas[] = [
            'id' => 'HERR-002',
            'codigo' => 'BOMB-HONDA-02',
            'nombre' => 'Motobomba Portátil Honda WX15',
            'tipo' => 'MOTOBOMBA',
            'horas_uso' => 45.00,
            'umbral_fatiga_horas' => 100.00,
            'estado' => 'DISPONIBLE',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(6),
            'updated_at' => Carbon::now()->subDays(5),
        ];
        $herramientas[] = [
            'id' => 'HERR-003',
            'codigo' => 'MOTOSI-ST-01',
            'nombre' => 'Motosierra de Rescate Stihl MS 462',
            'tipo' => 'MOTOSIERRA',
            'horas_uso' => 88.00,
            'umbral_fatiga_horas' => 80.00,
            'estado' => 'MANTENIMIENTO_REQUERIDO',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(6),
            'updated_at' => Carbon::now()->subDays(1),
        ];
        $herramientas[] = [
            'id' => 'HERR-004',
            'codigo' => 'GEN-CUMMINS-01',
            'nombre' => 'Generador Auxiliar Diésel 5kVA Cummins',
            'tipo' => 'GENERADOR',
            'horas_uso' => 38.00,
            'umbral_fatiga_horas' => 120.00,
            'estado' => 'DISPONIBLE',
            'estacion_id' => 'EST-02',
            'created_at' => Carbon::now()->subMonths(6),
            'updated_at' => Carbon::now()->subDays(7),
        ];
        $herramientas[] = [
            'id' => 'HERR-005',
            'codigo' => 'VENT-RAMFAN-01',
            'nombre' => 'Ventilador Táctico de Presión Positiva TurboVent Ramfan',
            'tipo' => 'EXTRACTOR',
            'horas_uso' => 22.00,
            'umbral_fatiga_horas' => 60.00,
            'estado' => 'DISPONIBLE',
            'estacion_id' => 'EST-01',
            'created_at' => Carbon::now()->subMonths(6),
            'updated_at' => Carbon::now()->subDays(3),
        ];

        $equiposPrototipos = [
            ['nombre' => 'Motobomba Portátil Forestal Waterax BB-4', 'tipo' => 'MOTOBOMBA', 'umbral' => 100.00],
            ['nombre' => 'Motobomba de Caudal Rosenbauer FOX 4', 'tipo' => 'MOTOBOMBA', 'umbral' => 120.00],
            ['nombre' => 'Motobomba Tohatsu VC72AS Alta Capacidad', 'tipo' => 'MOTOBOMBA', 'umbral' => 100.00],
            ['nombre' => 'Motosierra Forestal Husqvarna 372 XP', 'tipo' => 'MOTOSIERRA', 'umbral' => 80.00],
            ['nombre' => 'Motosierra de Salvamento Stihl MS 261 C-M', 'tipo' => 'MOTOSIERRA', 'umbral' => 75.00],
            ['nombre' => 'Motosierra de Techo Husqvarna 572 XP', 'tipo' => 'MOTOSIERRA', 'umbral' => 80.00],
            ['nombre' => 'Generador Inverter Honda EU3000is Silencioso', 'tipo' => 'GENERADOR', 'umbral' => 100.00],
            ['nombre' => 'Generador Industrial Pramac P11000 Diésel', 'tipo' => 'GENERADOR', 'umbral' => 150.00],
            ['nombre' => 'Generador Móvil Generac GP6500 de Respuesta Rápida', 'tipo' => 'GENERADOR', 'umbral' => 100.00],
            ['nombre' => 'Ventilador PPV a Batería Ramfan EX50Li 40V', 'tipo' => 'EXTRACTOR', 'umbral' => 60.00],
            ['nombre' => 'Extractor Antideflagrante Ramfan UB20 HAZMAT', 'tipo' => 'EXTRACTOR', 'umbral' => 50.00],
            ['nombre' => 'Ventilador a Gasolina Tempest Power Blower 16', 'tipo' => 'EXTRACTOR', 'umbral' => 70.00],
            ['nombre' => 'Cizalla Hidráulica Holmatro Pentheon CU5050', 'tipo' => 'CIZALLA', 'umbral' => 50.00],
            ['nombre' => 'Cizalla a Batería Lukas S700 eDraulic', 'tipo' => 'CIZALLA', 'umbral' => 50.00],
            ['nombre' => 'Separador Hidráulico Holmatro SP5240', 'tipo' => 'SEPARADOR', 'umbral' => 50.00],
            ['nombre' => 'Separador de Rescate Lukas SP310 eDraulic', 'tipo' => 'SEPARADOR', 'umbral' => 50.00],
            ['nombre' => 'Cilindro Telescópico RAM Holmatro RA5321', 'tipo' => 'RAM_HIDRAULICO', 'umbral' => 60.00],
            ['nombre' => 'Cámara Termográfica Táctica FLIR K55', 'tipo' => 'CAMARA_TERMICA', 'umbral' => 120.00],
            ['nombre' => 'Detector Multigas Altair 4XR LEL/CO/H2S/O2', 'tipo' => 'DETECTOR_GASES', 'umbral' => 150.00],
            ['nombre' => 'Compresor Móvil de Oxígeno Bauer Junior II 300 Bar', 'tipo' => 'COMPRESOR', 'umbral' => 100.00],
        ];

        for ($i = 6; $i <= 150; $i++) {
            $num = str_pad($i, 3, '0', STR_PAD_LEFT);
            $proto = $equiposPrototipos[($i - 6) % count($equiposPrototipos)];
            $estacion = $estacionIds[($i - 1) % count($estacionIds)];
            
            // 80% bajo el umbral, 20% superando fatiga
            $sobreFatiga = ($i % 5 === 0);
            $umbral = $proto['umbral'];
            $horas = $sobreFatiga ? round($umbral + rand(3, 35) + (rand(1, 9) / 10), 2) : round(rand(2, (int)($umbral * 0.85)) + (rand(1, 9) / 10), 2);
            $estado = ($horas >= $umbral) ? 'MANTENIMIENTO_REQUERIDO' : 'DISPONIBLE';

            $herramientas[] = [
                'id' => "HERR-{$num}",
                'codigo' => "EQ-{$proto['tipo']}-{$num}",
                'nombre' => "{$proto['nombre']} #{$num}",
                'tipo' => $proto['tipo'],
                'horas_uso' => $horas,
                'umbral_fatiga_horas' => $umbral,
                'estado' => $estado,
                'estacion_id' => $estacion,
                'created_at' => Carbon::now()->subMonths(6),
                'updated_at' => Carbon::now()->subDays(rand(1, 60)),
            ];
        }

        DB::table('herramientas_equipos')->insert($herramientas);
        $this->command?->info("✓ Herramientas y Equipos insertados: " . count($herramientas));

        // =========================================================================
        // 5. MOVIMIENTOS HISTÓRICOS DE INVENTARIO (650 registros)
        // =========================================================================
        $movimientos = [];
        // Base de demostración
        $movimientos[] = [
            'id' => 'MOV-001',
            'tipo_movimiento' => 'ENTRADA',
            'item_codigo' => 'QUIM-AFFF-01',
            'item_nombre' => 'Espumógeno AFFF 3% Chemguard',
            'categoria' => 'QUIMICO',
            'cantidad' => 1500.00,
            'motivo' => 'Adquisición anual de insumos químicos para extinción clase B',
            'responsable' => 'Sgto. Jefa de Logística M. Vargas',
            'created_at' => Carbon::now()->subDays(120),
            'updated_at' => Carbon::now()->subDays(120),
        ];
        $movimientos[] = [
            'id' => 'MOV-002',
            'tipo_movimiento' => 'RESERVA_SAGA',
            'item_codigo' => 'QUIM-AFFF-01',
            'item_nombre' => 'Espumógeno AFFF 3% Chemguard',
            'categoria' => 'QUIMICO',
            'cantidad' => 150.00,
            'motivo' => 'Reserva automática por despacho táctico SAGA (Incidente INC-SCZ-101 a cisterna B-04)',
            'responsable' => 'Orquestador MS-01 Nataly',
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ];
        $movimientos[] = [
            'id' => 'MOV-003',
            'tipo_movimiento' => 'ENTRADA',
            'item_codigo' => 'KIT-A-101',
            'item_nombre' => 'Equipo de Respiración Autónoma EPP (300 Bar)',
            'categoria' => 'EPP',
            'cantidad' => 1.00,
            'motivo' => 'Recarga de cilindro de oxígeno en estación de compresión certificada',
            'responsable' => 'Sgto. Jefa de Logística M. Vargas',
            'created_at' => Carbon::now()->subDays(10),
            'updated_at' => Carbon::now()->subDays(10),
        ];
        $movimientos[] = [
            'id' => 'MOV-004',
            'tipo_movimiento' => 'AJUSTE',
            'item_codigo' => 'BOMB-HONDA-01',
            'item_nombre' => 'Motobomba Alta Presión Honda GX390',
            'categoria' => 'HERRAMIENTA',
            'cantidad' => 1.00,
            'motivo' => 'Registro de horas tras combate de incendio estructural en Parque Industrial',
            'responsable' => 'Maquinista de Guardia T. Peinado',
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ];

        $motivosEntrada = [
            'Recepción de compra municipal para contingencia de temporada seca',
            'Reposición de insumos tras operativo de control de incendio',
            'Recarga y prueba hidrostática de cilindros en taller autorizado',
            'Ingreso por donación de equipamiento táctico internacional',
            'Devolución de material a estación tras simulacro de derrame HAZMAT',
        ];

        $motivosSalida = [
            'Despacho táctico inmediato a incendio estructural en Parque Industrial (Incidente INC-SCZ-108)',
            'Salida de unidad forestal para control de foco en zona de amortiguamiento Lomas de Arena',
            'Despacho de soporte para rescate vehicular e interfaz en km 9 Doble Vía La Guardia',
            'Salida por combate de incendio en almacén comercial zona Los Pozos',
            'Despacho a incidente clase B con hidrocarburos en Refinería Guillermo Elder Bell',
            'Abastecimiento de cisterna de respuesta rápida B-02 para contingencia en Warnes',
        ];

        $motivosAjuste = [
            'Calibración semestral de manómetros y sensores según norma NFPA 1852',
            'Registro de horómetro operativo post-servicio de extinción prolongada',
            'Ajuste por consumo menor de entrenamiento en academia de bomberos',
            'Inspección técnica preventiva y cambio de aceite de motor de 4 tiempos',
            'Corrección de inventario tras auditoría física mensual de cuartel',
        ];

        $motivosSaga = [
            'Reserva automática SAGA para Incidente INC-SCZ-%d asignado a Unidad %s',
            'Bloqueo preventivo de stock por coordinación inter-microservicios SAGA',
            'Reserva prioritaria de insumos químicos para camión de ataque rápido',
        ];

        $motivosCompensacion = [
            'Compensación automática SAGA: Despacho cancelado por falsa alarma (INC-SCZ-%d)',
            'Rollback de inventario SAGA: Camión no disponible en MS-02 Nataly',
            'Liberación de insumos reservados por resolución temprana del siniestro',
        ];

        $responsables = [
            'Sgto. Jefa de Logística M. Vargas',
            'Maquinista de Guardia T. Peinado',
            'Técnico Especialista de Taller C. Ramos',
            'Comandante de Incidente Cap. R. Soliz',
            'Orquestador MS-01 Nataly',
            'Operador de Despacho Táctico',
        ];

        // Generar desde el 5 hasta el 650 (646 registros)
        for ($i = 5; $i <= 650; $i++) {
            $num = str_pad($i, 4, '0', STR_PAD_LEFT);
            $incidenteNum = 100 + ($i % 250);
            $camionUnit = $camiones[$i % (count($camiones) - 1)];

            // Determinar categoría y tomar un ítem real ya insertado
            $catMod = $i % 3;
            if ($catMod === 0) {
                // QUIMICO
                $categoria = 'QUIMICO';
                $item = $quimicos[$i % count($quimicos)];
                $itemCod = $item['codigo'];
                $itemNom = $item['nombre'];
                $cantidad = (float) rand(20, 300);
            } elseif ($catMod === 1) {
                // EPP
                $categoria = 'EPP';
                $item = $kits[$i % count($kits)];
                $itemCod = $item['codigo'];
                $itemNom = "Equipo de Respiración Autónoma {$item['codigo']}";
                $cantidad = (float) rand(1, 4);
            } else {
                // HERRAMIENTA
                $categoria = 'HERRAMIENTA';
                $item = $herramientas[$i % count($herramientas)];
                $itemCod = $item['codigo'];
                $itemNom = $item['nombre'];
                $cantidad = (float) rand(1, 2);
            }

            // Distribuir tipos de movimiento
            $tipoRand = $i % 10;
            if ($tipoRand <= 2) {
                $tipoMov = 'ENTRADA';
                $motivo = $motivosEntrada[$i % count($motivosEntrada)];
                $responsable = $responsables[0]; // Logística
            } elseif ($tipoRand <= 5) {
                $tipoMov = 'SALIDA';
                $motivo = $motivosSalida[$i % count($motivosSalida)];
                $responsable = $responsables[array_rand([1, 3, 5])];
            } elseif ($tipoRand <= 7) {
                $tipoMov = 'AJUSTE';
                $motivo = $motivosAjuste[$i % count($motivosAjuste)];
                $responsable = $responsables[2]; // Taller
            } elseif ($tipoRand === 8) {
                $tipoMov = 'RESERVA_SAGA';
                $motivo = sprintf($motivosSaga[$i % count($motivosSaga)], $incidenteNum, $camionUnit);
                $responsable = 'Orquestador MS-01 Nataly';
            } else {
                $tipoMov = 'COMPENSACION_SAGA';
                $motivo = sprintf($motivosCompensacion[$i % count($motivosCompensacion)], $incidenteNum);
                $responsable = 'Orquestador MS-01 Nataly';
            }

            $diasAtras = rand(0, 180);
            $fecha = Carbon::now()->subDays($diasAtras)->subHours(rand(0, 23))->subMinutes(rand(0, 59));

            $movimientos[] = [
                'id' => "MOV-{$num}",
                'tipo_movimiento' => $tipoMov,
                'item_codigo' => $itemCod,
                'item_nombre' => $itemNom,
                'categoria' => $categoria,
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'responsable' => $responsable,
                'created_at' => $fecha,
                'updated_at' => $fecha,
            ];
        }

        // Insertar en lotes de 100 para máxima velocidad
        foreach (array_chunk($movimientos, 100) as $chunk) {
            DB::table('movimientos_inventario')->insert($chunk);
        }
        $this->command?->info("✓ Movimientos de Inventario insertados: " . count($movimientos));

        $totalRegistros = count($kits) + count($quimicos) + count($herramientas) + count($movimientos);
        $this->command?->info("=========================================================================");
        $this->command?->info("¡ÉXITO! Dataset Maestro generado satisfactoriamente con {$totalRegistros} registros:");
        $this->command?->info("- kits_epp:              " . count($kits));
        $this->command?->info("- suministros_quimicos:   " . count($quimicos));
        $this->command?->info("- herramientas_equipos:  " . count($herramientas));
        $this->command?->info("- movimientos_inventario: " . count($movimientos));
        $this->command?->info("=========================================================================");
    }
}
