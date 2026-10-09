# Laravel Arca

Facturación electrónica para aplicaciones Laravel mediante [AfipSDK](https://afipsdk.com/), actualmente integrado con ARCA.

El paquete mantiene al CRM separado de los códigos y estructuras de ARCA. El CRM trabaja con términos de negocio como `Factura A`, `Nota de crédito B`, `USD` y `punto de venta`; el módulo realiza el mapeo, administra la numeración, autoriza el comprobante y conserva el intercambio técnico completo.

El módulo no envía ni administra artículos individuales. La autorización recibe importes agrupados y la discriminación de IVA requerida por ARCA.

## Requisitos

- PHP 8.3+
- Laravel 13+
- Extensión PHP SOAP
- Extensión PHP OpenSSL
- Una cuenta y credenciales de AfipSDK
- Certificado y clave privada para producción, según la configuración de ARCA/AfipSDK

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
    'cuit' => env('ARCA_CUIT'),
    'access_token' => env('ARCA_ACCESS_TOKEN'),
    'certificate_path' => env('ARCA_CERTIFICATE_PATH'),
    'private_key_path' => env('ARCA_PRIVATE_KEY_PATH'),
    'production' => (bool) env('ARCA_PRODUCTION', false),
    'table_prefix' => env('ARCA_TABLE_PREFIX', 'arca_'),
    'route_prefix' => env('ARCA_ROUTE_PREFIX', 'api/arca'),
    'route_middleware' => ['web', 'auth', 'verified'],
];
```

Variables de entorno:

```ini
ARCA_CUIT=20111111112
ARCA_ACCESS_TOKEN=your-afipsdk-access-token
ARCA_CERTIFICATE_PATH=/secure/path/certificate.crt
ARCA_PRIVATE_KEY_PATH=/secure/path/private.key
ARCA_PRODUCTION=false
ARCA_TABLE_PREFIX=arca_
ARCA_ROUTE_PREFIX=api/arca
```

`ARCA_PRODUCTION=false` utiliza el ambiente de homologación. Cambiarlo a `true` solamente cuando el certificado, la clave, el CUIT y el access token correspondan a producción.

### Varias cuentas y CUIT

La aplicación consumidora administra las cuentas y sus secretos. `laravel-arca` no guarda API keys, certificados ni claves privadas. Para operar varias cuentas en el mismo proceso, crea un `ArcaManager` independiente por cuenta mediante `ArcaFactory`; el alcance de los datos queda aislado por CUIT y ambiente (`testing` u `production`). El CUIT y el ambiente se toman de las credenciales, nunca de los datos del comprobante.

```php
use Modules\Arca\ArcaFactory;
use Modules\Arca\Data\ArcaCredentials;

$arca = app(ArcaFactory::class)->forCredentials(new ArcaCredentials(
    cuit: $account->cuit,
    accessToken: $account->afipsdk_api_key,
    production: true,
    certificate: $account->certificate_pem,
    privateKey: $account->private_key_pem,
));

$arca->pointOfSales()->syncFromArca();
$result = $arca->authorize($voucherData);
```

`certificate` y `privateKey` reciben el contenido PEM directamente y deben enviarse juntos. Para usar un `ArcaClient` propio o un fake, se puede construir el manager con `ArcaFactory::forClient($client, $cuit, 'testing')`. No compartas una instancia de manager entre cuentas: crea una por cada par CUIT/ambiente. La fachada y las rutas existentes continúan usando la cuenta predeterminada de `config/arca.php`; en una aplicación multiusuario, la aplicación debe resolver la cuenta autorizada para cada usuario y llamar a la fábrica con sus credenciales.

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
        "document_type": "consumer_final",
        "document_number": 0,
        "vat_condition": "consumer_final"
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

AfipSDK expone `ElectronicBilling->GetSalesPoints()`. El módulo consulta ese servicio usando el CUIT configurado en `ARCA_CUIT` y obtiene también el último número de cada tipo de comprobante.

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
| `consumer_final` | 99 | Consumidor final |

Para consumidor final, el número de documento puede ser `0`. El emisor se configura con su CUIT en `ARCA_CUIT`; el web service de facturación no se identifica mediante un CUIL.

`customer.vat_condition` acepta los siguientes valores:

| Valor | Código ARCA | Descripción |
| --- | ---: | --- |
| `responsible_registered` | 1 | IVA responsable inscripto |
| `exempt` | 4 | IVA sujeto exento |
| `consumer_final` | 5 | Consumidor final |
| `monotributo` | 6 | Responsable monotributo |
| `not_categorized` | 7 | Sujeto no categorizado |
| `foreign_provider` | 8 | Proveedor del exterior |
| `foreign_customer` | 9 | Cliente del exterior |
| `iva_liberated` | 10 | IVA liberado, Ley 19.640 |
| `social_monotributo` | 13 | Monotributista social |
| `not_reached` | 15 | IVA no alcanzado |
| `promoted_monotributo` | 16 | Monotributo trabajador independiente promovido |

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

Un número reservado no se reutiliza si una solicitud posterior falla. Esto conserva la trazabilidad y evita volver a enviar un número que pudo haber sido aceptado por ARCA durante un timeout.

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

El SDK se invoca con la respuesta completa para conservar también los detalles de rechazos. Las credenciales y claves privadas nunca forman parte del request registrado por este módulo.

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

## Fuera de alcance actual

- Emisión de comprobantes en lote.
- Múltiples empresas emisoras.
- PDF o representación visual del comprobante.
- Consulta y reconciliación automática de comprobantes `unknown`.
- CAEA y facturación de contingencia.
- Pantallas frontend administrativas.
