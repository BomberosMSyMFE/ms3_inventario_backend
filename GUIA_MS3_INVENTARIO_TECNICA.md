# GUÍA TÉCNICA MAESTRA: MS-03 GESTIÓN DE INVENTARIO Y RECURSOS
## Sistema Integrado de Comando y Control de Incidentes — Bomberos SCZ
> **Asignatura:** Ingeniería de Software II (INF512) — UAGRM (Semestre 2/2026)  
> **Docente:** M.Sc. Ing. Rolando Antonio Martínez Cañedo  
> **Responsable del Subsistema:** Jael Sandoval (Ingeniería de Software / Backend & Automatización)  
> **Tecnología Base:** PHP 8.3 / Laravel 11 + PostgreSQL 16 + GraphQL (`nuwave/lighthouse`) + n8n + Node.js (Baileys WhatsApp)  
> **Puerto Asignado Backend:** `8003` | **PostgreSQL Docker:** `5435` (interno `5432`) | **n8n:** `5678` | **WhatsApp Gateway:** `8088`

---

## 1. Resumen Ejecutivo y Ficha Técnica del Microservicio

El subsistema **MS-03 (Inventario y Recursos)** gobierna la logística crítica de las estaciones de bomberos en Santa Cruz de la Sierra. Su misión es garantizar que ninguna unidad de emergencia sea despachada sin insumos certificados para salvaguardar la vida de los bomberos y la ciudadanía.

```text
===================================================================================================
                       TOPOLOGÍA INTERNA DEL MICROSERVICIO MS-03
===================================================================================================

 [ FRONTEND MFE-03 ]                  [ ORQUESTADOR MS-01 ]
 (Vue 3 / Apollo :3003)               (NestJS Nataly :3000)
         |                                     |
         +-----------------+-------------------+
                           |
                           v  HTTP POST (GraphQL SDL)
            +------------------------------+
            |  MS-03 INVENTARIO BACKEND    |
            |  (Laravel 11 / PHP 8.3 :8003)|
            +------------------------------+
              |              |           |
              | DDD / ORM    | HTTP POST | Eventos Stock Crítico / SAGA
              v              |           v
     +-----------------+     |     +-------------------------+
     |  PostgreSQL 16  |     |     |   n8n Automation Engine |
     |  (Docker :5435) |     |     |   (Docker Engine :5678) |
     +-----------------+     |     +-------------------------+
                             |                   |
                             | Webhook           | HTTP POST
                             v                   v
            +------------------------------------------------+
            | WhatsApp Gateway (Node.js Baileys REST :8088)  |
            +------------------------------------------------+
                                     |
                                     v WebSocket WA
                        [ Teléfono Celular Operador ]
                        (+591 60495035 / +591 68809827)
===================================================================================================
```

### Tabla de Puertos y Servicios del Subsistema MS-03:
| Servicio / Contenedor | Tecnología | Puerto Host | Puerto Contenedor | Descripción |
|---|---|:---:|:---:|---|
| `ms3_inventario_backend` | Laravel 11 / PHP 8.3 | **8003** | 8003 | API GraphQL con Lighthouse, Casos de Uso DDD y Logs Tácticos |
| `bomberos-postgres-inventario` | PostgreSQL 16 Alpine | **5435** | 5432 | Base de datos relacional transaccional (Dataset de 1,000 registros) |
| `bomberos-n8n-automation` | n8n Community Latest | **5678** | 5678 | Orquestador de flujos de automatización y alertas operativas |
| `whatsapp_gateway` | Node.js 20 / Express / Baileys | **8088** | 8088 | Micro-puente WebSocket a WhatsApp con soporte de QR y REST |
| `mfe3_inventario_frontend` | Vue 3 / Vite 5 / Tailwind | **3003** | 3003 | Microfrontend táctico federado vía Module Federation |

---

## 2. Arquitectura de Software: Domain-Driven Design (DDD) Táctico

