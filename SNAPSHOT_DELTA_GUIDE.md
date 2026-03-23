# Snapshot + Delta Guide

## Qué es

`snapshot + delta` es una forma de sincronizar estado entre backend y frontend sin tener que reenviar todo el estado completo cada vez.

La idea es separar la sincronización en dos partes:

- `snapshot`
  Una foto completa del estado actual.
- `delta`
  Solo los cambios nuevos desde un punto conocido.

En este proyecto lo usamos para el estado público del live shopping.

## Por qué lo usamos aquí

Antes el widget trabajaba con un solo producto actual.

Eso servía para mostrar un producto en vivo, pero no servía bien para:

- mantener historial de productos emitidos
- permitir navegación entre productos ya mostrados
- evitar mezclar productos de un live viejo con uno nuevo
- evitar reenviar todo el array completo en cada polling

Con `snapshot + delta` resolvimos eso así:

- cuando el widget entra, pide un `snapshot`
- después hace polling de `delta`
- si cambia la sesión del live, el frontend se resetea y vuelve a pedir `snapshot`

## Cuándo conviene usar este patrón

Conviene cuando:

- el frontend necesita una lista o estado acumulado
- hay polling frecuente
- el estado puede crecer con el tiempo
- pueden entrar usuarios nuevos a mitad del proceso
- necesitas distinguir entre una sesión actual y otra nueva

No hace falta cuando:

- el estado es mínimo y siempre reemplazable
- no hay historial
- reenviar todo siempre no tiene costo real
- la UI no necesita continuidad entre actualizaciones

## La idea simple

Piensa el flujo así:

1. El usuario entra al widget.
2. El frontend pide la foto completa del live actual.
3. Guarda localmente esa lista.
4. Luego pregunta cada cierto tiempo si hubo cambios.
5. El backend responde solo con lo nuevo.

Eso evita reconstruir todo desde cero en cada request.

## Qué agregamos en backend

Para soportar esto agregamos dos conceptos clave:

### 1. `public_session_key`

Identifica una sesión pública del live.

Sirve para saber si el frontend sigue en el mismo live o si empezó otro.

Ejemplos:

- live de hoy: `live_abcd1234`
- live de mañana: `live_efgh5678`

Si cambia esa clave:

- el frontend sabe que no es continuidad
- limpia su estado local
- vuelve a pedir `snapshot`

### 2. `session_revision`

Es un contador incremental de cambios dentro de una sesión.

Cada vez que pasa algo relevante en el live:

- sube la revisión
- los eventos nuevos se guardan con esa revisión

Así el frontend puede decir:

`dame cambios desde la revisión 8`

y el backend solo devuelve lo posterior.

## Qué datos usamos en esta implementación

En `live_sessions`:

- `public_session_key`
- `session_revision`
- `active_product_id`
- `active_product_name`
- `active_price`
- `active_image`

En `live_emission_queue`:

- `session_key`
- `event_revision`
- `product_id`
- `variation_id`
- `product_name`
- `price`
- `image_url`

Eso nos permite saber:

- a qué live pertenece un evento
- en qué orden ocurrió
- cuál es el producto activo actual
- cómo reconstruir una lista pública consolidada

## Qué devuelve el snapshot

Ejemplo simplificado:

```json
{
  "is_live": true,
  "live_session_id": 12,
  "live_session_key": "live_ab12cd34",
  "revision": 9,
  "youtube_video_id": "abc123xyz",
  "active_item_key": "45:0",
  "product": {
    "key": "45:0",
    "product_id": 45,
    "variation_id": null,
    "name": "Tónico Exfoliante",
    "price": "2890",
    "image": "https://...",
    "times_emitted": 1,
    "last_revision": 9
  },
  "items": [
    {
      "key": "18:0",
      "product_id": 18,
      "name": "Prod 18",
      "times_emitted": 2,
      "last_revision": 6
    },
    {
      "key": "45:0",
      "product_id": 45,
      "name": "Tónico Exfoliante",
      "times_emitted": 1,
      "last_revision": 9
    }
  ],
  "poll_interval_ms": 5000
}
```

Ese payload le da al frontend todo lo necesario para arrancar correctamente.

## Qué devuelve el delta

Ejemplo simplificado:

```json
{
  "is_live": true,
  "live_session_id": 12,
  "live_session_key": "live_ab12cd34",
  "revision": 10,
  "active_item_key": "52:0",
  "events": [
    {
      "key": "52:0",
      "product_id": 52,
      "name": "Nuevo Producto",
      "times_emitted": 1,
      "last_revision": 10
    }
  ],
  "reset": false,
  "poll_interval_ms": 5000
}
```

Si el frontend está desfasado o cambió el live:

```json
{
  "reset": true
}
```

Y ahí el widget vuelve a pedir `snapshot`.

## Cómo está implementado en nuestro backend

### Rutas públicas

En el backoffice agregamos dos endpoints:

- `POST /api/v1/live/public/{storeId}/snapshot`
- `POST /api/v1/live/public/{storeId}/delta`

Referencia:

- `backoffice/src/App.php`

Ejemplo real:

