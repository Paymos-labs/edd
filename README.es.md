# Paymos para Easy Digital Downloads

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Pasarela oficial de Paymos para Easy Digital Downloads. El comprador la selecciona al finalizar la compra, paga una factura en stablecoins en la página alojada de Paymos y vuelve a un pedido pendiente. El pedido pasa a `complete` —el estado en el que EDD libera los enlaces de descarga y envía el recibo de compra— cuando un webhook firmado informa de que la transferencia se ha confirmado en cadena. Volver desde la página de pago no es lo que desbloquea el archivo.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Recorrido en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- WordPress 6.5 o superior, probado hasta 7.0 — el mínimo declarado en la cabecera del plugin `paymos-easy-digital-downloads.php`
- Easy Digital Downloads, declarado en esa misma cabecera como plugin obligatorio. El modelo de pedidos en el que escribe esta pasarela corresponde a EDD 3.x
- PHP 7.4 o superior con OpenSSL — las credenciales se sellan con AES-256-GCM
- Un sitio HTTPS accesible públicamente. El webhook es una ruta REST en su propio dominio y las entregas nunca se envían a direcciones loopback, privadas ni link-local
- Una cuenta de Paymos, con el panel ya situado en el proyecto a través del cual vende esta tienda

## Instalación

Descargue `paymos-edd-<version>.zip` desde [Releases](https://github.com/Paymos-labs/edd/releases/latest) o obtenga el paquete en el panel **CMS integration** del panel de Paymos. Instálelo desde **Plugins → Add New Plugin → Upload Plugin** —en versiones anteriores de WordPress esa pantalla se denomina **Add New**— y actívelo.

El SDK de PHP de Paymos viene incluido en el archivo ZIP, por lo que no hace falta ningún paso de Composer. Todos los comercios descargan el mismo archivo, que no contiene ninguna clave de API, secreto de API, project id, secreto de webhook, token OAuth ni device code.

## Conexión

Para conectar la tienda se necesita la capacidad `manage_shop_settings` de EDD (gestor de tienda o administrador).

1. Sitúe el panel de Paymos en el proyecto a través del cual vende esta tienda: ese será el proyecto vinculado y no habrá nada que elegir más adelante.
2. Vaya a **Downloads → Settings → Payments → Paymos** y pulse **Connect Paymos**.
3. Apruebe la URL del sitio y el proyecto en la pestaña que se abre. Si el navegador bloquea la pestaña, el enlace y el código de usuario aparecerán junto al botón.
4. **Connection status** se completará automáticamente: modo activo, si Sandbox y Live están configurados, la clave de API enmascarada y el ID del proyecto.

Esa única aprobación entrega Sandbox y Live a la vez. Se reutiliza su única clave activa de Payment —o se crea si no existe— y se registra un webhook de tipo Invoice en:

```text
https://your-store.example/wp-json/paymos-edd/v1/webhook
```

Un webhook existente solo se reutiliza si la URL de callback, la categoría y el proyecto coinciden exactamente.

Las credenciales se almacenan en una opción de WordPress sin carga automática (`non-autoloaded`), selladas con AES-256-GCM mediante una clave derivada de las sales `auth` y `secure_auth` del sitio. El token de autorización de corta duración se descarta y todas las llamadas posteriores a la Merchant API se firman con HMAC.

## Ajustes

| Ajuste | Valor predeterminado |
|---|---|
| Etiqueta en el checkout (Checkout label) | `Pay with stablecoins` |
| Modo (Mode) | Sandbox |
| Webhook URL | Solo lectura; se registra automáticamente al conectar |
| Estado de conexión (Connection status) | Solo lectura |
| Registro de depuración (Debug logging) | Desactivado por defecto; cuando se activa, escribe mensajes de Paymos en el registro de depuración de EDD |

No hay campo para introducir un secreto ni selector de proyecto; ambos se aprovisionan mediante la aprobación.

## Cuándo se desbloquea la descarga

| Evento de Paymos | Pedido de EDD |
|---|---|
| El comprador elige Paymos, se registra el pedido y se vacía el carrito | `pending`, con una nota que incluye el ID de factura |
| `invoice.confirming` | `pending` — `"Paymos payment is confirming."` |
| `invoice.underpaid_waiting` | `pending`, y la nota indica el importe pendiente y su token |
| `invoice.awaiting_payment` | `pending`, y la nota señala que una transferencia confirmada se revirtió en cadena |
| `invoice.paid`, `invoice.paid_over` | `complete` — descargas liberadas y recibo enviado |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `abandoned`, el estado de EDD para un pedido pendiente que nunca se completó |

Al completarse el pedido, se guarda el hash de la transacción en cadena como ID de transacción de EDD, además de los metadatos `_paymos_tx_hash` y `_paymos_explorer_url`. Un segundo evento terminal sobre un pedido ya completado se reconoce como una repetición y no añade ninguna nota duplicada, mientras que un evento posterior `confirming`, `underpaid`, `expired` o `cancelled` se registra como descartado y no modifica nada.

Un pedido cuyo importe o divisa haya divergido respecto a la factura no se completa en ningún caso. Se establece la clave de metadatos `_paymos_amount_mismatch` en `yes`, la discrepancia queda anotada en las notas del pedido y un responsable debe resolverla manualmente. Mientras tanto, la descarga permanece bloqueada.

## Qué ve el comprador después

Tanto el recibo de compra como el correo de confirmación del pedido añaden una línea **Payment confirmation**: el enlace al explorador si el payload incluía uno, o el hash de transacción en texto plano en caso contrario. No se muestra nada mientras no exista un hash, que es el caso de todos los pedidos de Sandbox.

## Pruebas antes de pasar a Live

1. Deje **Mode** en Sandbox y compre una descarga desde la tienda.
2. Localice esa factura en el panel de Paymos manteniendo el panel en Sandbox y simule el flujo con **Pay Full**, **Pay 50%**, **Pay 150%** o **Cancel**. Son eventos reales del ciclo de vida, sin movimiento de fondos.
3. Revise el pedido: su estado, sus notas y si se libera el enlace de descarga.
4. Cambie **Mode** a Live. La aprobación inicial ya entregó ambos conjuntos de credenciales, así que no es necesaria una segunda configuración.

## Webhooks

La ruta está registrada en la REST API de WordPress y solo acepta peticiones `POST`. `X-Webhook-Signature` se verifica frente a los secretos de ambos entornos a la vez y, a continuación, la marca `is_test` del propio payload se contrasta con el entorno que validó la firma: un payload de Live no puede cerrar un pedido de Sandbox y lo contrario se rechaza con el mismo rigor. Cualquier `event_id` procesado previamente se confirma y se omite. Los eventos terminales se consultan de nuevo en la Merchant API y se comparan con el proyecto, importe y divisa guardados en el pedido antes de escribir ningún cambio.

Los fallos devuelven un código de estado sobre el que el emisor puede actuar, porque una respuesta distinta de 2xx mantiene el evento en la escalera de reintentos: `401` ante firma inválida o marca temporal caducada, `400` si falla el procesamiento y `500` cuando no hay ningún secreto de webhook guardado. La escalera consta de 11 intentos repartidos en unas 16 horas. Las facturas impagadas dependen de los webhooks firmados `invoice.paid` y no cuentan con un cron local de conciliación de facturas, por lo que un evento que supere todos los reintentos se reenvía manualmente desde el panel de Paymos. Los reembolsos pendientes sí se consultan localmente cada minuto mediante la tarea WP-Cron `paymos_edd_refunds_reconcile` hasta alcanzar un estado terminal.

## Reembolsos

La pasarela admite reembolsos nativos en cadena a través de la Paymos Merchant API (`/v1/refunds`) en dos modos:

- `full` — reembolsa el importe íntegro pagado de la factura;
- `overpayment` — reembolsa únicamente el excedente recibido en un pedido `invoice.paid_over` manteniendo el pedido en estado `complete`.

Los fondos se devuelven exclusivamente a la dirección de cartera verificada `first_sender` que Paymos registró cuando el comprador pagó la factura. No se introduce ninguna dirección de destino manualmente y no se admiten reembolsos parciales por importes arbitrarios.

En un pedido completado de Paymos dentro de **Downloads → Orders**, puede iniciar un reembolso `full` desde el cuadro nativo **Initiate a Refund** de EDD (con todos los artículos del pedido seleccionados al completo) o gestionar tanto reembolsos `full` como `overpayment` y consultar su estado desde el panel **Paymos refunds** en la ficha del pedido. EDD registra el pedido de reembolso local y envía el recibo correspondiente solo después de que Paymos confirme `completed` mediante `/wp-json/paymos-edd/v1/webhook` o la tarea WP-Cron de 1 minuto `paymos_edd_refunds_reconcile`. En modo Sandbox es posible simular resultados terminales de reembolso (`completed`, `failed`, `cancelled`) sin mover fondos.

## Solución de problemas

**El comprador pagó, pero el archivo sigue bloqueado.** El pedido está esperando un webhook que aún no ha llegado. Compruebe que el endpoint responde desde fuera del sitio: un plugin de seguridad, la autenticación HTTP básica o una opción para desactivar la REST API lo bloquean en silencio, y las entregas no siguen redirecciones hacia otra dirección.

**El endpoint responde `not_configured`.** No hay credenciales almacenadas, por lo que no existe ningún secreto con el que verificar la firma. Pulse **Connect Paymos** en la página de ajustes y complete la aprobación.

**Todo deja de funcionar tras editar `wp-config.php`.** El sobre de credenciales está cifrado a partir de las sales `auth` y `secure_auth`. Rotarlas o restaurar la base de datos en un sitio con sales distintas impide descifrarlo; vuelva a conectar el plugin para generar un sobre nuevo.

**Nada funciona tras migrar el sitio a un dominio nuevo.** La URL aprobada del sitio y el webhook registrado siguen apuntando al host anterior. Vuelva a conectar la tienda desde el nuevo dominio.

## Enlaces

- Documentación: [paymos.io/es/docs/cms-easy-digital-downloads](https://paymos.io/es/docs/cms-easy-digital-downloads)
- Código fuente y versiones: [Paymos-labs/edd](https://github.com/Paymos-labs/edd)
- Soporte: [support@paymos.io](mailto:support@paymos.io)
