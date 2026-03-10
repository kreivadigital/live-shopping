(function () {
  if (!window.LiveProWidgetConfig) {
    return
  }

  const cfg = window.LiveProWidgetConfig
  const widgetCfg = normalizeWidgetConfig(cfg.widget || {})
  const storeId = Number(cfg.storeId || 0)

  if (!storeId || !cfg.backofficeUrl) {
    return
  }

  const state = {
    expanded: false,
    isMuted: widgetCfg.startMuted,
    live: null,
    mountedVideoId: null,
    lastProductSignature: '',
  }

  const root = document.createElement('div')
  root.id = 'livepro-widget-root'
  document.body.appendChild(root)
  applyLayoutConfig()

  function render() {
    applyLayoutConfig()

    if (!state.live || !state.live.is_live) {
      root.className = ''
      root.innerHTML = ''
      return
    }

    root.className = state.expanded ? 'livepro-expanded' : 'livepro-collapsed'

    if (!state.expanded) {
      renderCollapsed()
      return
    }

    renderExpanded()
  }

  function renderCollapsed() {
    const previewImage = resolvePreviewImage()
    const viewers = getViewerLabel()
    const pills = renderStatusPills(viewers, 'mini')

    root.innerHTML = `
      <button class="livepro-mini" type="button" aria-label="Abrir live shopping">
        ${previewImage ? `<img class="livepro-mini__bg" src="${escapeAttribute(previewImage)}" alt="Preview live">` : ''}
        <span class="livepro-mini__overlay"></span>
        ${pills ? `<span class="livepro-mini__top">${pills}</span>` : ''}
        <span class="livepro-mini__center">
          <span class="livepro-mini__play">${renderPlayIcon()}</span>
          <span class="livepro-mini__cta">${escapeHtml(widgetCfg.labels.previewCta)}</span>
        </span>
      </button>
    `

    root.querySelector('.livepro-mini').addEventListener('click', () => {
      state.expanded = true
      render()
    })
  }

  function renderExpanded() {
    const product = state.live && state.live.product ? state.live.product : {}
    const videoId = state.live.youtube_video_id || ''
    const viewers = getViewerLabel()
    const hasActiveProduct = Number(product.product_id || 0) > 0

    state.mountedVideoId = videoId
    state.lastProductSignature = productSignature(product)

    root.innerHTML = `
      <section class="livepro-shell" aria-label="Live shopping">
        <div class="livepro-shell__media">
          <iframe
            class="livepro-shell__iframe"
            src="${escapeAttribute(buildEmbedUrl(videoId, widgetCfg.autoplay, state.isMuted))}"
            title="Live Shopping"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
          ></iframe>
          <span class="livepro-shell__scrim"></span>
        </div>

        <div class="livepro-shell__topbar">
          <div class="livepro-shell__status">
            ${renderStatusPills(viewers, 'panel')}
          </div>
          <div class="livepro-shell__actions">
            <button class="livepro-icon-btn livepro-mute" type="button" aria-label="Silenciar o activar audio">
              ${renderVolumeIcon(state.isMuted)}
            </button>
            <button class="livepro-icon-btn livepro-close" type="button" aria-label="Cerrar live shopping">
              ${renderCloseIcon()}
            </button>
          </div>
        </div>

        <div class="livepro-shell__dock-host">
          ${hasActiveProduct ? renderProductDock(product) : ''}
        </div>
      </section>
    `

    bindExpandedInteractions()
  }

  function bindExpandedInteractions() {
    const closeButton = root.querySelector('.livepro-close')
    if (closeButton && closeButton.dataset.bound !== '1') {
      closeButton.addEventListener('click', () => {
        state.expanded = false
        render()
      })
      closeButton.dataset.bound = '1'
    }

    const muteButton = root.querySelector('.livepro-mute')
    if (muteButton && muteButton.dataset.bound !== '1') {
      muteButton.addEventListener('click', () => {
        state.isMuted = !state.isMuted
        render()
      })
      muteButton.dataset.bound = '1'
    }

    const productButton = root.querySelector('.livepro-product-dock__cta')
    if (productButton) {
      productButton.addEventListener('click', () => {
        const dock = root.querySelector('.livepro-product-dock')
        if (!dock) {
          return
        }

        dock.classList.remove('is-primed')
        window.requestAnimationFrame(() => dock.classList.add('is-primed'))
        window.setTimeout(() => dock.classList.remove('is-primed'), 700)
      })
    }
  }

  function renderProductDock(product) {
    return `
      <div class="livepro-product-dock">
        <div class="livepro-product-dock__card">
          <div class="livepro-product-dock__image-wrap">
            <img class="livepro-product-dock__image" src="${escapeAttribute(product.image || '')}" alt="Producto" onerror="this.style.display='none'">
          </div>

          <div class="livepro-product-dock__body">
            <div class="livepro-product-dock__meta">
              <span class="livepro-product-dock__tag">DESTACADO</span>
              <span class="livepro-product-dock__stock">
                ${renderStockIcon()}
                ${escapeHtml(getStockLabel(product))}
              </span>
            </div>

            <p class="livepro-product-dock__title">${escapeHtml(product.name || 'Producto destacado')}</p>
            <p class="livepro-product-dock__price">${escapeHtml(product.price || '')}</p>

            <button class="livepro-product-dock__cta" type="button">
              ${renderChevronIcon()}
              ${escapeHtml(widgetCfg.labels.productCta)}
            </button>
          </div>
        </div>
      </div>
    `
  }

  function updateExpandedShell() {
    if (!state.expanded) {
      return false
    }

    const shell = root.querySelector('.livepro-shell')
    if (!shell) {
      return false
    }

    const product = state.live && state.live.product ? state.live.product : {}
    const viewers = getViewerLabel()
    const hasActiveProduct = Number(product.product_id || 0) > 0
    const dockHost = shell.querySelector('.livepro-shell__dock-host')
    const statusHost = shell.querySelector('.livepro-shell__status')

    if (statusHost) {
      statusHost.innerHTML = renderStatusPills(viewers, 'panel')
    }

    if (dockHost) {
      dockHost.innerHTML = hasActiveProduct ? renderProductDock(product) : ''
    }

    state.lastProductSignature = productSignature(product)
    bindExpandedInteractions()

    return true
  }

  async function pollLive() {
    try {
      const response = await fetch(`${cfg.backofficeUrl.replace(/\/$/, '')}/api/v1/live/public/${storeId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      })

      const body = await response.json()
      if (!response.ok) {
        throw new Error(body.error || 'No se pudo obtener estado live')
      }

      const prevVideoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : null
      state.live = body
      const nextVideoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : null

      if (state.expanded && prevVideoId && nextVideoId && prevVideoId === nextVideoId && state.mountedVideoId === nextVideoId) {
        updateExpandedShell()
      } else {
        render()
      }
    } catch (_error) {
      state.live = null
      render()
    }
  }

  function applyLayoutConfig() {
    root.style.setProperty('--livepro-width-desktop', `${widgetCfg.widthDesktop}px`)
    root.style.setProperty('--livepro-width-mobile', widgetCfg.widthMobile ? `${widgetCfg.widthMobile}px` : 'calc(100vw - 24px)')
    root.style.setProperty('--livepro-offset-x', `${widgetCfg.offset.x}px`)
    root.style.setProperty('--livepro-offset-y', `${widgetCfg.offset.y}px`)

    root.dataset.orientation = widgetCfg.orientation
    root.dataset.vertical = widgetCfg.position.vertical
    root.dataset.horizontal = widgetCfg.position.horizontal
  }

  function renderStatusPills(viewers, context) {
    const parts = []

    if (widgetCfg.indicators.showLiveBadge) {
      const badgeClass = context === 'mini' ? 'livepro-pill livepro-pill--live livepro-pill--mini' : 'livepro-pill livepro-pill--live'
      parts.push(`<span class="${badgeClass}"><span class="livepro-pill__dot"></span>${escapeHtml(widgetCfg.labels.liveBadge)}</span>`)
    }

    if (widgetCfg.indicators.showViewers && viewers) {
      const viewersClass = context === 'mini' ? 'livepro-pill livepro-pill--ghost livepro-pill--mini' : 'livepro-pill livepro-pill--ghost'
      parts.push(`<span class="${viewersClass}">${renderUsersIcon()}${escapeHtml(viewers)}</span>`)
    }

    return parts.join('')
  }

  function resolvePreviewImage() {
    if (cfg.previewImageUrl) {
      return cfg.previewImageUrl
    }

    const videoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : ''
    if (!videoId) {
      return ''
    }

    return `https://i.ytimg.com/vi/${encodeURIComponent(String(videoId))}/hqdefault.jpg`
  }

  function getViewerLabel() {
    const raw = String((state.live && state.live.viewer_count) || cfg.previewViewers || '').trim()
    return raw
  }

  function normalizeWidgetConfig(config) {
    return {
      widthDesktop: Number(config.widthDesktop || 392),
      widthMobile: config.widthMobile ? Number(config.widthMobile) : null,
      orientation: config.orientation === 'horizontal' ? 'horizontal' : 'vertical',
      position: {
        vertical: config.position && config.position.vertical === 'top' ? 'top' : 'bottom',
        horizontal: config.position && config.position.horizontal === 'right' ? 'right' : 'left',
      },
      offset: {
        x: Number(config.offset && config.offset.x ? config.offset.x : 16),
        y: Number(config.offset && config.offset.y ? config.offset.y : 16),
      },
      autoplay: config.autoplay !== false,
      startMuted: config.startMuted !== false,
      labels: {
        previewCta: String(config.labels && config.labels.previewCta ? config.labels.previewCta : 'VER AHORA'),
        productCta: String(config.labels && config.labels.productCta ? config.labels.productCta : 'VER PRODUCTO'),
        liveBadge: String(config.labels && config.labels.liveBadge ? config.labels.liveBadge : 'VIVO'),
      },
      indicators: {
        showLiveBadge: config.indicators ? config.indicators.showLiveBadge !== false : true,
        showViewers: config.indicators ? config.indicators.showViewers !== false : true,
      },
    }
  }

  function buildEmbedUrl(videoId, autoplay, muted) {
    const params = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      mute: muted ? '1' : '0',
      playsinline: '1',
      rel: '0',
      controls: '1',
      modestbranding: '1',
    })

    return `https://www.youtube.com/embed/${encodeURIComponent(String(videoId || ''))}?${params.toString()}`
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;')
  }

  function escapeAttribute(value) {
    return escapeHtml(value || '').replaceAll('`', '')
  }

  function productSignature(product) {
    if (!product) {
      return ''
    }

    return [
      String(product.product_id || ''),
      String(product.variation_id || ''),
      String(product.name || ''),
      String(product.price || ''),
      String(product.image || ''),
      String(product.stock || ''),
      String(product.stock_quantity || ''),
      String(product.available_qty || ''),
    ].join('|')
  }

  function getStockLabel(product) {
    const raw = product && (
      product.stock ??
      product.stock_quantity ??
      product.available_qty ??
      product.qty
    )

    const qty = Number(raw)
    if (Number.isFinite(qty) && qty > 0) {
      return `${Math.floor(qty)} disponibles`
    }

    return 'Disponible'
  }

  function renderPlayIcon() {
    return `
      <svg viewBox="0 0 40 40" aria-hidden="true">
        <circle cx="20" cy="20" r="20" fill="currentColor"></circle>
        <path d="M16 12.5L28 20L16 27.5V12.5Z" fill="#ffffff"></path>
      </svg>
    `
  }

  function renderVolumeIcon(isMuted) {
    return isMuted
      ? `
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M11 6L8.6 8.4H5v7.2h3.6L11 18V6Z"></path>
          <path d="M15.5 9.2L19 14.8"></path>
          <path d="M19 9.2L15.5 14.8"></path>
        </svg>
      `
      : `
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M11 6L8.6 8.4H5v7.2h3.6L11 18V6Z"></path>
          <path d="M15.2 9.2C16.7 10.3 17.6 12 17.6 13.8C17.6 15.6 16.7 17.3 15.2 18.4"></path>
          <path d="M17.4 6.8C19.7 8.5 21 11.1 21 13.8C21 16.5 19.7 19.1 17.4 20.8"></path>
        </svg>
      `
  }

  function renderCloseIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M7 7L17 17"></path>
        <path d="M17 7L7 17"></path>
      </svg>
    `
  }

  function renderUsersIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M9 11.2C10.9 11.2 12.4 9.7 12.4 7.8C12.4 5.9 10.9 4.4 9 4.4C7.1 4.4 5.6 5.9 5.6 7.8C5.6 9.7 7.1 11.2 9 11.2Z"></path>
        <path d="M3.8 18.2C4.4 15.9 6.4 14.4 9 14.4C11.6 14.4 13.6 15.9 14.2 18.2"></path>
        <path d="M16.2 10.2C17.5 10.2 18.6 9.1 18.6 7.8C18.6 6.5 17.5 5.4 16.2 5.4"></path>
        <path d="M16.8 14.8C18.7 15.1 20.1 16.3 20.6 18.2"></path>
      </svg>
    `
  }

  function renderStockIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 6.5C15.2 6.5 17.8 9.1 17.8 12.3C17.8 15.5 15.2 18.1 12 18.1C8.8 18.1 6.2 15.5 6.2 12.3C6.2 9.1 8.8 6.5 12 6.5Z"></path>
        <path d="M12 9.3V12.5L14 13.7"></path>
      </svg>
    `
  }

  function renderChevronIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M8.5 5.8L15.2 12L8.5 18.2"></path>
      </svg>
    `
  }

  pollLive()
  setInterval(pollLive, Number(cfg.pollMs || 5000))
})()
