# Laravel Arca

Facturación electrónica para aplicaciones Laravel mediante conexión SOAP directa a los servicios WSAA y WSFEv1 de ARCA. El cliente AfipSDK se conserva temporalmente como adaptador opcional de compatibilidad.

El paquete mantiene al CRM separado de los códigos y estructuras de ARCA. El CRM trabaja con términos de negocio como `Factura A`, `Nota de crédito B`, `USD` y `punto de venta`; el módulo realiza el mapeo, administra la numeración, autoriza el comprobante y conserva el intercambio técnico completo.

El módulo no envía ni administra artículos individuales. La autorización recibe importes agrupados y la discriminación de IVA requerida por ARCA.

## Requisitos

- PHP 8.3+
- Laravel 13+
- Extensión PHP OpenSSL
- Extensiones PHP DOM y cURL
- Certificado X.509 y clave privada asociados al servicio WSFE en ARCA

## Instalación

```bash
composer require afurgeri/laravel-arca
php artisan migrate
```

Laravel registra automáticamente el `ArcaServiceProvider` mediante Composer package discovery.

El paquete carga sus migraciones automáticamente. No es necesario copiarlas a `database/migrations`.

## Configuración

Para publicar el archivo de configuración:

```bash
php artisan vendor:publish --tag=arca-config
```

Esto crea `config/arca.php`. La configuración predeterminada es:

```php
return [
    'storage_driver' => env('ARCA_STORAGE_DRIVER', 'eloquent'),
    'connection' => env('ARCA_DB_CONNECTION'),
    'driver' => env('ARCA_DRIVER', 'soap'),
    'cuit' => env('ARCA_CUIT'),
    'certificate_path' => env('ARCA_CERTIFICATE_PATH'), // Compatibilidad, preferir rutas por ambiente.
    'private_key_path' => env('ARCA_PRIVATE_KEY_PATH'),
    'certificates' => [
        'testing' => [
            'certificate_path' => env('ARCA_TESTING_CERTIFICATE_PATH'),
            'private_key_path' => env('ARCA_TESTING_PRIVATE_KEY_PATH'),
        ],
        'production' => [
            'certificate_path' => env('ARCA_PRODUCTION_CERTIFICATE_PATH'),
            'private_key_path' => env('ARCA_PRODUCTION_PRIVATE_KEY_PATH'),
        ],
    ],
    'production' => (bool) env('ARCA_PRODUCTION', false),
    'timeout' => (int) env('ARCA_TIMEOUT', 20),
    'connect_timeout' => (int) env('ARCA_CONNECT_TIMEOUT', 5),
    'ssl_cipher_list' => env('ARCA_SSL_CIPHER_LIST', 'ECDHE+AESGCM'),
    'ticket_cache_store' => env('ARCA_TICKET_CACHE_STORE'),
    'ticket_cache_safety_seconds' => (int) env('ARCA_TICKET_CACHE_SAFETY_SECONDS', 300),
    'table_prefix' => env('ARCA_TABLE_PREFIX', 'arca_'),
    'route_prefix' => env('ARCA_ROUTE_PREFIX', 'api/arca'),
    'route_middleware' => ['web', 'auth', 'verified'],
];
```

Variables de entorno:

```ini
ARCA_CUIT=20111111112
ARCA_STORAGE_DRIVER=eloquent
ARCA_DB_CONNECTION=mysql
ARCA_DRIVER=soap
ARCA_TESTING_CERTIFICATE_PATH=/secure/path/homologacion.crt
ARCA_TESTING_PRIVATE_KEY_PATH=/secure/path/homologacion.key
ARCA_PRODUCTION_CERTIFICATE_PATH=/secure/path/produccion.crt
ARCA_PRODUCTION_PRIVATE_KEY_PATH=/secure/path/produccion.key
ARCA_PRODUCTION=false
ARCA_TIMEOUT=20
ARCA_CONNECT_TIMEOUT=5
ARCA_SSL_CIPHER_LIST=ECDHE+AESGCM
ARCA_TICKET_CACHE_STORE=redis
ARCA_TICKET_CACHE_SAFETY_SECONDS=300
ARCA_TABLE_PREFIX=arca_
ARCA_ROUTE_PREFIX=api/arca
```

