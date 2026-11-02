# GUÍA TÉCNICA Y DE ARQUITECTURA: MS-03 GESTIÓN DE INVENTARIO Y RECURSOS
## Plataforma Distribuida Cuerpo de Bomberos — INF512 (Semestre 2/2026)
> **Autor / Responsable:** Jael Mamani  
> **Tecnología Base:** PHP 8.3 / Laravel 11 + PostgreSQL (Docker) + GraphQL (`nuwave/lighthouse`)  
> **Patrón Arquitectónico Central:** Domain-Driven Design (DDD) Táctico estricto  

---

## 1. Propósito del Microservicio y Bounded Context
Este microservicio encapsula el **Contexto Delimitado de Inventario y Recursos Bomberiles** [Punto 01 de la materia]  
Su función es controlar de forma estricta los insumos críticos que salvan vidas:
1. **Equipos de Protección Personal (EPP):** Chaquetones, pantalones ignífugos, cascos y tanques de respiración autónoma (ERA/SCBA).
2. **Suministros Químicos:** Espumógeno AFFF al 3% (para fuegos de hidrocarburos/combustibles) y retardante forestal Phos-Chek.
3. **Participación en la SAGA Táctica:** Ejecuta la reserva atómica de insumos cuando Nataly despacha un camión, y ejecuta el rollback compensatorio si Maikol no puede asignar la unidad.

---

## 2. Implementación de Domain-Driven Design (DDD)
Siguiendo las directrices del [Punto 10], el código está estructurado en 3 capas desacopladas dentro de `app/Inventario/`:

```text
app/Inventario/
├── Domain/                                 # 1. CAPA DE DOMINIO PURO (Sin dependencias de Laravel)
│   ├── Model/
│   │   ├── PresionOxigeno.php              # Value Object Inmutable (Regla de 250 bares)
│   │   ├── KitProteccionEPP.php            # Raíz de Agregado (Aggregate Root con invariantes)
│   │   └── InsumoQuimico.php               # Entidad de Negocio (Control volumétrico de stock)
│   ├── Exceptions/
│   │   └── StockInsuficienteException.php  # Excepciones semánticas de dominio
│   └── Repositories/
│       ├── KitInventarioRepositoryInterface.php        # Contrato de persistencia abstracto
│       └── SuministroQuimicoRepositoryInterface.php    # Contrato de persistencia abstracto
│
├── Application/                            # 2. CAPA DE APLICACIÓN (Casos de Uso del Negocio)
│   └── Services/
│       ├── ReservarSuministrosService.php  # Transacción atómica participante en la SAGA
│       └── LiberarSuministrosService.php   # Acción compensatoria SAGA (Rollback de inventario)
│
└── Infrastructure/                         # 3. CAPA DE INFRAESTRUCTURA (Detalles externos)
    ├── Persistence/
    │   ├── Models/                         # Modelos Eloquent mapeados a PostgreSQL
    │   │   ├── KitEPPEloquentModel.php
    │   │   ├── SuministroQuimicoEloquentModel.php
    │   │   └── TransaccionSagaEloquentModel.php
    │   ├── EloquentKitInventarioRepository.php         # Implementa la interfaz de dominio
    │   └── EloquentSuministroQuimicoRepository.php    # Implementa la interfaz de dominio
    └── GraphQL/
        └── Resolvers/
            └── SuministrosResolver.php     # Resuelve las operaciones del Schema SDL
```

### Conceptos Clave de DDD implementados:
* **Value Object (`PresionOxigeno`):** Inmutable (`readonly class`). No tiene ID propio. Valida que la presión esté entre 0 y 350 bares, y encapsula la regla de seguridad `esSeguraParaCombate()` (requiere $\ge 250$ bares según norma NFPA).
* **Aggregate Root (`KitProteccionEPP`):** Es la puerta de entrada para modificar un kit. Protege la **invariante de negocio**: jamás permite reservar un kit si su estado no es `DISPONIBLE` o si su presión es menor a 250 bares.
* **Inversión de Dependencias (DIP):** El servicio de aplicación `ReservarSuministrosService` depende de `KitInventarioRepositoryInterface` (abstracción). En `AppServiceProvider.php` se inyecta la implementación concreta `EloquentKitInventarioRepository` (tecnología PostgreSQL).