El subsistema aplica **Domain-Driven Design (DDD) Táctico estricto** en `app/Inventario/`, desacoplando completamente las reglas de negocio de la infraestructura y el framework:

```text
app/Inventario/
├── Domain/                                     # 1. DOMINIO PURO (Sin dependencias de Laravel ni BD)
│   ├── Model/
│   │   ├── PresionOxigeno.php                  # Value Object Inmutable (Regla NFPA: >= 250 Bares)
│   │   ├── KitProteccionEPP.php                # Aggregate Root con invariantes de reserva
│   │   ├── InsumoQuimico.php                   # Entidad para control volumétrico de espumógenos
│   │   └── HerramientaEquipo.php               # Entidad de maquinaria con horómetro y fatiga
│   ├── Exceptions/
│   │   ├── StockInsuficienteException.php      # Excepción de dominio semántica
│   │   └── PresionInvalidaException.php        # Error al violar límites físicos de tanques
│   └── Repositories/
│       ├── KitInventarioRepositoryInterface.php     # Contrato de persistencia de Kits ERA
│       └── SuministroQuimicoRepositoryInterface.php # Contrato de persistencia de Químicos
│
├── Application/                                # 2. CASOS DE USO (Orquestación del Negocio)
│   └── Services/
│       ├── ReservarSuministrosService.php      # Caso de uso: Participante atómico SAGA con Idempotencia
│       └── LiberarSuministrosService.php       # Caso de uso: Rollback compensatorio SAGA con Idempotencia
│
└── Infrastructure/                             # 3. INFRAESTRUCTURA (Detalles externos y adapters)
    ├── Persistence/
    │   ├── Models/                             # Modelos Eloquent mapeados a PostgreSQL
    │   │   ├── KitEPPEloquentModel.php
    │   │   ├── SuministroQuimicoEloquentModel.php
    │   │   ├── HerramientaEquipoEloquentModel.php
    │   │   ├── MovimientoInventarioEloquentModel.php
    │   │   └── TransaccionSagaEloquentModel.php
    │   ├── EloquentKitInventarioRepository.php
    │   └── EloquentSuministroQuimicoRepository.php
    ├── Services/
    │   └── AlertaQuiebreStockN8nService.php   # Cliente HTTP que dispara Webhooks a n8n y WhatsApp
    └── GraphQL/
        └── Resolvers/
            └── SuministrosResolver.php         # Resolutor de consultas y mutaciones SDL
```

### Conceptos Clave de Dominio Implementados:
1. **Value Object Inmutable (`PresionOxigeno`):**
   - Declarado como `readonly class`.
   - No tiene identificador (`id`), su identidad radica exclusivamente en su magnitud (bares).
   - Valida que la presión se encuentre en el rango físico real: $0 \le \text{bares} \le 350$.
   - Encapsula la regla de seguridad NFPA 1852: `esSeguraParaCombate(): bool => $this->bares >= 250`.
2. **Aggregate Root (`KitProteccionEPP`):**
   - Es el punto único de entrada para modificar o reservar un kit de respiración autónoma (ERA).
   - Defiende su **invariante de negocio**: jamás permite reservar un kit si su estado no es `DISPONIBLE` o si su presión de oxígeno es menor a 250 bares.
3. **Mantenimiento Predictivo / Horómetros de Herramientas (`HerramientaEquipo`):**
   - Control continuo de horas acumuladas de uso (`horas_uso`) vs umbral crítico de fatiga de materiales (`umbral_fatiga_horas`).
   - Algoritmo de clasificación de estado: Si $\text{horas\_uso} \ge \text{umbral}$, el equipo transiciona automáticamente a `MANTENIMIENTO_REQUERIDO`, alertando al taller mecánico.