`ARCA_PRODUCTION=false` utiliza homologación; `true` selecciona producción. Para el cliente directo, WSAA obtiene el ticket temporal de acceso usando el certificado y la clave: no se requiere token de AfipSDK. Mantén las claves privadas fuera del repositorio y usa una caché compartida entre instancias de la aplicación.

`ARCA_DRIVER=soap` selecciona la conexión directa y es el valor predeterminado. Para mantener temporalmente la integración anterior, configura `ARCA_DRIVER=afipsdk` y `ARCA_ACCESS_TOKEN`; no hay fallback automático entre conectores.

El adaptador AfipSDK puede usarse como contingencia mediante selección explícita. Si ARCA rechaza las fechas de su ticket con el error `(600) ValidacionDeToken: No validaron las fechas del token`, las consultas de tipos, puntos de venta, último número y comprobante individual fuerzan una renovación WSAA mediante AfipSDK y se repiten una sola vez. La renovación se registra sin credenciales; si falla o el segundo intento falla, el error se propaga.

La emisión no se reintenta automáticamente, tampoco por ticket vencido. Ante una respuesta incierta, reconcilia el comprobante antes de emitir de nuevo o cambiar de proveedor. Usar el mismo certificado desde distintas aplicaciones o conectores puede provocar `alreadyAuthenticated`: no borres tickets válidos ni fuerces renovaciones en bucle. Ambos conectores dependen de la disponibilidad de ARCA.

### Almacenamiento SQL o MongoDB

El almacenamiento es independiente del conector fiscal: tanto `soap` como `afipsdk` pueden usarse con cualquiera de los dos adaptadores.

SQL es el predeterminado y conserva las tablas, modelos e IDs enteros existentes:

```ini
ARCA_STORAGE_DRIVER=eloquent
ARCA_DB_CONNECTION=mysql
```

`ARCA_DB_CONNECTION` es el nombre de una conexión definida en `config/database.php`, no el nombre del motor. Si se omite, se usa la conexión predeterminada de Laravel. Los modelos, relaciones y transacciones de ARCA usan esa conexión, aunque la aplicación principal utilice otra. SQLite se usa para regresiones; las reservas concurrentes se verifican también con MySQL real.

Para MongoDB, instala la integración oficial en la aplicación consumidora:

```bash
composer require mongodb/laravel-mongodb:^5.11
```

```ini
ARCA_STORAGE_DRIVER=mongodb
ARCA_DB_CONNECTION=mongodb
```

Define la conexión `mongodb` en `config/database.php` con su URI y base de datos.

**MongoDB requiere un replica set o un clúster fragmentado con soporte de transacciones. Esto también aplica a instalaciones de una sola instancia: configura un replica set de un solo nodo. Un servidor en modo standalone no está soportado para reservar y emitir comprobantes.**

No necesitas agregar otro servidor: un replica set de un nodo habilita transacciones, aunque no proporciona alta disponibilidad. El adaptador las utiliza para actualizar la secuencia y crear la reserva del comprobante de forma consistente ante solicitudes simultáneas. No hay fallback automático a escrituras sin transacción.

Para habilitarlo, inicia Mongo con `mongod --replSet rs0` (conservando las demás opciones de tu servicio), inicializa el conjunto una sola vez con `rs.initiate(...)` y espera a que el nodo sea primario. El hostname configurado para el miembro debe ser accesible desde los clientes y desde Mongo. En servicios existentes, planifica el reinicio, respalda los datos y revisa la configuración de autenticación antes de realizar el cambio.

Para desarrollo local, si Mongo está publicado en `127.0.0.1:27017` y Laravel corre directamente en tu máquina, una URI posible es:

```ini
MONGODB_URI=mongodb://127.0.0.1:27017/?replicaSet=rs0&directConnection=true
```

La configuración de `database.connections.mongodb.dsn` debe leer esa variable. Los parámetros de la URI no convierten un standalone en replica set: el servidor debe estar configurado e inicializado previamente. `directConnection=true` es útil para este entorno local de un nodo; no copies esa configuración sin revisar la topología de producción o Kubernetes.

Una aplicación SQL no necesita instalar la dependencia MongoDB ni su extensión PHP.

Con la configuración elegida, ejecuta las migraciones:

```bash
php artisan migrate
```

