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
}
