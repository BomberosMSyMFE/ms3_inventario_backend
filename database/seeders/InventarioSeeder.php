<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventarioSeeder extends Seeder
{
    public function run(): void
    {
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
    }
}