El proveedor registra únicamente las migraciones del adaptador seleccionado. Las migraciones SQL conservan sus nombres y operaciones de esquema; su conexión ahora respeta `ARCA_DB_CONNECTION`. Si la conexión principal de la aplicación es distinta, Laravel mantiene su historial de migraciones en la conexión del comando y ejecuta los cambios de ARCA en la conexión configurada. Para administrar el historial específicamente en la base de ARCA, utiliza `php artisan migrate --database=nombre_de_conexion`; ten en cuenta que ese comando también puede ejecutar migraciones pendientes de otros paquetes.

Mongo utiliza las colecciones `arca_points_of_sale`, `arca_sequences`, `arca_vouchers` y `arca_operations`, respetando `ARCA_TABLE_PREFIX`. Los índices únicos parciales permiten múltiples comprobantes sin clave de idempotencia o con número liberado, pero impiden duplicar claves o números vigentes. No se utilizan claves foráneas ni cascadas SQL: no elimines directamente documentos referenciados.

La API del manager y `AuthorizationResult::toArray()` se mantienen. `voucher_id` es un entero en SQL y un string en MongoDB. Los modelos SQL siguen en `Modules\Arca\Models`; los modelos documentales están en `Modules\Arca\Models\Mongo` y usan el trait oficial `DocumentModel`. Prefiere los managers para código independiente del motor; los imports de modelos SQL no cambian automáticamente a Mongo.

Cambiar el adaptador no copia ni migra información entre bases. No cambies de motor sobre una cuenta con operaciones pendientes sin una migración y reconciliación explícitas. La dependencia MongoDB es opcional en producción y se incorpora como dependencia de desarrollo para las pruebas del paquete.

### Varias cuentas y CUIT

La aplicación consumidora administra las cuentas y sus secretos. `laravel-arca` no guarda API keys, certificados ni claves privadas. Para operar varias cuentas en el mismo proceso, crea un `ArcaManager` independiente por cuenta mediante `ArcaFactory`; el alcance de los datos queda aislado por CUIT y ambiente (`testing` u `production`). El CUIT y el ambiente se toman de las credenciales, nunca de los datos del comprobante.

```php
use Modules\Arca\ArcaFactory;
use Modules\Arca\Data\ArcaCredentials;

$arca = app(ArcaFactory::class)->forCredentials(new ArcaCredentials(
    cuit: $account->cuit,
    production: true,
    certificate: $account->certificate_pem,
    privateKey: $account->private_key_pem,
));

$arca->pointOfSales()->syncFromArca();
$result = $arca->authorize($voucherData);
```

`certificate` y `privateKey` reciben el contenido PEM directamente y deben enviarse juntos. `accessToken` queda disponible únicamente para el adaptador AfipSDK de compatibilidad. Para usar un `ArcaClient` propio o un fake, se puede construir el manager con `ArcaFactory::forClient($client, $cuit, 'testing')`. No compartas una instancia de manager entre cuentas: crea una por cada par CUIT/ambiente. La fachada y las rutas existentes continúan usando la cuenta predeterminada de `config/arca.php`; en una aplicación multiusuario, la aplicación debe resolver la cuenta autorizada para cada usuario y llamar a la fábrica con sus credenciales.

### Actualizar datos existentes a multi-CUIT

La migración agrega CUIT y ambiente sin cambiar IDs ni relaciones, pero deja los registros preexistentes sin asignar. Antes de volver a habilitar facturación en una aplicación ya instalada, determina a qué cuenta corresponden los datos heredados y ejecuta primero una simulación:

```bash
php artisan migrate
php artisan arca:assign-legacy --cuit=30712345678 --environment=production --dry-run
```

Si los conteos son los esperados, repite sin `--dry-run` para asignar los puntos de venta, comprobantes y operaciones que todavía no tienen ámbito:

```bash
php artisan arca:assign-legacy --cuit=30712345678 --environment=production
```

El comando no llama a ARCA ni modifica numeración, estados o CAE. Es transaccional y se niega a continuar si encuentra registros parcialmente asignados o pertenecientes a otro CUIT/ambiente. Si los datos antiguos pertenecen a más de una cuenta, no uses esta asignación global: deben clasificarse y migrarse por cuenta antes de habilitar la emisión multi-CUIT.

No guardar certificados, claves privadas ni access tokens en el repositorio o en la base de datos. Los archivos de certificado deben tener permisos restrictivos y no deben quedar dentro de un directorio público.

Después de modificar variables de entorno en una aplicación con configuración cacheada:

```bash
php artisan config:clear
php artisan config:cache
```

