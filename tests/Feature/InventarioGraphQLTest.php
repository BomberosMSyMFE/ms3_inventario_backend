<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Inventario\Domain\Model\PresionOxigeno;
use InvalidArgumentException;

class InventarioGraphQLTest extends TestCase
{
    public function test_value_object_presion_oxigeno_inmutable_y_reglas(): void
    {
        $presionSegura = new PresionOxigeno(300);
        $this->assertEquals(300, $presionSegura->bares);
        $this->assertTrue($presionSegura->esSeguraParaCombate());

        $presionBaja = new PresionOxigeno(210);
        $this->assertFalse($presionBaja->esSeguraParaCombate());

        $this->expectException(InvalidArgumentException::class);
        new PresionOxigeno(400);
    }

    public function test_query_auditoria_stock_general(): void
    {
        $response = $this->postJson('/graphql', [
            'query' => '
                query {
                    auditoriaStockGeneral {
                        codigo
                        nombre
                        litrosDisponibles
                    }
                }
            '
        ]);

        $response->assertStatus(200);
        $data = $response->json('data.auditoriaStockGeneral');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('codigo', $data[0]);
    }

    public function test_query_obtener_inventario_estacion_combinado(): void
    {
        $response = $this->postJson('/graphql', [
            'query' => '
                query {
                    obtenerInventarioEstacion(estacionId: "EST-01") {
                        nombreEstacion
                        kitsDisponibles {
                            codigo
                            presionOxigenoBares
                            esSeguroParaCombate
                        }
                        suministrosQuimicos {
                            codigo
                            nombre
                            litrosDisponibles
                        }
                    }
                }
            '
        ]);

        $response->assertStatus(200);
        $estacion = $response->json('data.obtenerInventarioEstacion');
        $this->assertNotNull($estacion);
        $this->assertNotEmpty($estacion['kitsDisponibles']);
        $this->assertNotEmpty($estacion['suministrosQuimicos']);
    }

    public function test_ciclo_completo_saga_reserva_y_compensacion(): void
    {
        $resReserva = $this->postJson('/graphql', [
            'query' => '
                mutation {
                    reservarKitParaDespacho(
                        incidenteId: "INC-999-SAGA"
                        camionId: "B-04-SCZ"
                        litrosEspumogeno: 100.0
                    ) {
                        exito
                        transaccionId
                        materialesReservados
                        mensaje
                    }
                }
            '
        ]);

        $resReserva->assertStatus(200);
        $payloadReserva = $resReserva->json('data.reservarKitParaDespacho');
        $this->assertTrue($payloadReserva['exito']);
        $txId = $payloadReserva['transaccionId'];

        $resCompensacion = $this->postJson('/graphql', [
            'query' => '
                mutation {
                    liberarReservaCompensatoria(
                        transaccionId: "' . $txId . '"
                    ) {
                        exito
                        mensaje
                    }
                }
            '
        ]);

        $resCompensacion->assertStatus(200);
        $payloadComp = $resCompensacion->json('data.liberarReservaCompensatoria');
        $this->assertTrue($payloadComp['exito']);
    }

    public function test_idempotencia_en_reintento_de_reserva_y_compensacion_saga(): void
    {
        $incidenteId = 'INC-IDEMPOTENCIA-' . rand(1000, 9999);
        $camionId = 'B-01-SCZ';
        $litros = 50.0;

        // 1. Primera petición de reserva
        $resp1 = $this->postJson('/graphql', [
            'query' => '
                mutation {
                    reservarKitParaDespacho(
                        incidenteId: "' . $incidenteId . '"
                        camionId: "' . $camionId . '"
                        litrosEspumogeno: ' . $litros . '
                    ) {
                        exito
                        transaccionId
                        mensaje
                    }
                }
            '
        ]);
        $resp1->assertStatus(200);
        $txId1 = $resp1->json('data.reservarKitParaDespacho.transaccionId');
        $this->assertTrue($resp1->json('data.reservarKitParaDespacho.exito'));

        // Obtener stock actual de espumógeno
        $quimicoAntes = \Illuminate\Support\Facades\DB::table('suministros_quimicos')
            ->where('tipo', 'ESPUMOGENO_AFFF')->first();

        // 2. Reintento idéntico por micro-corte de red (Simulación SAGA reintentando)
        $resp2 = $this->postJson('/graphql', [
            'query' => '
                mutation {
                    reservarKitParaDespacho(
                        incidenteId: "' . $incidenteId . '"
                        camionId: "' . $camionId . '"
                        litrosEspumogeno: ' . $litros . '
                    ) {
                        exito
                        transaccionId
                        mensaje
                    }
                }
            '
        ]);
        $resp2->assertStatus(200);
        $txId2 = $resp2->json('data.reservarKitParaDespacho.transaccionId');
        $mensaje2 = $resp2->json('data.reservarKitParaDespacho.mensaje');

        // VERIFICACIONES CRÍTICAS DE IDEMPOTENCIA:
        // A) Mismo ID de transacción
        $this->assertEquals($txId1, $txId2, 'El reintento debe reutilizar la misma transacción');
        $this->assertStringContainsString('idempotente', strtolower($mensaje2));

        // B) No debe haber descontado el stock dos veces
        $quimicoDespues = \Illuminate\Support\Facades\DB::table('suministros_quimicos')
            ->where('tipo', 'ESPUMOGENO_AFFF')->first();
        $this->assertEquals($quimicoAntes->litros_disponibles, $quimicoDespues->litros_disponibles, 'El stock no debe descontarse por segunda vez en un reintento');

        // 3. Compensación idempotente (repetir compensación 2 veces)
        $comp1 = $this->postJson('/graphql', [
            'query' => '
                mutation {
                    liberarReservaCompensatoria(transaccionId: "' . $txId1 . '") {
                        exito
                        mensaje
                    }
                }
            '
        ]);
        $comp1->assertStatus(200);
        $this->assertTrue($comp1->json('data.liberarReservaCompensatoria.exito'));

        $quimicoCompensado1 = \Illuminate\Support\Facades\DB::table('suministros_quimicos')
            ->where('tipo', 'ESPUMOGENO_AFFF')->first();

        $comp2 = $this->postJson('/graphql', [
            'query' => '
                mutation {
                    liberarReservaCompensatoria(transaccionId: "' . $txId1 . '") {
                        exito
                        mensaje
                    }
                }
            '
        ]);
        $comp2->assertStatus(200);
        $this->assertTrue($comp2->json('data.liberarReservaCompensatoria.exito'));

        $quimicoCompensado2 = \Illuminate\Support\Facades\DB::table('suministros_quimicos')
            ->where('tipo', 'ESPUMOGENO_AFFF')->first();
        $this->assertEquals($quimicoCompensado1->litros_disponibles, $quimicoCompensado2->litros_disponibles, 'La compensación repetida no debe reponer stock dos veces');
    }
}