4. **Inversión de Dependencias (DIP):**
   - Los servicios de aplicación dependen únicamente de abstracciones (`KitInventarioRepositoryInterface`). La implementación concreta con PostgreSQL (`EloquentKitInventarioRepository`) es inyectada en tiempo de ejecución por `AppServiceProvider.php`.

---

## 3. Capa de Interfaz GraphQL (`nuwave/lighthouse`)

Cumpliendo con los requerimientos de la materia, MS-03 utiliza **GraphQL** en lugar de REST tradicional para resolver los problemas clásicos de integración:

* **Eliminación de Over-fetching:** El frontend o servicios externos solicitan únicamente los campos que necesitan, evitando transmitir información innecesaria (hojas de seguridad químicas pesadas, fichas técnicas o metadatos de auditoría).
* **Eliminación de Under-fetching / N+1 Requests:** Con una sola solicitud HTTP POST al endpoint `/graphql`, se obtienen de forma combinada los datos de la estación, kits de respiración y agentes extintores.

### Schema SDL Principal (`graphql/schema.graphql`):
```graphql
type Query {
  "Auditoría rápida de stock sin over-fetching"
  auditoriaStockGeneral: [ItemStockAuditoria!]!

  "Consulta agregada de inventario completo por estación (evita N+1)"
  obtenerInventarioEstacion(estacionId: String!): InventarioEstacionResponse!

  "Listado de kits de respiración autónoma EPP con estado de manómetro"
  obtenerKitsEPP: [KitEPPResponse!]!

  "Listado de insumos químicos con litros y fechas de caducidad"
  obtenerSuministrosQuimicos: [SuministroQuimicoResponse!]!

  "Listado de maquinaria con horómetros y control de fatiga"
  obtenerHerramientasEquipos: [HerramientaEquipoResponse!]!

  "Bitácora de movimientos históricos de almacén"
  obtenerMovimientosInventario(limite: Int): [MovimientoInventarioResponse!]!

  "Historial de transacciones distribuidas SAGA"
  obtenerTransaccionesSaga: [TransaccionSagaResponse!]!
}

type Mutation {
  "Mutación participante en la SAGA: Reserva atómica para despacho táctico"
  reservarKitParaDespacho(
    incidenteId: String!
    camionId: String!
    litrosEspumogeno: Float!
    estacionId: String
  ): ReservaResponse!

  "Mutación compensatoria SAGA: Rollback de materiales por fallo en otro MS"
  liberarReservaCompensatoria(
    transaccionId: String!
  ): LiberacionResponse!

  "Ajuste manual de stock por recepción o auditoría de almacén"
  ajustarStockInsumo(
    itemCodigo: String!
    categoria: String!
    cantidadDelta: Float!
    motivo: String!
    responsable: String
  ): AjusteStockResponse!
}
```

---

## 4. Protocolo SAGA Distribuido e Idempotencia (Contrato con Nataly / MS-01)

Cuando el microservicio de Comandos de Nataly (`ms1_comandos_backend`) ejecuta el caso de uso **CU-03: Despachar Unidad de Emergencia**, interactúa con MS-03 mediante dos mutaciones GraphQL a `http://localhost:8003/graphql`:

### Paso 1: Mutación de Reserva Atómica (Happy Path)
```graphql
mutation ReservarDespacho {
  reservarKitParaDespacho(
    incidenteId: "INC-2026-8821"
    camionId: "B-04-SCZ"
    litrosEspumogeno: 150.0
    estacionId: "EST-01"
  ) {
    exito
    transaccionId
    materialesReservados
    mensaje
  }
}
```
* **Respuesta Exitosa (`exito: true`):** Retorna un `transaccionId` único (ej. `TX-SAGA-INV-ZGRW4QHU`), descuenta 150L de espumógeno, asigna el kit ERA disponible con $\ge 250$ bares y emite logs tácticos.
* **Respuesta de Rechazo (`exito: false`):** Si no hay suficiente químico o no hay kits con presión segura, devuelve el error para que el orquestador aborte el despacho.