## Camino rápido

### 1. Crear un punto de venta

Mediante el servicio PHP:

```php
use Modules\Arca\ArcaManager;

$pointOfSale = app(ArcaManager::class)->pointOfSales()->create([
    'number' => 1,
    'name' => 'Casa central',
    'active' => true,
]);
```

O mediante HTTP:

```http
POST /api/arca/point-of-sales
Content-Type: application/json
```

```json
{
    "number": 1,
    "name": "Casa central",
    "active": true
}
```

### 2. Autorizar una factura

```php
use Modules\Arca\ArcaManager;

$result = app(ArcaManager::class)->authorize([
    'point_of_sale' => 1,
    'letter' => 'A',
    'operation' => 'invoice',
    'concept' => 'products',
    'customer' => [
        'document_type' => 'cuit',
        'document_number' => '30709999999',
        'vat_condition' => 'responsible_registered',
    ],
    'amounts' => [
        'total' => 121000,
        'taxable_net' => 100000,
        'vat' => 21000,
        'exempt' => 0,
        'non_taxed' => 0,
        'other_taxes' => 0,
    ],
    'vat_rates' => [
        [
            'rate' => 21,
            'taxable_base' => 100000,
            'amount' => 21000,
        ],
    ],
    'idempotency_key' => 'crm-order-4587',
]);

$data = $result->toArray();
```

El resultado autorizado tiene esta forma:

```php
[
    'success' => true,
    'status' => 'authorized',
    'voucher_id' => 1,
    'point_of_sale' => 1,
    'voucher_number' => 125,
    'formatted_number' => '00001-00000125',
    'currency' => 'ARS',
    'exchange_rate' => 1,
    'cae' => '74012345678901',
    'cae_expires_at' => '2026-09-18',
    'message' => null,
]
```

El `voucher_id` es el identificador interno de la aplicación. El CRM no necesita usar códigos internos de ARCA.

## API HTTP

Las rutas se cargan automáticamente con el prefijo configurado en `arca.route_prefix`.

| Método | Ruta | Uso |
| --- | --- | --- |
| `GET` | `/api/arca/point-of-sales` | Lista puntos de venta activos |
| `GET` | `/api/arca/point-of-sales/arca` | Consulta puntos de venta directamente en ARCA |
| `POST` | `/api/arca/point-of-sales/sync` | Sincroniza puntos de venta y contadores locales con ARCA |
| `POST` | `/api/arca/point-of-sales` | Crea un punto de venta |
| `POST` | `/api/arca/vouchers/authorize` | Autoriza un comprobante |

Las rutas utilizan por defecto los middlewares `web`, `auth` y `verified`. Para una integración mediante API, publicar `config/arca.php` y reemplazar `route_middleware` por los middlewares de autenticación de la aplicación.

Ejemplo de autorización HTTP:

```http
POST /api/arca/vouchers/authorize
Content-Type: application/json
```

```json
{
    "point_of_sale": 1,
    "letter": "B",
    "operation": "invoice",
    "concept": "products",
    "customer": {
        "document_type": "final_consumer",
        "document_number": 0,
        "vat_condition": "final_consumer"
    },
    "amounts": {
        "total": 1210,
        "taxable_net": 1000,
        "vat": 210,
        "exempt": 0,
        "non_taxed": 0,
        "other_taxes": 0
    },
    "vat_rates": [
        {
            "rate": 21,
            "taxable_base": 1000,
            "amount": 210
        }
    ],
    "idempotency_key": "crm-order-4587"
}
```

### Consultar puntos de venta en ARCA

El cliente SOAP directo consulta `FEParamGetPtosVenta` usando el CUIT y certificado de la instancia. El método `fromArca()` también consulta el último número de cada tipo de comprobante; para consultar únicamente puntos de venta, invoca `ArcaClient::getSalesPoints()` directamente.

```php
use Modules\Arca\ArcaManager;

$salesPoints = app(ArcaManager::class)->pointOfSales()->fromArca();
```

La respuesta usa términos del módulo y no expone códigos de ARCA:

```php
[
    [
        'number' => 1,
        'name' => 'Casa central',
        'active' => true,
        'counters' => [
            [
                'letter' => 'A',
                'operation' => 'invoice',
                'label' => 'Factura A',
                'last_number' => 125,
                'next_number' => 126,
            ],
            // Notas de crédito/débito y comprobantes B/C...
        ],
    ],
]
```