---

## 3. Servidor GraphQL (`nuwave/lighthouse`) y Rescate de Problemas REST

En cumplimiento del [Punto 03], este microservicio utiliza **GraphQL** porque es el módulo de **Gestión Empresarial y Auditoría**:

### A) Eliminación de Over-fetching
El frontend administrativo de inventario puede consultar solo lo que muestra en la tabla resumen sin transferir descripciones químicas pesadas ni fichas de seguridad en PDF:
```graphql
query {
  auditoriaStockGeneral {
    codigo
    nombre
    litrosDisponibles
  }
}
```

### B) Eliminación de Under-fetching / N+1 Requests
En una sola petición HTTP POST, el App Shell puede obtener la estación, sus kits disponibles y sus insumos químicos consolidados:
```graphql
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
```

---

## 4. Guía de Integración para Nataly (Orquestador SAGA en MS-01)

Nataly, cuando tu microservicio `ms1_comandos_backend` ejecute el caso de uso `CU-03: Despachar Unidad de Emergencia`, debes enviar estas dos mutaciones a `POST http://localhost:8000/graphql`:

### Paso 1: Mutación de Reserva (Happy Path)
```graphql
mutation {
  reservarKitParaDespacho(
    incidenteId: "INC-8821"
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
* **Respuesta exitosa:** Devuelve `exito: true` y un `transaccionId` (ej. `TX-SAGA-INV-A1B2C3D4`).
* **Respuesta de rechazo:** Si no hay stock o los tanques tienen menos de 250 bares, devuelve `exito: false` y el código de error para que tu SAGA active el rollback de flotas.

### Paso 2: Mutación Compensatoria (Rollback si falla Maikol en Flotas)
Si Maikol (`ms2_flotas_backend`) no logra reservar el camión cisterna, tu orquestador SAGA debe invocar la compensación enviando el `transaccionId`:
```graphql
mutation {
  liberarReservaCompensatoria(
    transaccionId: "TX-SAGA-INV-A1B2C3D4"
  ) {
    exito
    mensaje
  }
}
```
* **Efecto:** Inventario devuelve el kit EPP a estado `DISPONIBLE`, repone los litros de espumógeno y garantiza la **consistencia eventual**.

---

## 5. Datos Sembrados en PostgreSQL para Pruebas (Seeders)

La base de datos PostgreSQL ya contiene datos precargados listos para la defensa ante el Ing. Martínez:

| Código | Recurso | Presión / Volumen | Estado Inicial | Comportamiento en Demostración |
|---|---|:---:|:---:|---|
| `KIT-A-101` | Kit EPP Tanque Oxígeno | 300 bares | `DISPONIBLE` | Pasa la reserva con éxito ($\ge 250$ bares). |
| `KIT-A-102` | Kit EPP Tanque Oxígeno | 280 bares | `DISPONIBLE` | Pasa la reserva con éxito. |
| `KIT-A-103` | Kit EPP Tanque Oxígeno | 190 bares | `MANTENIMIENTO_REQUERIDO` | **Rechazado por invariante:** Presión insuficiente para entrar al fuego. |
| `QUIM-AFFF-01` | Espumógeno AFFF 3% | 1,500 Litros | Operativo | Se descuenta automáticamente al despachar. |
| `QUIM-RET-02` | Retardante Phos-Chek | 3,200 Litros | Operativo | Para emergencias forestales. |

---

## 6. Comandos para Levantar y Probar

1. **Asegurar que PostgreSQL esté corriendo en Docker:**
   ```bash
   docker compose -f ../docker-compose.jael.yml up -d
   ```
2. **Ejecutar servidor local:**
   ```bash
   php artisan serve --port=8003
   ```
3. **Ejecutar la suite de pruebas automatizadas:**
   ```bash
   php artisan test --filter=InventarioGraphQLTest
   ```
   *Todas las 4 pruebas unitarias y de integración pasan con 16 aserciones en verde.*

---