### Paso 2: Mutación Compensatoria / Rollback (Compensating Transaction)
Si el microservicio de Flotas de Maikol (`ms2_flotas_backend`) no logra asignar el camión o el personal de Bomberos falla, el orquestador de Nataly envía la mutación compensatoria:
```graphql
mutation CompensarDespacho {
  liberarReservaCompensatoria(
    transaccionId: "TX-SAGA-INV-ZGRW4QHU"
  ) {
    exito
    mensaje
  }
}
```
* **Efecto Compensatorio:** Reincorpora de inmediato los 150L de espumógeno al stock disponible, libera el kit ERA devuelto a estado `DISPONIBLE`, marca la transacción SAGA como `COMPENSADO` y dispara la alerta n8n informando la cancelación.

### Garantía de Idempotencia Táctica:
Para evitar inconsistencias producidas por reintentos de red (redes celulares inestables de bomberos o reintentos de Axios):
* Si `reservarKitParaDespacho` recibe un `incidenteId` que ya tiene una transacción activa, **no duplica el descuento de stock**; intercepta la solicitud, reutiliza la transacción existente y responde con la confirmación previa (`[SAGA-IDEMPOTENCIA]`).
* Si `liberarReservaCompensatoria` se ejecuta más de una vez para el mismo `transaccionId`, detecta que ya fue compensado y **no suma dos veces el inventario**.

---

## 5. Automatización de Procesos con n8n y Notificaciones WhatsApp

MS-03 cuenta con un pipeline de automatización para comunicar eventos críticos directamente a los teléfonos celulares de los oficiales de guardia mediante WhatsApp:

```text
  [ MS-03 Inventario ]
           |
           | HTTP POST (Alerta Stock Crítico / SAGA Compensación)
           v
  [ Webhook n8n :5678 ] ---> http://localhost:5678/webhook/alerta-quiebre-stock
           |
           +---> [ Nodo Formateador JS ] (Construye tarjeta estructurada)
           |
           +---> [ HTTP Request a MS-01 ] (http://127.0.0.1:3000/api/v1/alarmas/iniciar-triaje)
           |
           +---> [ HTTP Request a Gateway :8088 ] (http://host.docker.internal:8088/enviar)
                                 |
                                 v
                     [ WhatsApp Oficial de Bomberos ]
```

### Credenciales y Parámetros del Entorno n8n:
* **URL de Acceso Web:** `http://localhost:5678`
* **Usuario Propietario / Admin:** `admin@bomberos.gob.bo`
* **Nombre del Flujo Activo:** `Pipeline_Bomberos_Inventario_WhatsApp_Jael`
* **Webhook Endpoint:** `POST http://localhost:5678/webhook/alerta-quiebre-stock`
* **Archivo de Definición del Flujo:** [`ms3_inventario_backend/n8n/workflow_inventario_stock_whatsapp_n8n.json`](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/ms3_inventario_backend/n8n/workflow_inventario_stock_whatsapp_n8n.json)

### WhatsApp Gateway (Baileys Node.js):
* **Directorio:** `ms3_inventario_backend/n8n/whatsapp_gateway/`
* **Puerto:** `8088`
* **Estado de Conexión:** Vinculado vía WebSocket con persistencia de credenciales en `auth_info_baileys/`.
* **Endpoints del Gateway:**
  - `GET http://localhost:8088/`: Interfaz visual con estado de conexión, teléfono activo y visualizador de código QR.
  - `GET http://localhost:8088/status`: JSON con `{ conectado: true, telefono: "59168809827", sesion: "ACTIVA" }`.
  - `POST http://localhost:8088/enviar`: `{ "numero": "59160495035", "mensaje": "..." }`.