Para persistir esos puntos y actualizar las secuencias locales:

```php
$salesPoints = app(ArcaManager::class)->pointOfSales()->syncFromArca();
```

También puede ejecutarse por HTTP:

```http
POST /api/arca/point-of-sales/sync
```

La sincronización actualiza el nombre y el estado de cada punto de venta, crea las secuencias faltantes y nunca retrocede un contador local que ya haya reservado un número.

También puede ejecutarse desde la consola:

```bash
php artisan arca:sync-points-of-sale
```

Para consultar los puntos y contadores de ARCA sin crear ni actualizar puntos, secuencias ni registros de auditoría locales:

```bash
php artisan arca:sync-points-of-sale --dry-run
```

En homologación, ARCA normalmente expone sólo el punto de venta de prueba `1`; es normal que la consulta no devuelva los puntos de producción.

### Consultar tipos de comprobante en ARCA

`ArcaClient::getVoucherTypes()` consulta los tipos de comprobante habilitados en WSFE usando las credenciales y el CUIT de la instancia del cliente. Es una consulta de solo lectura y no depende de que la cuenta tenga puntos de venta configurados, por lo que también sirve para comprobar acceso autenticado a WSFE en homologación.

```php
use Modules\Arca\ArcaFactory;
use Modules\Arca\Data\ArcaCredentials;

$arca = app(ArcaFactory::class)->forCredentials(new ArcaCredentials(
    cuit: $account->cuit,
    production: false,
    certificate: $account->certificate_pem,
    privateKey: $account->private_key_pem,
));

$voucherTypes = $arca->getVoucherTypes();
```

## Datos del comprobante

### Tipo de operación

`operation` acepta:

| Valor | Significado |
| --- | --- |
| `invoice` | Factura |
| `credit_note` | Nota de crédito |
| `debit_note` | Nota de débito |

`letter` acepta `A`, `B` o `C`.

El módulo realiza el mapeo a los códigos de comprobante de ARCA:

| Comprobante | Código ARCA |
| --- | ---: |
| Factura A | 1 |
| Nota de débito A | 2 |
| Nota de crédito A | 3 |
| Factura B | 6 |
| Nota de débito B | 7 |
| Nota de crédito B | 8 |
| Factura C | 11 |
| Nota de débito C | 12 |
| Nota de crédito C | 13 |

### Concepto

`concept` acepta:

| Valor | Código ARCA |
| --- | ---: |
| `products` | 1 |
| `services` | 2 |
| `products_and_services` | 3 |

Para servicios, pueden enviarse opcionalmente `service_from`, `service_to` y `payment_due` en formato `Y-m-d`. Si no se envían, se utiliza la fecha del comprobante.

### Cliente

`customer.document_type` acepta los siguientes valores:

| Valor | Código ARCA | Descripción |
| --- | ---: | --- |
| `cuit` | 80 | CUIT |
| `cuil` | 86 | CUIL |
| `cdi` | 87 | CDI |
| `le` | 89 | Libreta de enrolamiento |
| `lc` | 90 | Libreta cívica |
| `foreign_id` | 91 | Cédula de identidad extranjera |
| `in_process` | 92 | Documento en trámite |
| `birth_certificate` | 93 | Acta de nacimiento |
| `passport` | 94 | Pasaporte |
| `ci_buenos_aires` | 95 | Cédula de identidad de Buenos Aires / RNP |
| `dni` | 96 | DNI |
| `final_consumer` / `consumer_final` | 99 | Consumidor final sin identificar (ambos valores admitidos) |

El tipo de documento y la condición fiscal son independientes: un consumidor final puede estar identificado con DNI, CUIT o CUIL. El valor `final_consumer` como **tipo de documento** representa al receptor sin identificar (código `99`), con `document_number: 0`, únicamente cuando las reglas fiscales permiten no identificarlo. No uses `0` como número de un DNI, CUIT o CUIL.

El emisor se configura con su CUIT en `ARCA_CUIT`; el web service de facturación no se identifica mediante un CUIL.

`customer.vat_condition` acepta los siguientes valores:

