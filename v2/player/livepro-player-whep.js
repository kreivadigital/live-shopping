/**
 * LivePro — player WHEP (Cloudflare Stream).
 *
 * Reemplaza al iframe de YouTube manteniendo la misma superficie que ya usa el widget:
 * mute/unMute, playVideo/pauseVideo y un aviso de cambio de estado. Así el widget puede
 * elegir backend por tienda sin reescribir su lógica.
 *
 * No toca livepro-connector/. Ver v2/README.md.
 */
(function (global) {
  'use strict'

  // Mismos códigos que la IFrame API de YouTube, para que el widget no tenga que
  // distinguir de qué player vino el estado.
  const STATE = { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3 }

  const RECONNECT_BASE_MS = 1000
  const RECONNECT_MAX_MS = 15000

  function createWhepPlayer(options) {
    const cfg = Object.assign({
      endpoint: '',        // https://customer-<CODE>.cloudflarestream.com/<UID>/webRTC/play
      container: null,
      muted: true,
      autoplay: true,
      onStateChange: null,
      onError: null,
    }, options || {})

    let pc = null
    let video = null
    let resourceUrl = null   // el Location que devuelve el POST, para cerrar la sesión
    let reconnectTimer = null
    let reconnectAttempt = 0
    let destroyed = false
    let lastState = STATE.UNSTARTED

    function emitState(next) {
      if (next === lastState) {
        return
      }
      lastState = next
      if (typeof cfg.onStateChange === 'function') {
        cfg.onStateChange(next)
      }
    }

    function emitError(error) {
      if (typeof cfg.onError === 'function') {
        cfg.onError(error)
      }
    }

    function buildVideo() {
      const el = document.createElement('video')
      el.className = 'livepro-shell__video'
      el.playsInline = true
      el.autoplay = cfg.autoplay
      el.muted = cfg.muted
      el.controls = false
      // iOS bloquea autoplay con sonido: se arranca en mute y se levanta con un gesto.
      el.setAttribute('playsinline', '')
      el.addEventListener('playing', () => emitState(STATE.PLAYING))
      el.addEventListener('pause', () => emitState(STATE.PAUSED))
      el.addEventListener('waiting', () => emitState(STATE.BUFFERING))
      el.addEventListener('ended', () => emitState(STATE.ENDED))
      return el
    }

    async function negotiate() {
      pc = new RTCPeerConnection({
        bundlePolicy: 'max-bundle',
        // Cloudflare publica sus propios servidores ICE; no hace falta TURN nuestro.
        iceServers: [{ urls: 'stun:stun.cloudflare.com:3478' }],
      })

      pc.addTransceiver('video', { direction: 'recvonly' })
      pc.addTransceiver('audio', { direction: 'recvonly' })

      const stream = new MediaStream()
      pc.ontrack = (event) => {
        stream.addTrack(event.track)
        if (video.srcObject !== stream) {
          video.srcObject = stream
        }
      }

      pc.oniceconnectionstatechange = () => {
        if (!pc) {
          return
        }
        if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
          scheduleReconnect()
        }
      }

      const offer = await pc.createOffer()
      await pc.setLocalDescription(offer)

      const response = await fetch(cfg.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/sdp' },
        body: offer.sdp,
      })

      if (!response.ok) {
        throw new Error('WHEP respondió ' + response.status)
      }

      // El Location identifica la sesión: hace falta para cerrarla con DELETE.
      const location = response.headers.get('Location')
      if (location) {
        resourceUrl = new URL(location, cfg.endpoint).toString()
      }

      const answer = await response.text()
      await pc.setRemoteDescription({ type: 'answer', sdp: answer })
      reconnectAttempt = 0
    }

    function scheduleReconnect() {
      if (destroyed || reconnectTimer) {
        return
      }
      // Backoff: si el vivo cortó de verdad, no tiene sentido martillar el endpoint.
      const delay = Math.min(RECONNECT_BASE_MS * Math.pow(2, reconnectAttempt), RECONNECT_MAX_MS)
      reconnectAttempt += 1
      emitState(STATE.BUFFERING)
      reconnectTimer = global.setTimeout(async () => {
        reconnectTimer = null
        teardownConnection()
        try {
          await negotiate()
        } catch (error) {
          emitError(error)
          scheduleReconnect()
        }
      }, delay)
    }

    function teardownConnection() {
      if (pc) {
        pc.ontrack = null
        pc.oniceconnectionstatechange = null
        try { pc.close() } catch (_) {}
        pc = null
      }
      if (resourceUrl) {
        // Best effort: si no llega, Cloudflare la caduca sola.
        try { fetch(resourceUrl, { method: 'DELETE', keepalive: true }) } catch (_) {}
        resourceUrl = null
      }
    }

    return {
      STATE,

      async mount(container) {
        const target = container || cfg.container
        if (!target) {
          throw new Error('LiveProPlayer: falta el contenedor')
        }
        video = buildVideo()
        target.appendChild(video)
        try {
          await negotiate()
        } catch (error) {
          emitError(error)
          scheduleReconnect()
        }
        return video
      },

      // Misma superficie que sendPlayerCommand() usaba contra la IFrame API.
      mute() { if (video) { video.muted = true } },
      unMute() { if (video) { video.muted = false } },
      playVideo() { if (video) { video.play().catch(emitError) } },
      pauseVideo() { if (video) { video.pause() } },
      isMuted() { return video ? video.muted : true },

      destroy() {
        destroyed = true
        if (reconnectTimer) {
          global.clearTimeout(reconnectTimer)
          reconnectTimer = null
        }
        teardownConnection()
        if (video && video.parentNode) {
          video.parentNode.removeChild(video)
        }
        video = null
      },
    }
  }

  global.LiveProPlayerWhep = { create: createWhepPlayer, STATE }
}(window))
