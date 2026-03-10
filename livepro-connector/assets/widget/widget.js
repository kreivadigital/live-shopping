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
    productSheetOpen: false,
    activePhotoIndex: 0,
    isMuted: widgetCfg.startMuted,
    live: null,
    mountedVideoId: null,
    lastProductSignature: '',
    activeProductKey: '',
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
      state.productSheetOpen = false
      state.activePhotoIndex = 0
      state.activeProductKey = ''
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
    const product = getProductViewModel(state.live && state.live.product ? state.live.product : {})
    const videoId = state.live.youtube_video_id || ''
    const viewers = getViewerLabel()
    const hasActiveProduct = Boolean(product.key)

    syncProductState(product)

    state.mountedVideoId = videoId
    state.lastProductSignature = productSignature(product.raw)

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

        ${hasActiveProduct && state.productSheetOpen ? renderProductSheet(product) : ''}
      </section>
    `

    bindExpandedInteractions(product)
  }

  function bindExpandedInteractions(product) {
    const closeButton = root.querySelector('.livepro-close')
    if (closeButton && closeButton.dataset.bound !== '1') {
      closeButton.addEventListener('click', () => {
        state.expanded = false
        state.productSheetOpen = false
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
    if (productButton && productButton.dataset.bound !== '1') {
      productButton.addEventListener('click', () => {
        state.productSheetOpen = true
        render()
      })
      productButton.dataset.bound = '1'
    }

    const sheetCloseButton = root.querySelector('.livepro-product-sheet__close')
    if (sheetCloseButton && sheetCloseButton.dataset.bound !== '1') {
      sheetCloseButton.addEventListener('click', () => {
        state.productSheetOpen = false
        render()
      })
      sheetCloseButton.dataset.bound = '1'
    }

    const prevButton = root.querySelector('.livepro-gallery-nav--prev')
    if (prevButton && prevButton.dataset.bound !== '1') {
      prevButton.addEventListener('click', () => {
        state.activePhotoIndex = state.activePhotoIndex <= 0 ? product.gallery.length - 1 : state.activePhotoIndex - 1
        render()
      })
      prevButton.dataset.bound = '1'
    }

    const nextButton = root.querySelector('.livepro-gallery-nav--next')
    if (nextButton && nextButton.dataset.bound !== '1') {
      nextButton.addEventListener('click', () => {
        state.activePhotoIndex = state.activePhotoIndex >= product.gallery.length - 1 ? 0 : state.activePhotoIndex + 1
        render()
      })
      nextButton.dataset.bound = '1'
    }

    root.querySelectorAll('.livepro-gallery-dot').forEach((dot) => {
      if (dot.dataset.bound === '1') {
        return
      }

      dot.addEventListener('click', () => {
        state.activePhotoIndex = Number(dot.dataset.index || 0)
        render()
      })
      dot.dataset.bound = '1'
    })
  }

  function renderProductDock(product) {
    return `
      <div class="livepro-product-dock">
        <div class="livepro-product-dock__card">
          <div class="livepro-product-dock__image-wrap">
            <img class="livepro-product-dock__image" src="${escapeAttribute(product.primaryImage)}" alt="Producto" onerror="this.style.display='none'">
          </div>

          <div class="livepro-product-dock__body">
            <div class="livepro-product-dock__meta">
              <span class="livepro-product-dock__tag">DESTACADO</span>
              <span class="livepro-product-dock__stock">
                ${renderStockIcon()}
                ${escapeHtml(product.stockLabel)}
              </span>
            </div>

            <p class="livepro-product-dock__title">${escapeHtml(product.name)}</p>
            <p class="livepro-product-dock__price">${escapeHtml(product.price)}</p>

            <button class="livepro-product-dock__cta" type="button">
              ${renderChevronIcon()}
              ${escapeHtml(widgetCfg.labels.productCta)}
            </button>
          </div>
        </div>
      </div>
    `
  }

  function renderProductSheet(product) {
    const galleryCount = product.gallery.length
    const activeIndex = clamp(state.activePhotoIndex, 0, galleryCount - 1)
    const activePhoto = product.gallery[activeIndex] || product.primaryImage

    return `
      <div class="livepro-product-sheet" role="dialog" aria-modal="false" aria-label="Detalle de producto">
        <div class="livepro-product-sheet__handle"></div>
        <button class="livepro-product-sheet__close" type="button" aria-label="Cerrar detalle de producto">
          ${renderSheetCloseIcon()}
        </button>

        <div class="livepro-product-sheet__header">
          <p class="livepro-product-sheet__step">Paso ${galleryCount ? activeIndex + 1 : 1} de ${galleryCount || 1}</p>
          <div class="livepro-product-sheet__hero">
            <div class="livepro-product-sheet__photo">
              <img src="${escapeAttribute(activePhoto)}" alt="${escapeAttribute(product.name)}" onerror="this.style.display='none'">
            </div>
            <div class="livepro-product-sheet__summary">
              <p class="livepro-product-sheet__title">${escapeHtml(product.name)}</p>
              <p class="livepro-product-sheet__price">${escapeHtml(product.price)}</p>
              <p class="livepro-product-sheet__stock">${renderStockSparkIcon()}${escapeHtml(product.stockLabel)}</p>
            </div>
          </div>
        </div>

        <div class="livepro-product-sheet__gallery">
          ${galleryCount > 1 ? `
            <div class="livepro-gallery">
              <button class="livepro-gallery-nav livepro-gallery-nav--prev" type="button" aria-label="Foto anterior">${renderArrowIcon('left')}</button>
              <div class="livepro-gallery__viewport">
                <img src="${escapeAttribute(activePhoto)}" alt="${escapeAttribute(product.name)}" onerror="this.style.display='none'">
              </div>
              <button class="livepro-gallery-nav livepro-gallery-nav--next" type="button" aria-label="Foto siguiente">${renderArrowIcon('right')}</button>
            </div>
            <div class="livepro-gallery-dots">
              ${product.gallery.map((_, index) => `
                <button
                  class="livepro-gallery-dot ${index === activeIndex ? 'is-active' : ''}"
                  type="button"
                  data-index="${index}"
                  aria-label="Ir a foto ${index + 1}"
                ></button>
              `).join('')}
            </div>
          ` : ''}
        </div>

        <div class="livepro-product-sheet__blocks">
          ${renderInfoGroup('Color', product.colors, 'No informado')}
          ${renderInfoGroup('Tamaño', product.sizes, 'No informado')}
        </div>

        <div class="livepro-product-sheet__footer">
          <button class="livepro-product-sheet__continue" type="button">
            CONTINUAR
            ${renderChevronIcon()}
          </button>
        </div>
      </div>
    `
  }

  function renderInfoGroup(label, items, fallbackLabel) {
    const safeItems = Array.isArray(items) ? items.filter(Boolean) : []
    const tokens = safeItems.length > 0 ? safeItems : [fallbackLabel]

    return `
      <section class="livepro-info-group">
        <p class="livepro-info-group__label">${escapeHtml(label)}</p>
        <div class="livepro-info-group__tokens">
          ${tokens.map((item) => `<span class="livepro-info-token">${escapeHtml(item)}</span>`).join('')}
        </div>
      </section>
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

    const product = getProductViewModel(state.live && state.live.product ? state.live.product : {})
    const viewers = getViewerLabel()
    const hasActiveProduct = Boolean(product.key)
    const dockHost = shell.querySelector('.livepro-shell__dock-host')
    const statusHost = shell.querySelector('.livepro-shell__status')

    syncProductState(product)

    if (statusHost) {
      statusHost.innerHTML = renderStatusPills(viewers, 'panel')
    }

    if (dockHost) {
      dockHost.innerHTML = hasActiveProduct ? renderProductDock(product) : ''
    }

    const existingSheet = shell.querySelector('.livepro-product-sheet')
    if (existingSheet) {
      existingSheet.remove()
    }

    if (hasActiveProduct && state.productSheetOpen) {
      shell.insertAdjacentHTML('beforeend', renderProductSheet(product))
    }

    state.lastProductSignature = productSignature(product.raw)
    bindExpandedInteractions(product)

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

  function syncProductState(product) {
    if (!product.key) {
      state.productSheetOpen = false
      state.activePhotoIndex = 0
      state.activeProductKey = ''
      return
    }

    if (state.activeProductKey !== product.key) {
      state.activeProductKey = product.key
      state.activePhotoIndex = clamp(state.activePhotoIndex, 0, product.gallery.length - 1)
    } else {
      state.activePhotoIndex = clamp(state.activePhotoIndex, 0, product.gallery.length - 1)
    }
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
    return String((state.live && state.live.viewer_count) || cfg.previewViewers || '').trim()
  }

  function getProductViewModel(product) {
    const gallery = dedupeList([
      product.image,
      ...(readImageList(product.gallery)),
      ...(readImageList(product.images)),
      ...(readImageList(product.photos)),
    ])

    const colors = dedupeList([
      ...(readTextList(product.colors)),
      ...(readTextList(product.color_options)),
      ...(readAttributeValues(product.attributes, ['color', 'colour', 'colores', 'colores'])),
    ])

    const sizes = dedupeList([
      ...(readTextList(product.sizes)),
      ...(readTextList(product.talles)),
      ...(readTextList(product.size_options)),
      ...(readAttributeValues(product.attributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño'])),
    ])

    const key = [
      String(product.product_id || ''),
      String(product.variation_id || ''),
      String(product.name || ''),
    ].join('|')

    return {
      key: key === '||' ? '' : key,
      name: String(product.name || 'Producto destacado'),
      price: String(product.price || ''),
      stockLabel: getStockLabel(product),
      primaryImage: gallery[0] || '',
      gallery: gallery.length > 0 ? gallery : [''],
      colors,
      sizes,
      raw: product,
    }
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

  function readImageList(value) {
    if (!Array.isArray(value)) {
      return []
    }

    return value
      .map((item) => {
        if (typeof item === 'string') {
          return item.trim()
        }

        if (item && typeof item === 'object') {
          return String(item.src || item.url || item.image || '').trim()
        }

        return ''
      })
      .filter(Boolean)
  }

  function readTextList(value) {
    if (!Array.isArray(value)) {
      return []
    }

    return value
      .map((item) => {
        if (typeof item === 'string' || typeof item === 'number') {
          return String(item).trim()
        }

        if (item && typeof item === 'object') {
          return String(item.label || item.name || item.value || item.option || '').trim()
        }

        return ''
      })
      .filter(Boolean)
  }

  function readAttributeValues(attributes, candidates) {
    if (!Array.isArray(attributes)) {
      return []
    }

    const normalizedCandidates = candidates.map((candidate) => normalizeKey(candidate))
    const values = []

    attributes.forEach((attribute) => {
      if (!attribute || typeof attribute !== 'object') {
        return
      }

      const attrName = normalizeKey(attribute.name || attribute.slug || attribute.label || '')
      if (!normalizedCandidates.includes(attrName)) {
        return
      }

      if (Array.isArray(attribute.options)) {
        values.push(...readTextList(attribute.options))
        return
      }

      const singleValue = String(attribute.option || attribute.value || '').trim()
      if (singleValue) {
        values.push(singleValue)
      }
    })

    return values
  }

  function dedupeList(values) {
    const seen = new Set()

    return values.filter((value) => {
      const normalized = String(value || '').trim()
      if (!normalized || seen.has(normalized)) {
        return false
      }

      seen.add(normalized)
      return true
    })
  }

  function normalizeKey(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim()
  }

  function clamp(value, min, max) {
    if (!Number.isFinite(value)) {
      return min
    }

    return Math.min(Math.max(value, min), Math.max(min, max))
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
      JSON.stringify(product.gallery || product.images || product.photos || []),
      JSON.stringify(product.colors || product.color_options || []),
      JSON.stringify(product.sizes || product.talles || product.size_options || []),
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
      return `Solo quedan ${Math.floor(qty)} unidades`
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

  function renderSheetCloseIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M8 8L16 16"></path>
        <path d="M16 8L8 16"></path>
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

  function renderStockSparkIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M7.5 12L9.5 14L16.5 7"></path>
        <path d="M12 4.5L12.7 6.2L14.5 6.9L12.7 7.6L12 9.3L11.3 7.6L9.5 6.9L11.3 6.2L12 4.5Z"></path>
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

  function renderArrowIcon(direction) {
    return direction === 'left'
      ? `
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M14.8 5.8L8.1 12L14.8 18.2"></path>
        </svg>
      `
      : `
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M9.2 5.8L15.9 12L9.2 18.2"></path>
        </svg>
      `
  }

  pollLive()
  setInterval(pollLive, Number(cfg.pollMs || 5000))
})()