| Valor | Código ARCA | Descripción |
| --- | ---: | --- |
| `responsible_registered` | 1 | IVA responsable inscripto |
| `exempt` | 4 | IVA sujeto exento |
| `final_consumer` / `consumer_final` | 5 | Consumidor final (ambos valores admitidos) |
| `monotributo` | 6 | Responsable monotributo |
| `not_categorized` | 7 | Sujeto no categorizado |
| `foreign_provider` | 8 | Proveedor del exterior |
| `foreign_customer` | 9 | Cliente del exterior |
| `iva_liberated` | 10 | IVA liberado, Ley 19.640 |
| `social_monotributo` | 13 | Monotributista social |
| `not_reached` | 15 | IVA no alcanzado |
| `promoted_monotributo` | 16 | Monotributo trabajador independiente promovido |

En ambos campos se recomienda `final_consumer`; `consumer_final` sigue admitido como alias por retrocompatibilidad y produce exactamente el mismo código ARCA. No es necesario modificar las solicitudes existentes.

Ejemplo de consumidor final identificado con DNI:

```json
{
    "customer": {
        "document_type": "dni",
        "document_number": 30123456,
        "vat_condition": "final_consumer"
    }
}
```

Ejemplo de consumidor final sin identificar, cuando esté permitido:

```json
{
    "customer": {
        "document_type": "final_consumer",
        "document_number": 0,
        "vat_condition": "final_consumer"
    }
}
```

### Importes

No se envían artículos. `amounts` debe contener los importes agrupados del comprobante:

| Campo | Significado |
| --- | --- |
| `total` | Importe total |
| `taxable_net` | Neto gravado |
| `vat` | IVA total |
| `exempt` | Importe exento |
| `non_taxed` | Importe no gravado |
| `other_taxes` | Otros tributos |

Los seis valores son obligatorios, incluidos los que correspondan en cero. El módulo valida que:

```text
total = taxable_net + vat + exempt + non_taxed + other_taxes
```

`vat_rates` permite discriminar una o más alícuotas:

```php
'vat_rates' => [
    ['rate' => 21, 'taxable_base' => 100000, 'amount' => 21000],
    ['rate' => 10.5, 'taxable_base' => 50000, 'amount' => 5250],
],
```

Alícuotas admitidas:

| Porcentaje | Código ARCA |
| ---: | ---: |
| 0 | 3 |
| 2.5 | 9 |
| 5 | 8 |
| 10.5 | 4 |
| 21 | 5 |
| 27 | 6 |

### Moneda

Por defecto, el comprobante se emite en pesos:

```php
'currency' => 'ARS',
```

Para dólares:

```php
'currency' => 'USD',
'exchange_rate' => 1000,
```

Para euros:

```php
'currency' => 'EUR',
'exchange_rate' => 1200,
```

El módulo transforma:

| CRM | ARCA |
| --- | --- |
| `ARS` | `PES`, cotización `1` |
| `USD` | `DOL`, cotización obligatoria y positiva |
| `EUR` | `060`, cotización obligatoria y positiva |

Los importes se informan en la moneda del comprobante. La cotización no convierte los importes; se envía a ARCA como `MonCotiz`.

## Notas de crédito y débito

Las notas requieren el comprobante original asociado:

```php
$result = app(ArcaManager::class)->authorize([
    'point_of_sale' => 1,
    'letter' => 'A',
    'operation' => 'credit_note',
    'associated_voucher' => [
        'point_of_sale' => 1,
        'letter' => 'A',
        'number' => 125,
    ],
    // customer, amounts, vat_rates y demás campos...
]);
```

El módulo transforma la asociación a `CbtesAsoc` y utiliza el tipo de factura original correspondiente.

## Numeración

La numeración es independiente por punto de venta y tipo de comprobante. Por ejemplo, un punto de venta tiene contadores separados para:

```text
Factura A
Nota de crédito A
Nota de débito A
Factura B
Nota de crédito B
Nota de débito B
Factura C
Nota de crédito C
Nota de débito C
```

Antes de reservar un número, el módulo consulta el último comprobante autorizado por ARCA y ajusta la secuencia local. La reserva se realiza dentro de una transacción con bloqueo para evitar números duplicados en solicitudes concurrentes.

Un número pendiente no se reutiliza mientras una solicitud pueda estar en curso. Los intentos rechazados o inciertos se consultan en ARCA antes de liberar su número: si fue autorizado, se recupera el CAE; si no existe, se conserva `attempted_number` y el intento pasa a `failed`. Una consulta fallida nunca debe considerarse confirmación de ausencia.