### Formato de Tarjetas WhatsApp Enviadas:
Las alertas no son párrafos planos desordenados; utilizan tipografía táctica de WhatsApp (negritas `*`, bloques monospace `` ` ``, viñetas y saltos limpios):

```text
🚨 *ALERTA TÁCTICA DE SUMINISTROS*
*Cuerpo de Bomberos Santa Cruz - MS-03*
────────────────────────
⚠️ *Evento:* QUIEBRE DE STOCK CRÍTICO
📦 *Insumo:* Espumógeno AFFF 3% Chemguard
🏷️ *Código:* `QUIM-AFFF-01`
📊 *Stock Actual:* 120.00 Lts (Mínimo: 200.00 Lts)
🏢 *Estación:* Estación Central N° 1 (Centro)
📅 *Fecha:* 06/11/2026 03:00
────────────────────────
👉 _Se solicita al encargado de logística gestionar reposición inmediata._
```

---

## 6. Sistema de Logs Tácticos Estructurados

Para diagnósticos rápidos durante las defensas y pruebas inter-microservicios, MS-03 emite logs con etiquetas tácticas estandarizadas tanto a la terminal como a `storage/logs/laravel.log`:

| Etiqueta de Log | Significado Operativo |
|---|---|
| `[GRAPHQL-SAGA]` | Recepción de una mutación SAGA desde el exterior |
| `[SAGA-RESERVA-INICIO]` | Inicio del procesamiento atómico de reserva de materiales |
| `[SAGA-RESERVA-CONSOLIDADA]` | Éxito en la reserva; asignación de kit y deducción de químicos |
| `[SAGA-COMPENSACION-INICIO]` | Solicitud de rollback o compensación de una transacción |
| `[SAGA-COMPENSACION]` | Materiales reincorporados satisfactoriamente a las tablas |
| `[SAGA-IDEMPOTENCIA]` | Detección de petición duplicada; neutralización de doble cargo |
| `[n8n-SAGA-RESERVA]` | Webhook de notificación disparado al pipeline de n8n |
| `[HERRAMIENTA-HOROMETRO]` | Maquinaria que supera el umbral de fatiga y entra en mantenimiento |
| `[STOCK-MOVIMIENTO]` | Registro histórico generado en la bitácora de auditoría |

### Monitoreo en Tiempo Real:
```powershell
# En Windows PowerShell desde ms3_inventario_backend:
Get-Content -Path storage/logs/laravel.log -Wait -Tail 30
```

---

## 7. Dataset Maestro Oficial de 1,000 Registros

El backend incorpora un **dataset realista de exactamente 1,000 registros** diseñado para pruebas de volumen, auditorías y algoritmos de mantenimiento predictivo:

```text
===================================================================================================
                   DESGLOSE DEL DATASET MAESTRO MS-03 (1,000 REGISTROS)
===================================================================================================
- kits_epp:              150 registros  (Kits ERA con manómetros de 140 a 310 bares)
- suministros_quimicos:   50 registros  (Espumógenos AFFF 1/3/6%, Phos-Chek, PQS ABC, FM-200, CO2)
- herramientas_equipos:  150 registros  (Motobombas, generadores, motosierras, cizallas Holmatro)
- movimientos_inventario: 650 registros  (Historial de 6 meses: Entradas, Salidas, SAGA y Compensaciones)
---------------------------------------------------------------------------------------------------
TOTAL:                 1,000 registros  (PostgreSQL verificado)
===================================================================================================
```

### Ubicación de los Archivos del Dataset:
* **Clase Seeder:** [`ms3_inventario_backend/database/seeders/DatasetMilRegistrosSeeder.php`](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/ms3_inventario_backend/database/seeders/DatasetMilRegistrosSeeder.php)
* **Script SQL Plano:** [`ms3_inventario_backend/database/data/dataset_1000_inventario.sql`](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/ms3_inventario_backend/database/data/dataset_1000_inventario.sql) (389 KB)
* **Archivo CSV de Movimientos:** [`ms3_inventario_backend/database/data/dataset_movimientos_650.csv`](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/ms3_inventario_backend/database/data/dataset_movimientos_650.csv) (155 KB)
* **Archivo JSON Estructurado:** [`ms3_inventario_backend/database/data/dataset_1000_inventario.json`](file:///C:/Users/Usuario/Documents/U/Semestre%20II-2026/SW2/SegundoParcial/ms3_inventario_backend/database/data/dataset_1000_inventario.json) (499 KB)

---

## 8. Guía de Ejecución Local Paso a Paso (Para el Equipo)

Sigue estos pasos en orden para levantar todo el subsistema MS-03 desde cero en una máquina nueva:

### Prerrequisitos:
* Docker Desktop instalado y corriendo.
* PHP 8.2 o 8.3 con extensiones `pdo_pgsql`, `intl`, `bcmath`, `curl`.
* Composer 2.7+.
* Node.js 18+ o 20+.

---

### Paso 1: Levantar los Contenedores Docker (PostgreSQL y n8n)
Desde la raíz del repositorio (`SegundoParcial/`):
```powershell
# Levantar PostgreSQL de Inventario (Puerto 5435)
docker compose -f docker-compose.jael.yml up -d

# Levantar n8n Automation Engine (Puerto 5678)
docker compose -f docker-compose.n8n.yml up -d
```
*Verificar que ambos contenedores estén `Up` con `docker ps`.*

---

### Paso 2: Configurar y Levantar el Backend Laravel (MS-03)
En una nueva terminal, ingresa a `ms3_inventario_backend`:
```powershell
cd ms3_inventario_backend

# 1. Copiar archivo de entorno si no existe
copy .env.example .env

# 2. Asegurar que las variables de conexión a la BD en .env sean:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5435
# DB_DATABASE=inventario_db
# DB_USERNAME=jael_admin
# DB_PASSWORD=secret_password_123

# 3. Instalar dependencias PHP
composer install

# 4. Generar clave de aplicación (si es instalación nueva)
php artisan key:generate

# 5. Ejecutar migraciones y sembrar el dataset maestro de 1,000 registros
php artisan migrate:fresh --seed

# 6. Iniciar el servidor backend en el puerto 8003
php artisan serve --port=8003
```
*El backend quedará escuchando en `http://127.0.0.1:8003` y el GraphQL Playground en `http://127.0.0.1:8003/graphql`.*

---

### Paso 3: Iniciar el Gateway de WhatsApp (Baileys)
En otra terminal, ingresa a la carpeta del gateway:
```powershell
cd ms3_inventario_backend/n8n/whatsapp_gateway

# 1. Instalar dependencias Node.js
npm install

# 2. Iniciar el microservicio del gateway
node server.js
```
*El gateway iniciará en `http://localhost:8088`. Si es la primera vez que se ejecuta, abrir el navegador en esa URL y escanear el código QR con el WhatsApp del teléfono asignado.*

---

### Paso 4: Configurar el Flujo en n8n
1. Abre tu navegador en `http://localhost:5678`.
2. Inicia sesión con:
   - **Email:** `admin@bomberos.gob.bo`
   - O si es primera vez, completa el formulario de bienvenida de n8n.
3. Dirígete a **Workflows** > **Import from File**.
4. Selecciona el archivo `ms3_inventario_backend/n8n/workflow_inventario_stock_whatsapp_n8n.json`.
5. Haz clic en **Activate** (botón superior derecho en verde) para que el Webhook quede en modo escucha permanente.

---

### Paso 5: Ejecución de la Suite de Pruebas Automatizadas
Para verificar que todo el dominio, GraphQL, SAGA, Idempotencia y conexión a BD estén operando al 100%:
```powershell
cd ms3_inventario_backend
php artisan test
```
**Resultado esperado:**
```text
PASS Tests\Unit\ExampleTest
PASS Tests\Feature\ExampleTest
PASS Tests\Feature\InventarioGraphQLTest
Tests: 7 passed (28 assertions)
```
