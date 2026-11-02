# MS-03: Gestión de Inventario y Catálogo de Recursos
**Plataforma Distribuida Cuerpo de Bomberos — INF512 (Ingeniería de Software II)**  
**Semestre:** 2/2026 — UAGRM  
**Responsable:** Jael Sandoval  
**Stack Tecnológico:** PHP ^8.2 / 8.3 | Laravel Framework 11.31 | Nuwave Lighthouse 6.74 | PostgreSQL 16 Alpine | PHPUnit 11.0.1  
**Patrón Arquitectónico:** Domain-Driven Design (DDD) Táctico / Clean Architecture  
**Mecanismo de Exposición:** GraphQL API (`POST /graphql`) sobre HTTP  

---

## 1. Encabezado Técnico

* **Microservicio:** `MS-03: Gestión de Inventario y Catálogo de Recursos`
* **Ecosistema:** Plataforma Distribuida Integral de Gestión de Emergencias y Bomberos
* **Semestre Académico:** Semestre 2/2026 — UAGRM
* **Versiones Detectadas en Código:**
  * PHP: `^8.2` (desarrollo y pruebas en PHP 8.2 / 8.3)
  * Laravel Core: `^11.31`
  * Nuwave Lighthouse (GraphQL Engine): `^6.74`
  * PostgreSQL: `16-alpine` (puerto asignado `5435:5432` en Docker Compose)
  * PHPUnit: `^11.0.1`
* **Patrón de Arquitectura:** Domain-Driven Design (DDD) Táctico estructurado en capas concéntricas (Domain, Application, Infrastructure).
* **Mecanismo de Comunicación:** GraphQL API (`POST http://localhost:8003/graphql`), diseñado para resolver problemas de *over-fetching* en reportes administrativos y *under-fetching* (N+1 queries) en auditorías de estaciones. Participa como nodo transaccional en el patrón SAGA orquestado por MS-01.

---

## 2. Arquitectura de Código

Estructura de capas concéntricas implementada en el código fuente (`app/Inventario`):

```text
+---------------------------------------------------------------------------------------+
|                                    INFRASTRUCTURE                                     |
|  +---------------------------------------------------------------------------------+  |
|  |                                   APPLICATION                                   |  |
|  |  +---------------------------------------------------------------------------+  |  |
|  |  |                                  DOMAIN                                   |  |  |
|  |  |  [Value Object]                                                           |  |  |
|  |  |   - PresionOxigeno (Inmutable, valida rango 0-350 bar, >=250 bar seguro)  |  |  |
|  |  |  [Aggregate Root & Entities]                                              |  |  |
|  |  |   - KitProteccionEPP (Invariantes de reserva y recarga operativa)         |  |  |
|  |  |   - InsumoQuimico (Control de volumen y fecha caducidad)                  |  |  |
|  |  |  [Exceptions]                                                             |  |  |
|  |  |   - StockInsuficienteException                                            |  |  |
|  |  |  [Repository Ports]                                                       |  |  |
|  |  |   - KitInventarioRepositoryInterface                                      |  |  |
|  |  |   - SuministroQuimicoRepositoryInterface                                  |  |  |
|  |  +---------------------------------------------------------------------------+  |  |
|  |  [Use Cases / SAGA Application Services]                                        |  |
|  |   - ReservarSuministrosService (Paso 2 SAGA: Reserva de kit + espumógeno)       |  |
|  |   - LiberarSuministrosService (Compensación SAGA: Rollback atómico)             |  |
|  +---------------------------------------------------------------------------------+  |
|  [Adapters / GraphQL / Persistence Drivers]                                           |
|   - SuministrosResolver (Resolvers Lighthouse para queries y mutaciones SAGA)         |
|   - EloquentKitInventarioRepository (Mapeo ORM -> PostgreSQL)                         |
|   - EloquentSuministroQuimicoRepository (Mapeo ORM -> PostgreSQL)                     |
|   - Models: KitEPPModel, SuministroQuimicoModel, TransaccionSagaModel                 |
|   - Database: Migraciones Laravel y Seeders de contingencia                           |
+---------------------------------------------------------------------------------------+
```

---

## 3. Modelo de Persistencia

El microservicio utiliza PostgreSQL 16. La persistencia se define en la migración `database/migrations/2026_11_02_052136_create_inventario_tables.php`:

| Tabla | Clave Primaria (PK) | Claves Foráneas / Índices | Propósito Operativo en el Negocio |
| :--- | :--- | :--- | :--- |
| `estaciones_inventario` | `id` (VARCHAR) | Índice primario `id` | Registra las sedes físicas o estaciones bomberiles (ej. `EST-01`) que almacenan físicamente insumos y equipos. |
| `kits_epp` | `id` (VARCHAR) | `codigo` (UNIQUE), `estacion_id` -> `estaciones_inventario(id)` (ON DELETE CASCADE) | Control de equipos autónomos de respiración (EPP) y tubos ERA. Registra `presion_oxigeno_bares` (requiere $\ge 250$ bar para combate), `estado` (`DISPONIBLE`, `ASIGNADO_CAMION`, `MANTENIMIENTO_REQUERIDO`) y `camion_asignado_id`. |
| `suministros_quimicos` | `id` (VARCHAR) | `codigo` (UNIQUE), `estacion_id` -> `estaciones_inventario(id)` (ON DELETE CASCADE) | Registro volumétrico de agentes extintores (`ESPUMOGENO_AFFF`, `RETARDANTE_FORESTAL`), lote (`lote`), fecha de vencimiento (`fecha_caducidad`) y balance disponible (`litros_disponibles`). |
| `transacciones_saga_inventario` | `id` (VARCHAR) | `transaccion_id` (UNIQUE) | Ledger de auditoría transaccional SAGA. Registra la trazabilidad de reservas vinculadas a un `incidente_id` y `camion_id`, permitiendo la ejecución atómica de mutaciones compensatorias (Rollback). |

