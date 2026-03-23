# LivePro MVP - Backoffice + Woo Plugin

Implementación actual del MVP:

- Backoffice PHP con autenticación (`registro/login/logout`).
- Módulos operativos: `Conexión Tienda`, `Conexión Live (YouTube)`, `Inventario`.
- Widget flotante para storefront con formulario de datos.
- Creación de pedido WooCommerce en estado `pending` o `on-hold`.
- Polling de estado live cada 5 segundos.

## Estructura

- `/Users/jonathantierno/Desktop/live-shopping/backoffice`
  - Backoffice + API en PHP (MySQL por `.env`, SQLite fallback local).
- `/Users/jonathantierno/Desktop/live-shopping/plugin/livepro-connector`
  - Plugin instalable de WordPress/WooCommerce.

## Endpoints implementados

### Backoffice

1. `POST /api/v1/plugin/connect`
2. `POST /api/v1/live/public/:storeId`
3. `POST /api/v1/intent/create-pending-order`

### Woo plugin (WP REST)

1. `POST /wp-json/livepro/v1/order-pending`

## Pantallas backoffice

1. `/register`
2. `/login`
3. `/app/store-connection`
4. `/app/live-connection`
5. `/app/inventory`

## Configuración MySQL (Hostinger)

1. Copiar `/Users/jonathantierno/Desktop/live-shopping/backoffice/.env.example` a `/Users/jonathantierno/Desktop/live-shopping/backoffice/.env`.
2. Editar `.env` con tus datos reales:

```env
APP_ENV=production
APP_URL=https://livepro.kreivadigital.com

DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u129733262_livepro
DB_USER=u129733262_livepro
DB_PASS=TU_PASSWORD_REAL
DB_CHARSET=utf8mb4
```

3. Opción recomendada: apuntar dominio/subdominio a `backoffice/public`.
4. Opción alternativa (sin cambiar document root): usar `backoffice/index.php` en la raíz de `backoffice`.
5. En ambos casos, la fuente de verdad de estilos es `backoffice/public/assets/app.css`.
6. Abrir `https://livepro.kreivadigital.com/register` para crear el primer usuario.

Notas:
- Las tablas se crean automáticamente al primer request (migración simple embebida).
- Si quieres usar SQLite localmente, cambia `DB_DRIVER=sqlite`.

## Plugin WooCommerce

1. Comprimir carpeta `plugin/livepro-connector` en zip.
2. Instalar plugin en WordPress.
3. Activar `LivePro Connector`.
4. En `LivePro` de wp-admin, cargar:
   - Backoffice URL (`https://livepro.kreivadigital.com`)
   - API Key
   - API Secret
   - Estado de pedido
5. Guardar y ejecutar `Conectar y validar`.

## Actualización reciente (v0.1.14)

Resumen de cambios aplicados en esta etapa:

1. Widget flotante mejorado (frontend tienda):
   - Estado minimizado rediseñado tipo preview (imagen, badge `VIVO`, viewers y CTA `VER AHORA`).
   - Si no se configura imagen de preview, usa fallback automático con thumbnail de YouTube.
   - El video no se reinicia en cada polling si el `youtube_video_id` no cambió.
   - En mobile, al expandir, ocupa pantalla completa.

2. Header del widget expandido:
   - Overlay superior absoluto sobre el video.
   - Badge `VIVO` + contador de viewers.
   - Botones redondos para mute/cerrar con estilo blur.
   - Ajustes finos de spacing según referencia (`top: 0`, `gap: 0`, `margin-top: 6px`, etc.).

3. Card de producto destacado:
   - Estilo visual ajustado al diseño (card clara, tag `DESTACADO`, pill de stock, CTA integrado).
   - Botón `COMPRAR AHORA` movido dentro de `livepro-product-card`.
   - Animación de fade al cambiar producto activo.
   - Stock dinámico en pill (`N disponibles`) cuando el dato existe.

4. Íconos:
   - Reemplazo de caracteres Unicode/emoji por SVG inline en CTA para evitar fallos de render en WordPress (`s.w.org`).

5. Configuración en wp-admin (`LivePro`):
   - Campo de URL de preview para widget minimizado.
   - Selector de imagen desde Media Library para preview.
   - Campo opcional de viewers fallback.

6. Versionado:
   - Se estableció versionado incremental en cada entrega del plugin.
   - Versión actual documentada: `0.1.14`.
   - ZIP de entrega: `plugin/livepro-connector.zip`.

## Flujo básico

1. En backoffice, guardar `Conexión Tienda`.
2. En `Conexión Live`, cargar URL de YouTube e iniciar live.
3. En `Inventario`, usar `Sincronizar todos`.
   - La sincronización corre en tandas/batches desde backend para evitar timeouts.
4. Abrir tienda frontend y probar widget + pedido pendiente.

## Seguridad implementada

- Firma HMAC en endpoint Woo `order-pending`.
- Validación de timestamp (5 minutos).
- Password hash con `password_hash`.
- Archivo `.env` ignorado por git.

## Limitaciones actuales

- Inventario sincroniza productos simples desde `/wc/v3/products` (sin detalle avanzado de variaciones).
- Sin WebSockets (solo polling).
- Sin cobro online (solo pedido pendiente).
