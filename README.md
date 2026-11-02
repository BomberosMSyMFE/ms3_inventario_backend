# MS-03: Gestión de Inventario y Catálogo de Recursos
## Plataforma Distribuida Cuerpo de Bomberos — INF512 (Ing. Software II)
> **Responsable:** Jael Mamani  
> **Tecnología:** PHP 8.3 / Laravel 11 + PostgreSQL + GraphQL (Lighthouse)  
> **Patrón Arquitectónico:** Domain-Driven Design (DDD) Táctico  

---

## 1. Arquitectura de Dominio (DDD)

```text
app/Inventario/
├── Domain/                      # NÚCLEO DE DOMINIO PURO
│   ├── Model/
│   │   ├── PresionOxigeno.php   # Value Object inmutable (Autovalida 0-350 bares, >=250 seguro)
│   │   ├── KitProteccionEPP.php # Aggregate Root (Protege invariantes de reserva de kits)
│   │   └── InsumoQuimico.php    # Entity (Control volumétrico de espumógeno y retardante)
│   ├── Exceptions/
│   │   └── StockInsuficienteException.php
│   └── Repositories/
│       ├── KitInventarioRepositoryInterface.php
│       └── SuministroQuimicoRepositoryInterface.php
├── Application/                 # SERVICIOS DE APLICACIÓN (Casos de Uso)
│   └── Services/
│       ├── ReservarSuministrosService.php # Participante SAGA (Reserva atómica)
│       └── LiberarSuministrosService.php  # Compensación SAGA (Rollback)
└── Infrastructure/              # INFRAESTRUCTURA Y DETALLES EXTERNOS
    ├── Persistence/
    │   ├── Models/              # Modelos Eloquent mapeados a PostgreSQL
    │   ├── EloquentKitInventarioRepository.php
    │   └── EloquentSuministroQuimicoRepository.php
    └── GraphQL/
        └── Resolvers/
            └── SuministrosResolver.php    # Resolvers Lighthouse
```

---

## 2. Participación en la Transacción Distribuida SAGA

1. **Happy Path:**
   * Nataly (`ms1_comandos_backend`) invoca:
     ```graphql
     mutation {
       reservarKitParaDespacho(
         incidenteId: "INC-101"
         camionId: "B-04-SCZ"
         litrosEspumogeno: 250.0
       ) {
         exito
         transaccionId
         materialesReservados
         mensaje
       }
     }
     ```
   * Si existe un kit con presión segura ($\ge 250$ bares) y espumógeno suficiente, transiciona el kit a `ASIGNADO_CAMION`, descuenta el stock y retorna `exito: true`.

2. **Rollback Compensatorio:**
   * Si la reserva del camión en el microservicio de Flotas falla, Nataly invoca:
     ```graphql
     mutation {
       liberarReservaCompensatoria(
         transaccionId: "TX-SAGA-INV-..."
       ) {
         exito
         mensaje
       }
     }
     ```
   * El servicio repone los litros de espumógeno y regresa el kit EPP a `DISPONIBLE` garantizando la consistencia eventual.

---

## 3. Ejecución de Pruebas Automatizadas

```bash
php artisan test --filter=InventarioGraphQLTest
```

**Resultado esperado:**
* `✓ value object presion oxigeno inmutable y reglas`
* `✓ query auditoria stock general (Sin Over-fetching)`
* `✓ query obtener inventario estacion combinado (Sin N+1 / Under-fetching)`
* `✓ ciclo completo saga reserva y compensacion`