---

## 4. Integración Distribuida y Flujos de Negocio

### Diagrama de Secuencia Transaccional SAGA (ASCII)

```text
   MS-01 Comandos           MS-03 Inventario (GraphQL)         MS-02 Flotas
(Orquestador SAGA)              (Participante SAGA)         (Participante SAGA)
        |                                |                           |
        |--- 1. reservarKitParaDespacho ->|                           |
        |    (incidente, camion, litros) |                           |
        |                                |-- Valida Invariantes      |
        |                                |   (Presión >= 250 bar,    |
        |                                |    Litros suficientes)    |
        |                                |-- Transición estado       |
        |                                |   -> ASIGNADO_CAMION      |
        |                                |-- Registra Ledger SAGA    |
        |<-- 2. ReservaPayload(OK) ------|                           |
        |                                                            |
        |--- 3. Asignar Unidad Vehicular --------------------------->|
        |       (Falla: No hay chofer / Neumático pinchado)          |
        |<-- 4. ERROR de Asignación en Flotas -----------------------|
        |                                                            |
        |================= COMPENSACIÓN DISTRIBUIDA =================|
        |--- 5. liberarReservaCompensatoria(transaccionId) --------->|
        |                                |-- Repone litros químicos  |
        |                                |-- Restaura Kit EPP        |
        |                                |   -> DISPONIBLE           |
        |                                |-- Ledger -> COMPENSADO    |
        |<-- 6. LiberacionPayload(OK) ---|                           |
        v                                v                           v
```

### Operaciones Críticas Expuestas en `graphql/schema.graphql`

#### 1. Mutación Transaccional SAGA: `reservarKitParaDespacho`
* **Argumentos:** `incidenteId: ID!`, `camionId: String!`, `litrosEspumogeno: Float!`
* **Validaciones clave:**
  * Presión de oxígeno verificada vía Value Object: $\ge 250\text{ bar}$.
  * Insumo químico con fecha vigente y volumen suficiente.
  * Atomicidad en base de datos PostgreSQL mediante bloqueo y registro en ledger.
* **Respuesta:** `ReservaPayload` (`exito: Boolean!`, `transaccionId: String!`, `materialesReservados: [String!]!`, `mensaje: String!`).

#### 2. Mutación Compensatoria SAGA: `liberarReservaCompensatoria`
* **Argumentos:** `transaccionId: String!`
* **Validaciones clave:**
  * Localización de la transacción en `transacciones_saga_inventario` en estado `RESERVADO`.
  * Reposición exacta de litros consumidos al registro de `suministros_quimicos`.
  * Transición de estado del kit EPP de `ASIGNADO_CAMION` a `DISPONIBLE`.
* **Respuesta:** `LiberacionPayload` (`exito: Boolean!`, `mensaje: String!`).

#### 3. Consultas GraphQL para Auditoría y Monitoreo:
* `auditoriaStockGeneral(estacionId: ID): [SuministroQuimico!]!` — Resuelve el sobre-procesamiento (*over-fetching*) al permitir solicitar únicamente campos específicos (`codigo`, `litrosDisponibles`).
* `obtenerInventarioEstacion(estacionId: ID!): EstacionInventario` — Resuelve el sub-procesamiento (*under-fetching* y problema $N+1$) unificando kits y químicos en una sola petición de red.

---

## 5. Comandos de Inicialización y Ejecución

### Paso 1: Levantar PostgreSQL en Docker
```bash
# Levantar el contenedor PostgreSQL con puerto expuesto 5435
docker run -d --name bomberos-postgres-inventario \
  -e POSTGRES_DB=inventario_db \
  -e POSTGRES_USER=jael_admin \
  -e POSTGRES_PASSWORD=secret_password_123 \
  -p 5435:5432 \
  postgres:16-alpine
```

### Paso 2: Configurar Entorno e Instalar Dependencias
```bash
# Copiar variables de entorno
cp .env.example .env

# Instalar dependencias de Composer
composer install
```

Asegurar las siguientes variables en `.env`:
```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5435
DB_DATABASE=inventario_db
DB_USERNAME=jael_admin
DB_PASSWORD=secret_password_123
```

### Paso 3: Generar Clave y Ejecutar Migraciones
```bash
php artisan key:generate
php artisan migrate
php artisan db:seed
```

### Paso 4: Iniciar el Servidor de Desarrollo
```bash
php artisan serve --port=8003
```

### Paso 5: Ejecución de Tests Automatizados
```bash
php artisan test --filter=InventarioGraphQLTest
```

---

## 6. Enlaces y Documentación de Pruebas

* **Endpoint GraphQL:** `http://localhost:8003/graphql`
* **Playground / GraphiQL:** `http://localhost:8003/graphql` (vía navegador o clientes como Altair / Postman)
* **Contrato GraphQL Compartido:** [ms3_schema.graphql](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/shared_contracts_specs/ms3_schema.graphql)
* **Suite de Pruebas Automatizadas:** [InventarioGraphQLTest.php](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/ms3_inventario_backend/tests/Feature/InventarioGraphQLTest.php)