La consulta de reconciliación ocurre fuera de la transacción de reserva. Dentro de la transacción se vuelve a comprobar el comprobante ocupado para evitar liberar un número cuyo estado cambió. Mongo serializa reservas mediante escrituras sobre el documento de secuencia y transacciones con reintentos locales acotados; las llamadas fiscales y la emisión nunca se ejecutan dentro de esos reintentos.

## Idempotencia

El CRM puede enviar `idempotency_key` para asociar la operación con un pedido, venta o transacción externa:

```php
'idempotency_key' => 'crm-order-4587',
```

Si se repite una solicitud con la misma clave, el módulo devuelve el comprobante existente y no vuelve a llamar a ARCA.

La clave debe ser estable para la operación de negocio y no debe reutilizarse para otra factura.

## Estados

| Estado | Significado |
| --- | --- |
| `pending` | Número reservado, autorización en curso o aún no procesada |
| `authorized` | ARCA asignó CAE |
| `rejected` | ARCA rechazó el comprobante |
| `failed` | Fallo interno antes o fuera de la comunicación final |
| `unknown` | No se pudo confirmar si ARCA autorizó el comprobante |

Un comprobante `unknown` no debe reintentarse ciegamente. Primero debe consultarse ARCA para determinar si el número fue autorizado.

## Registro de operaciones

Cada intercambio con ARCA se persiste en `arca_operations`, incluyendo:

- Método invocado.
- Request enviada.
- Response recibida.
- Estado.
- Correlation ID.
- Duración.
- Número de intento.
- Mensaje de error.
- Comprobante relacionado, cuando ya existe.

Las tablas principales son:

| Tabla | Uso |
| --- | --- |
| `arca_points_of_sale` | Puntos de venta disponibles |
| `arca_sequences` | Contadores por punto de venta y tipo de comprobante |
| `arca_vouchers` | Comprobantes, importes, CAE y estado |
| `arca_operations` | Auditoría técnica de llamadas a ARCA |

La respuesta completa del servicio se conserva para registrar detalles de rechazos. Las credenciales WSAA y claves privadas nunca forman parte del request registrado por este módulo.

## Public API PHP

La API principal es `Modules\Arca\ArcaManager`:

```php
use Modules\Arca\ArcaManager;

/** @var ArcaManager $arca */
$arca = app(ArcaManager::class);

$activePointOfSales = $arca->pointOfSales()->active();
$pointOfSale = $arca->pointOfSales()->create([
    'number' => 1,
    'name' => 'Casa central',
]);
$result = $arca->authorize($data);
```

También puede utilizarse la facade:

```php
use Modules\Arca\Facades\Arca;

$result = Arca::authorize($data);
```

Para reemplazar el SDK durante tests o utilizar otra implementación, enlazar `Modules\Arca\Contracts\ArcaClient` en un service provider de la aplicación.

## Testing

Los tests deben reemplazar `ArcaClient` por un fake. No deben utilizar credenciales reales ni realizar requests contra ARCA.

En el proyecto consumidor:

```bash
php artisan test --compact tests/Feature/Arca/ArcaManagerTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse modules/Arca/src --no-progress
```

Las pruebas de integración usan exclusivamente una base aislada llamada `arca_storage_test`. Para ejecutarlas en el proyecto de desarrollo, proporciona servicios de pruebas (Mongo replica set, Mongo standalone y MySQL, respectivamente):

```bash
ARCA_TEST_MONGODB_URI='mongodb://127.0.0.1:27028/?directConnection=true' \
ARCA_TEST_MONGODB_STANDALONE_URI='mongodb://127.0.0.1:27029/?directConnection=true' \
ARCA_TEST_MYSQL_PORT=33078 \
php artisan test --compact tests/Feature/Arca
```

Los tests utilizan prefijos aislados y eliminan únicamente sus tablas o colecciones. Sin esas variables, las verificaciones externas se omiten. Configura un límite de archivos abiertos suficiente para MongoDB (`nofile=65536`) y no apuntes estas variables a bases reales. Las pruebas concurrentes usan procesos PHP separados, sin llamadas reales a ARCA.

## Fuera de alcance actual

- Emisión de comprobantes en lote.
- Múltiples empresas emisoras.
- PDF o representación visual del comprobante.
- Consulta y reconciliación automática de comprobantes `unknown`.
- CAEA y facturación de contingencia.
- Pantallas frontend administrativas.
