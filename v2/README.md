# v2 — transmisión propia

Carpeta separada **a propósito**: nada de acá toca `livepro-connector/` ni `backoffice/`.
Lo que hoy está en producción sigue funcionando igual mientras esto se construye al lado.

| Carpeta | Qué es | Estado |
|---|---|---|
| `player/` | Player WHEP contra Cloudflare Stream, reemplaza al iframe de YouTube | 🚧 primera versión |
| `estudio/` | Estudio web: publica por WHIP desde el navegador | ⬜ pendiente |
| `db/` | Dialecto Postgres para migrar la base a Supabase | ⬜ pendiente |

Plan y decisiones: [PLAN-TRANSMISION.md](../../PLAN-TRANSMISION.md).

## Player

`player/livepro-player-whep.js` expone **la misma superficie** que el widget ya usaba
contra la IFrame API de YouTube — `mute()`, `unMute()`, `playVideo()`, `pauseVideo()` y
avisos de cambio de estado con los mismos códigos numéricos. La idea es que el widget
elija backend por tienda sin reescribir su lógica de reproducción.

Resuelve además tres cosas que el iframe daba gratis y WebRTC no:

- **Reconexión con backoff exponencial** ante `failed` / `disconnected`, con techo de 15s
  para no martillar el endpoint cuando el vivo cortó de verdad.
- **Cierre de sesión** con `DELETE` contra el `Location` que devuelve el POST inicial.
- **Autoplay en iOS**: arranca en mute, porque Safari bloquea el sonido sin gesto previo.

Todavía **no está integrado al widget**: es un módulo autónomo, sin probar contra un
endpoint real. Falta la cuenta de Cloudflare Stream.