```php
if ($method === 'POST' && preg_match('#^/api/v1/live/public/(\d+)/snapshot$#', $path, $matches)) {
    $this->livePublicSnapshot((int) $matches[1]);
    return;
}

if ($method === 'POST' && preg_match('#^/api/v1/live/public/(\d+)/delta$#', $path, $matches)) {
    $this->livePublicDelta((int) $matches[1]);
    return;
}
```

### Snapshot real

La función principal del snapshot es:

```php
private function livePublicSnapshot(int $storeId): void
{
    $store = $this->findStoreById($storeId);
    if (!$store) {
        jsonResponse(['error' => 'Store not found'], 404);
        return;
    }

    jsonResponse($this->buildLivePublicSnapshotPayload($storeId));
}
```

### Delta real

El delta recibe dos datos del cliente:

- `live_session_key`
- `since_revision`

Y si ve que cambió la sesión o que el cliente está desalineado, responde `reset: true`.

Ejemplo real:

```php
$body = parseJsonBody();
$clientSessionKey = trim((string) ($body['live_session_key'] ?? ''));
$sinceRevision = max(0, (int) ($body['since_revision'] ?? 0));
$snapshot = $this->buildLivePublicSnapshotPayload($storeId);

if ($clientSessionKey === '' || $clientSessionKey !== $currentSessionKey || $sinceRevision > $currentRevision) {
    jsonResponse([
        'reset' => true,
    ]);
    return;
}
```

### Cómo armamos la lista pública

No usamos la cola cruda tal cual para el widget.

La convertimos en una lista consolidada:

- agrupamos por `product_id + variation_id`
- contamos cuántas veces salió un producto
- nos quedamos con la última revisión
- ordenamos por última emisión

Eso pasa en:

- `buildPublicLiveItems()`
- `mergeActivePublicProductIntoItems()`

Ejemplo real:

```php
$items = array_values($itemsByKey);
usort($items, static function (array $left, array $right): int {
    return ((int) ($left['last_revision'] ?? 0)) <=> ((int) ($right['last_revision'] ?? 0));
});
```

## Cómo está implementado en el widget

### Flujo del polling

Primero pide snapshot. Después usa delta.

Ejemplo real:

```js
if (!state.liveSessionKey) {
  const snapshot = await fetchLiveSnapshot()
  applyLiveSnapshot(snapshot)
} else {
  const delta = await fetchLiveDelta()
  if (delta && delta.reset) {
    const snapshot = await fetchLiveSnapshot()
    applyLiveSnapshot(snapshot)
  } else {
    applyLiveDelta(delta)
  }
}
```

### Snapshot en frontend

El snapshot:

- carga `liveItems`
- guarda la revisión
- guarda la sesión actual

Ejemplo real:

```js
state.live = normalized.live
state.liveItems = normalized.items
state.liveRevision = normalized.revision
state.liveSessionKey = normalized.sessionKey
```

### Delta en frontend

El delta:

- actualiza revisión
- mergea cambios
- mantiene el estado local

Ejemplo real:

```js
state.liveRevision = normalized.revision
state.liveSessionKey = normalized.sessionKey
state.liveItems = mergeLiveItems(state.liveItems, normalized.items)
```

## Qué ventaja concreta nos dio en este proyecto

Nos permitió:

- pasar de “un solo producto live” a “lista navegable”
- soportar usuarios que entran tarde al live
- no reenviar toda la lista en cada polling
- resetear correctamente cuando empieza otro live
- deduplicar productos en la vista del dock
- mantener historial en memoria del frontend

## Comparación rápida con “enviar siempre el array completo”

### Enviar siempre todo

Pros:

- más simple de entender
- fácil para casos pequeños

Contras:

- más peso en cada request
- menos eficiente con polling
- crece peor a medida que avanza el live
- mezcla bootstrap con actualización continua

### Snapshot + delta

Pros:

- arranque limpio con snapshot
- updates livianos con delta
- mejor soporte para historial
- mejor soporte para múltiples usuarios
- más control sobre reinicios de sesión

Contras:

- un poco más de lógica
- hay que manejar sesión y revisión

## Regla práctica

Usa `snapshot + delta` cuando:

- el estado vive un tiempo
- hay una lista o historial
- el cliente puede entrar tarde
- la continuidad entre actualizaciones importa

No hace falta si:

- solo muestras un valor actual y reemplazable
- el payload es mínimo
- no hay historial ni incrementalidad

## Dónde mirar en el código

Backend:

- `backoffice/src/App.php`

Funciones clave:

- `livePublicSnapshot()`
- `livePublicDelta()`
- `buildLivePublicSnapshotPayload()`
- `buildPublicLiveItems()`

Frontend:

- `livepro-connector/assets/widget/widget.js`

Funciones clave:

- `pollLive()`
- `fetchLiveSnapshot()`
- `fetchLiveDelta()`
- `applyLiveSnapshot()`
- `applyLiveDelta()`
- `mergeLiveItems()`

## Resumen corto

`snapshot + delta` es:

- una foto completa al inicio
- cambios pequeños después

En este proyecto lo usamos porque el live ya no es “un solo producto”, sino un estado con historial, navegación y sesión propia.
