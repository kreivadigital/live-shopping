(function () {
  if (!window.LiveProWidgetConfig) {
    return
  }

  const cfg = window.LiveProWidgetConfig
  const widgetCfg = normalizeWidgetConfig(cfg.widget || {})
  const checkoutUrl = String(cfg.checkoutUrl || '').trim()
  const storeId = Number(cfg.storeId || 0)
  const autoOpenStorageKey = `livepro:auto-open:${storeId}`
  const TRANSITION_DELTA_MS = 600
  let dockPositionRaf = 0

  if (!storeId || !cfg.backofficeUrl) {
    return
  }

  const state = {
    expanded: false,
    shellOpening: false,
    shellClosing: false,
    dockCollapsed: false,
    productSheetOpen: false,
    productSheetOpening: false,
    productSheetClosing: false,
    sheetProductSnapshot: null,
    sheetSelectionProductKey: '',
    sheetSelectedColor: '',
    sheetSelectedSize: '',
    sheetSelectedVariationId: null,
    activePhotoIndex: 0,
    isMuted: widgetCfg.startMuted,
    isPaused: !widgetCfg.autoplay,
    live: null,
    liveItems: [],
    liveRevision: 0,
    liveSessionKey: '',
    followLive: true,
    dockTransitioning: false,
    pendingProductKey: '',
    pendingIncomingProductKey: '',
    mountedVideoId: null,
    lastProductSignature: '',
    activeProductKey: '',
    fallbackCache: {},
    fallbackStatus: {},
    cartConfirmationVisible: false,
    cartConfirmationItem: null,
    cartViewOpen: false,
    cartViewOpening: false,
    cartViewClosing: false,
    cart: null,
    cartLoading: false,
    cartError: null,
    addToCartLoading: false,
  }

  const root = document.createElement('div')
  root.id = 'livepro-widget-root'
  document.body.appendChild(root)

  const templateEl = document.createElement('template')
  function htmlToFragment(html) {
    templateEl.innerHTML = html
    return templateEl.content
  }
  function replaceContent(container, html) {
    container.replaceChildren(htmlToFragment(html))
  }
  function replaceNode(oldNode, html) {
    const fragment = htmlToFragment(html)
    const newNode = fragment.firstElementChild || fragment.firstChild
    oldNode.parentNode.replaceChild(newNode, oldNode)
  }
  function appendContent(container, html) {
    container.appendChild(htmlToFragment(html))
  }

  function isDesktopViewport() {
    if (!window.matchMedia) {
      return true
    }

    return !window.matchMedia('(max-width: 767px)').matches
  }

  function readAutoOpenPreference() {
    try {
      return window.sessionStorage.getItem(autoOpenStorageKey) === '1'
    } catch (_error) {
      return false
    }
  }

  function writeAutoOpenPreference(enabled) {
    try {
      window.sessionStorage.setItem(autoOpenStorageKey, enabled ? '1' : '0')
    } catch (_error) {
      // Ignore storage failures and keep widget behavior in-memory.
    }
  }

  function maybeAutoOpenShell() {
    if (!state.live || !state.live.is_live || state.expanded || !isDesktopViewport() || !readAutoOpenPreference()) {
      return false
    }

    openShell({
      persistPreference: false,
      forceMuted: true,
    })

    return true
  }

  function shouldRenderMiniLive() {
    return Boolean(state.live && state.live.is_live && !state.expanded && !isDesktopViewport() && readAutoOpenPreference())
  }

  applyLayoutConfig()

  function render() {
    applyLayoutConfig()

    if (!state.live || !state.live.is_live) {
      root.className = ''
      root.replaceChildren()
      state.liveItems = []
      state.liveRevision = 0
      state.liveSessionKey = ''
      state.followLive = true
      state.dockCollapsed = false
      state.dockTransitioning = false
      state.pendingProductKey = ''
      state.pendingIncomingProductKey = ''
      state.productSheetOpen = false
      state.productSheetOpening = false
      state.productSheetClosing = false
      state.sheetProductSnapshot = null
      clearSheetSelection()
      state.activePhotoIndex = 0
      state.activeProductKey = ''
      state.lastProductSignature = ''
      state.isPaused = !widgetCfg.autoplay
      return
    }

    root.className = state.expanded ? 'livepro-expanded' : 'livepro-collapsed'

    if (!state.expanded) {
      if (shouldRenderMiniLive()) {
        renderMiniLive()
        return
      }

      renderCollapsed()
      return
    }

    renderExpanded()
  }

  function renderCollapsed() {
    const previewImage = resolvePreviewImage()
    const viewers = getViewerLabel()
    const pills = renderStatusPills(viewers, 'mini')

    replaceContent(root, `
      <button class="livepro-mini" type="button" aria-label="Abrir live shopping">
        ${previewImage ? `<img class="livepro-mini__bg" src="${escapeAttribute(previewImage)}" alt="Preview live">` : ''}
        <span class="livepro-mini__overlay"></span>
        ${pills ? `<span class="livepro-mini__top">${pills}</span>` : ''}
        <span class="livepro-mini__center">
          <span class="livepro-mini__play">${renderPlayIcon()}</span>
          <span class="livepro-mini__cta">${escapeHtml(widgetCfg.labels.previewCta)}</span>
        </span>
      </button>
    `)

    root.querySelector('.livepro-mini').addEventListener('click', () => {
      openShell()
    })
  }

  function renderMiniLive() {
    const videoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : ''
    if (!videoId) {
      renderCollapsed()
      return
    }

    const pills = renderStatusPills(getViewerLabel(), 'mini')
    const existing = root.querySelector('.livepro-mini.livepro-mini--live')

    if (existing && existing.dataset.videoId === videoId) {
      updateMiniLive(existing, pills)
      bindMiniLiveInteractions(existing)
      return
    }

    replaceContent(root, `
      <div class="livepro-mini livepro-mini--live" data-video-id="${escapeAttribute(videoId)}" aria-label="Abrir live shopping">
        <iframe
          class="livepro-mini__bg livepro-mini__iframe"
          src="${escapeAttribute(buildEmbedUrl(videoId, true, true))}"
          title="Live Shopping preview"
          tabindex="-1"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        ></iframe>
        <span class="livepro-mini__overlay"></span>
        <span class="livepro-mini__top">${pills}</span>
        <span class="livepro-mini__live-indicator" aria-hidden="true"></span>
        <button class="livepro-mini__hit" type="button" aria-label="Abrir live shopping"></button>
      </div>
    `)

    bindMiniLiveInteractions(root.querySelector('.livepro-mini.livepro-mini--live'))
  }

  function updateMiniLive(container, pills) {
    if (!container) {
      return
    }

    const top = container.querySelector('.livepro-mini__top')
    if (top) {
      replaceContent(top, pills)
    }
  }

  function bindMiniLiveInteractions(container) {
    if (!container) {
      return
    }

    const hit = container.querySelector('.livepro-mini__hit')
    if (hit && hit.dataset.bound !== '1') {
      hit.addEventListener('click', () => {
        openShell()
      })
      hit.dataset.bound = '1'
    }
  }

  function renderExpanded() {
    const liveProduct = getLiveProductViewModel()
    const product = getDisplayedProductViewModel(liveProduct)
    const videoId = state.live.youtube_video_id || ''
    const viewers = getViewerLabel()
    const hasActiveProduct = Boolean(product.key)

    syncProductState(product)

    state.mountedVideoId = videoId
    state.lastProductSignature = productSignature(product.raw)

    replaceContent(root, `
      <section class="livepro-shell ${state.shellClosing ? 'is-closing' : (state.shellOpening ? '' : 'is-open')}" aria-label="Live shopping">
        <div class="livepro-shell__media">
          <iframe
            class="livepro-shell__iframe"
            src="${escapeAttribute(buildEmbedUrl(videoId, widgetCfg.autoplay, state.isMuted))}"
            title="Live Shopping"
            tabindex="-1"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
          ></iframe>
          <span class="livepro-shell__scrim"></span>
          <span class="livepro-shell__interaction-blocker" aria-hidden="true"></span>
        </div>

        <div class="livepro-shell__topbar">
          <div class="livepro-shell__status">
            ${renderStatusPills(viewers, 'panel')}
          </div>
          <div class="livepro-shell__actions">
            <button class="livepro-icon-btn livepro-cart-btn" type="button" aria-label="Ver carrito" style="background:${escapeAttribute(widgetCfg.colors.cartIconBackground)};color:${escapeAttribute(widgetCfg.colors.cartIconColor)}">
              ${renderCartIcon()}
              <span class="livepro-cart-badge${(!state.cart || state.cart.item_count === 0) ? ' is-hidden' : ''}">
                <span class="livepro-cart-badge__count">${state.cart ? state.cart.item_count : 0}</span>
              </span>
            </button>
            <button class="livepro-icon-btn livepro-pause" type="button" aria-label="${escapeAttribute(getPauseButtonLabel())}">
              ${renderPauseIcon(state.isPaused)}
            </button>
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

        ${hasActiveProduct && (state.productSheetOpen || state.productSheetClosing) ? renderProductSheet(product) : ''}
        ${state.cartConfirmationVisible ? renderCartConfirmation() : ''}
        ${(state.cartViewOpen || state.cartViewClosing) ? renderCartView() : ''}
      </section>
    `)

    bindExpandedInteractions(product)
    syncDockTrackPosition(false)

    if (state.shellOpening) {
      animateShellIn()
    }

    if (state.productSheetOpening) {
      animateProductSheetIn()
    }

    if (state.cartViewOpening) {
      animateCartViewIn()
    }
  }

  function bindExpandedInteractions(product) {
    const playerFrame = root.querySelector('.livepro-shell__iframe')
    if (playerFrame && playerFrame.dataset.bound !== '1') {
      playerFrame.addEventListener('load', () => {
        registerPlayerBridge()
        syncPlayerStateToFrame()
      })
      playerFrame.dataset.bound = '1'
    }

    const closeButton = root.querySelector('.livepro-close')
    if (closeButton && closeButton.dataset.bound !== '1') {
      closeButton.addEventListener('click', () => {
        closeShell()
      })
      closeButton.dataset.bound = '1'
    }

    const pauseButton = root.querySelector('.livepro-pause')
    if (pauseButton && pauseButton.dataset.bound !== '1') {
      pauseButton.addEventListener('click', () => {
        const nextPaused = !state.isPaused
        state.isPaused = nextPaused
        sendPlayerCommand(nextPaused ? 'pauseVideo' : 'playVideo')
        syncPlayerActionButtons()
      })
      pauseButton.dataset.bound = '1'
    }

    const muteButton = root.querySelector('.livepro-mute')
    if (muteButton && muteButton.dataset.bound !== '1') {
      muteButton.addEventListener('click', () => {
        const nextMuted = !state.isMuted
        state.isMuted = nextMuted
        sendPlayerCommand(nextMuted ? 'mute' : 'unMute')
        syncPlayerActionButtons()
      })
      muteButton.dataset.bound = '1'
    }

    root.querySelectorAll('.livepro-product-dock__slide').forEach((slide) => {
      if (slide.dataset.bound === '1') {
        return
      }

      slide.addEventListener('click', (event) => {
        const slideKey = String(slide.dataset.productKey || '')
        if (!slideKey) {
          return
        }

        if (event.target && event.target.closest('.livepro-product-dock__close')) {
          state.dockCollapsed = true
          if (!updateExpandedShell()) {
            render()
          }
          return
        }

        if (state.dockCollapsed) {
          state.dockCollapsed = false

          if (slideKey !== state.activeProductKey) {
            applyDisplayedProductKey(slideKey)
          }

          if (!updateExpandedShell()) {
            render()
          }
          return
        }

        const isActive = slide.dataset.active === '1'
        if (event.target && event.target.closest('.livepro-product-dock__cta')) {
          if (isActive) {
            openProductSheet()
          }
          return
        }

        if (isActive) {
          return
        }

        setDisplayedProductByKey(slideKey)
      })

      slide.dataset.bound = '1'
    })

    const dockTrack = root.querySelector('.livepro-product-dock__track')
    if (dockTrack && dockTrack.dataset.bound !== '1') {
      let touchStartX = 0

      dockTrack.addEventListener('touchstart', (event) => {
        touchStartX = Number(event.touches && event.touches[0] ? event.touches[0].clientX : 0)
      }, { passive: true })

      dockTrack.addEventListener('touchend', (event) => {
        const touchEndX = Number(event.changedTouches && event.changedTouches[0] ? event.changedTouches[0].clientX : 0)
        const diff = touchEndX - touchStartX

        if (Math.abs(diff) < 36) {
          return
        }

        if (diff < 0) {
          moveDisplayedProduct(1)
          return
        }

        moveDisplayedProduct(-1)
      }, { passive: true })

      dockTrack.dataset.bound = '1'
    }

    const sheetCloseButton = root.querySelector('.livepro-product-sheet__close')
    if (sheetCloseButton && sheetCloseButton.dataset.bound !== '1') {
      sheetCloseButton.addEventListener('click', () => {
        closeProductSheet()
      })
      sheetCloseButton.dataset.bound = '1'
    }

    const prevButton = root.querySelector('.livepro-gallery-nav--prev')
    if (prevButton && prevButton.dataset.bound !== '1') {
      prevButton.addEventListener('click', () => {
        state.activePhotoIndex = state.activePhotoIndex <= 0 ? product.gallery.length - 1 : state.activePhotoIndex - 1
        renderProductSheetInShell(product)
      })
      prevButton.dataset.bound = '1'
    }

    const nextButton = root.querySelector('.livepro-gallery-nav--next')
    if (nextButton && nextButton.dataset.bound !== '1') {
      nextButton.addEventListener('click', () => {
        state.activePhotoIndex = state.activePhotoIndex >= product.gallery.length - 1 ? 0 : state.activePhotoIndex + 1
        renderProductSheetInShell(product)
      })
      nextButton.dataset.bound = '1'
    }

    root.querySelectorAll('.livepro-gallery-dot').forEach((dot) => {
      if (dot.dataset.bound === '1') {
        return
      }

      dot.addEventListener('click', () => {
        state.activePhotoIndex = Number(dot.dataset.index || 0)
        renderProductSheetInShell(product)
      })
      dot.dataset.bound = '1'
    })

    const galleryViewport = root.querySelector('.livepro-gallery__viewport')
    if (galleryViewport && galleryViewport.dataset.bound !== '1') {
      let galTouchStartX = 0
      let galTouchStartY = 0
      let galSwiping = false

      galleryViewport.addEventListener('touchstart', (e) => {
        galTouchStartX = e.touches[0].clientX
        galTouchStartY = e.touches[0].clientY
        galSwiping = false
      }, { passive: true })

      galleryViewport.addEventListener('touchmove', (e) => {
        if (galSwiping) {
          return
        }
        const dx = Math.abs(e.touches[0].clientX - galTouchStartX)
        const dy = Math.abs(e.touches[0].clientY - galTouchStartY)
        if (dx > dy && dx > 10) {
          galSwiping = true
          e.preventDefault()
        }
      }, { passive: false })

      galleryViewport.addEventListener('touchend', (e) => {
        if (!galSwiping) {
          return
        }
        const diff = e.changedTouches[0].clientX - galTouchStartX
        if (Math.abs(diff) < 30) {
          return
        }
        const galleryLen = product.gallery.length
        if (galleryLen <= 1) {
          return
        }
        if (diff < 0) {
          state.activePhotoIndex = state.activePhotoIndex >= galleryLen - 1 ? 0 : state.activePhotoIndex + 1
        } else {
          state.activePhotoIndex = state.activePhotoIndex <= 0 ? galleryLen - 1 : state.activePhotoIndex - 1
        }
        renderProductSheetInShell(product)
      }, { passive: true })

      galleryViewport.dataset.bound = '1'
    }

    root.querySelectorAll('.livepro-info-token--option').forEach((token) => {
      if (token.dataset.bound === '1') {
        return
      }

      token.addEventListener('click', () => {
        if (token.disabled) {
          return
        }

        const group = String(token.dataset.group || '')
        const value = String(token.dataset.value || '')
        if (!group || !value) {
          return
        }

        if (group === 'color') {
          toggleSheetColor(product, value)
          return
        }

        if (group === 'size') {
          toggleSheetSize(product, value)
        }
      })
      token.dataset.bound = '1'
    })

    const cartBtn = root.querySelector('.livepro-cart-btn')
    if (cartBtn && cartBtn.dataset.bound !== '1') {
      cartBtn.addEventListener('click', () => {
        openCartView()
      })
      cartBtn.dataset.bound = '1'
    }

    const addToCartBtn = root.querySelector('.livepro-product-sheet__add-to-cart')
    if (addToCartBtn && addToCartBtn.dataset.bound !== '1') {
      addToCartBtn.addEventListener('click', () => {
        if (addToCartBtn.disabled || state.addToCartLoading) {
          return
        }
        const raw = product.raw || {}
        const productId = Number(raw.product_id || 0)
        const variationId = state.sheetSelectedVariationId || 0
        if (productId > 0) {
          addToCart(productId, variationId, 1)
        }
      })
      addToCartBtn.dataset.bound = '1'
    }

    if (state.cartConfirmationVisible) {
      bindCartConfirmationInteractions()
    }

    if (state.cartViewOpen || state.cartViewClosing) {
      bindCartViewInteractions()
    }
  }

  function renderProductDock(product) {
    const activeKey = product && product.key ? String(product.key) : String(getDisplayedLiveProduct().key || '')
    const items = state.dockCollapsed
      ? [getLiveProductByKey(activeKey)].filter((item) => item && item.key)
      : getDockItems()
    const latestKey = getLiveActiveProductKey()
    if (!activeKey || items.length === 0) {
      return ''
    }

    return `
      <div class="livepro-product-dock livepro-product-dock--count-${items.length}${state.dockCollapsed ? ' livepro-product-dock--collapsed' : ''}">
        <div class="livepro-product-dock__viewport">
          <div class="livepro-product-dock__track">
            ${items.map((item) => renderProductDockSlide(item, activeKey, latestKey)).join('')}
          </div>
        </div>
      </div>
    `
  }

  function renderProductDockSlide(rawProduct, activeKey, latestKey) {
    const product = getProductViewModel(getResolvedProductSource(rawProduct))
    const isActive = String(product.key) === String(activeKey)
    const isLatest = String(product.key) === String(latestKey)
    const isCollapsed = state.dockCollapsed

    return `
      <div
        class="livepro-product-dock__slide ${isActive ? 'is-active' : 'is-inactive'}"
        data-product-key="${escapeAttribute(product.key)}"
        data-active="${isActive ? '1' : '0'}"
      >
        <div class="livepro-product-dock__card ${isActive ? 'livepro-product-dock__card--active' : 'livepro-product-dock__card--peek'}${isCollapsed ? ' livepro-product-dock__card--collapsed' : ''}">
          ${isCollapsed ? '' : `
            <div class="livepro-product-dock__image-wrap">
              <img class="livepro-product-dock__image" src="${escapeAttribute(product.primaryImage)}" alt="Producto" onerror="this.style.display='none'">
            </div>
          `}

          <div class="livepro-product-dock__body">
            <div class="livepro-product-dock__body-info">
              ${isCollapsed ? `
                <div class="livepro-product-dock__summary-row">
                  <p class="livepro-product-dock__title">${escapeHtml(product.name)}</p>
                  <p class="livepro-product-dock__price">${escapeHtml(product.price)}</p>
                </div>
                ${isLatest ? `<div class="livepro-product-dock__status-corner">${renderProductDockStatus()}</div>` : ''}
              ` : `
                <div class="livepro-product-dock__body-top">
                  <div class="livepro-product-dock__meta">
                    <span class="livepro-product-dock__tag">${escapeHtml(widgetCfg.labels.productTag)}</span>
                    <span class="livepro-product-dock__stock is-hidden" aria-hidden="true">
                      ${renderStockIcon()}
                      ${escapeHtml(product.stockLabel)}
                    </span>
                  </div>
                  ${isLatest ? renderProductDockStatus() : ''}
                  <button class="livepro-btn-close livepro-product-dock__close" type="button" aria-label="Colapsar dock">
                    ${renderDockCloseIcon()}
                  </button>
                </div>
                <div class="livepro-product-dock__body-bottom">
                  <p class="livepro-product-dock__title">${escapeHtml(product.name)}</p>
                  <p class="livepro-product-dock__price">${escapeHtml(product.price)}</p>
                </div>
              `}
            </div>

            ${isCollapsed ? '' : `
              <div class="livepro-product-dock__body-cta">
                <button class="livepro-product-dock__cta" type="button">
                  ${escapeHtml(widgetCfg.labels.productCta)}
                </button>
              </div>
            `}
          </div>
        </div>
      </div>
    `
  }

  function renderProductDockStatus() {
    return `
      <span class="livepro-product-dock__status" aria-label="Ultimo producto emitido">
        <span class="livepro-product-dock__status-dot"></span>
      </span>
    `
  }

  function renderProductSheet(product) {
    ensureSheetSelection(product)
    const variationState = getSheetVariationState(product)
    const galleryCount = product.gallery.length
    const activeIndex = clamp(state.activePhotoIndex, 0, galleryCount - 1)
    const activePhoto = product.gallery[activeIndex] || product.primaryImage
    const variationGroups = []

    if (variationState.hasColorDimension) {
      variationGroups.push(renderSelectableInfoGroup('Color', 'color', variationState.colorOptions, 'No informado'))
    }

    if (variationState.hasSizeDimension) {
      variationGroups.push(renderSelectableInfoGroup('Talle', 'size', variationState.sizeOptions, 'No informado'))
    }

    return `
      <div class="livepro-product-sheet ${state.productSheetClosing ? 'is-closing' : (state.productSheetOpening ? '' : 'is-open')}" role="dialog" aria-modal="false" aria-label="Detalle de producto">
        <button class="livepro-btn-close livepro-product-sheet__close" type="button" aria-label="Cerrar detalle de producto">
          ${renderSheetCloseIcon()}
        </button>

        <div class="livepro-product-sheet__content">
          <div class="livepro-product-sheet__header">
            <p class="livepro-product-sheet__step is-hidden" aria-hidden="true">Paso ${galleryCount ? activeIndex + 1 : 1} de ${galleryCount || 1}</p>
          </div>

          <div class="livepro-product-sheet__gallery">
            <div class="livepro-gallery ${galleryCount <= 1 ? 'livepro-gallery--single' : ''}">
              ${galleryCount > 1 ? `
                <button class="livepro-gallery-nav livepro-gallery-nav--prev" type="button" aria-label="Foto anterior">${renderArrowIcon('left')}</button>
              ` : ''}
              <div class="livepro-gallery__viewport">
                <img src="${escapeAttribute(activePhoto)}" alt="${escapeAttribute(product.name)}" onerror="this.style.display='none'">
              </div>
              ${galleryCount > 1 ? `
                <button class="livepro-gallery-nav livepro-gallery-nav--next" type="button" aria-label="Foto siguiente">${renderArrowIcon('right')}</button>
              ` : ''}
            </div>
            ${galleryCount > 1 ? `
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

          <div class="livepro-product-sheet__details">
            <div class="livepro-product-sheet__summary">
              <div class="livepro-product-sheet__summary-row">
                <p class="livepro-product-sheet__title">${escapeHtml(product.name)}</p>
                <p class="livepro-product-sheet__price">${escapeHtml(product.price)}</p>
              </div>
              <p class="livepro-product-sheet__stock is-hidden" aria-hidden="true">${renderStockSparkIcon()}${escapeHtml(product.stockLabel)}</p>
            </div>
            ${variationGroups.join('')}
          </div>
        </div>

        <div class="livepro-product-sheet__footer">
          ${renderSheetAddToCartCta(variationState)}
        </div>
      </div>
    `
  }

  function renderSheetAddToCartCta(variationState) {
    const hasRequiredSelection = (!variationState.hasColorDimension || Boolean(state.sheetSelectedColor))
      && (!variationState.hasSizeDimension || Boolean(state.sheetSelectedSize))
    const canAdd = !variationState.isVariable || (hasRequiredSelection && Boolean(state.sheetSelectedVariationId))
    const isLoading = state.addToCartLoading
    const ctaDisabled = !canAdd || isLoading
    return `
      <button
        class="livepro-btn-cta livepro-product-sheet__add-to-cart${ctaDisabled ? ' is-disabled' : ''}"
        type="button"
        ${ctaDisabled ? 'disabled' : ''}
        style="background:${escapeAttribute(widgetCfg.colors.cartCtaBackground)};color:${escapeAttribute(widgetCfg.colors.cartCtaText)}"
      >
        ${isLoading ? renderSpinnerIcon() : escapeHtml(widgetCfg.labels.cartCta)}
      </button>
    `
  }

  function renderCartConfirmation() {
    return `
      <div class="livepro-cart-confirmation">
        <div class="livepro-cart-confirmation__content">
          <div class="livepro-cart-confirmation__message">
            <span class="livepro-cart-confirmation__check">${renderCheckIcon()}</span>
            <p class="livepro-cart-confirmation__title">Producto agregado al carrito</p>
          </div>
          <div class="livepro-cart-confirmation__actions">
            <button class="livepro-btn-cta livepro-cart-confirmation__continue" type="button" style="background:${escapeAttribute(widgetCfg.colors.continueBtnBackground)};color:${escapeAttribute(widgetCfg.colors.continueBtnText)};border-color:${escapeAttribute(widgetCfg.colors.continueBtnText)}">${escapeHtml(widgetCfg.labels.continueBtn)}</button>
            <button class="livepro-btn-cta livepro-cart-confirmation__checkout" type="button" style="background:${escapeAttribute(widgetCfg.colors.checkoutBtnBackground)};color:${escapeAttribute(widgetCfg.colors.checkoutBtnText)}">${escapeHtml(widgetCfg.labels.checkoutBtn)}</button>
          </div>
        </div>
      </div>
    `
  }

  function renderCartConfirmationInShell() {
    const shell = root.querySelector('.livepro-shell')
    if (!shell || !state.cartConfirmationVisible) {
      return
    }

    const existing = shell.querySelector('.livepro-cart-confirmation')
    if (existing) {
      existing.remove()
    }

    appendContent(shell, renderCartConfirmation())
    bindCartConfirmationInteractions()

    window.requestAnimationFrame(() => {
      const el = shell.querySelector('.livepro-cart-confirmation')
      if (el) {
        el.classList.add('is-open')
      }
    })
  }

  function bindCartConfirmationInteractions() {
    const continueBtn = root.querySelector('.livepro-cart-confirmation__continue')
    if (continueBtn && continueBtn.dataset.bound !== '1') {
      continueBtn.addEventListener('click', () => {
        dismissCartConfirmation()
      })
      continueBtn.dataset.bound = '1'
    }

    const checkoutBtn = root.querySelector('.livepro-cart-confirmation__checkout')
    if (checkoutBtn && checkoutBtn.dataset.bound !== '1') {
      checkoutBtn.addEventListener('click', () => {
        state.cartConfirmationVisible = false
        state.cartConfirmationItem = null
        const overlay = root.querySelector('.livepro-cart-confirmation')
        if (overlay) {
          overlay.remove()
        }
        openCartView()
      })
      checkoutBtn.dataset.bound = '1'
    }
  }

  function dismissCartConfirmation() {
    state.cartConfirmationVisible = false
    state.cartConfirmationItem = null
    const overlay = root.querySelector('.livepro-cart-confirmation')
    if (overlay) {
      overlay.remove()
    }
  }

  function goToCheckout() {
    if (!checkoutUrl) {
      return
    }

    window.location.href = checkoutUrl
  }

  function renderCartView() {
    const cart = state.cart
    const isEmpty = !cart || !cart.items || cart.items.length === 0
    const isLoading = state.cartLoading

    return `
      <div class="livepro-cart-view ${state.cartViewClosing ? 'is-closing' : (state.cartViewOpening ? '' : 'is-open')}" role="dialog" aria-modal="false" aria-label="Mi carrito">
        <button class="livepro-btn-close livepro-cart-view__close" type="button" aria-label="Cerrar carrito">
          ${renderSheetCloseIcon()}
        </button>

        <div class="livepro-cart-view__header">
          <p class="livepro-cart-view__title">Mi carrito</p>
        </div>

        <div class="livepro-cart-view__body">
          ${isLoading && isEmpty ? '<div class="livepro-cart-view__loading"><div class="livepro-spinner livepro-spinner--lg"></div></div>' : ''}
          ${!isLoading && isEmpty ? renderCartEmpty() : ''}
          ${!isEmpty ? renderCartItems(cart) : ''}
        </div>

        ${!isEmpty ? `
          <div class="livepro-cart-view__footer">
            <div class="livepro-cart-view__totals">
              <div class="livepro-cart-view__total-row">
                <span>Subtotal</span>
                <span>${escapeHtml(cart.subtotal)}</span>
              </div>
              <div class="livepro-cart-view__total-row livepro-cart-view__total-row--total">
                <span>Total</span>
                <span>${escapeHtml(cart.total)}</span>
              </div>
            </div>
            <button class="livepro-btn-cta livepro-cart-view__checkout" type="button" ${checkoutUrl ? '' : 'disabled aria-disabled="true"'} style="background:${escapeAttribute(widgetCfg.colors.checkoutBtnBackground)};color:${escapeAttribute(widgetCfg.colors.checkoutBtnText)}">${escapeHtml(widgetCfg.labels.checkoutBtn)}</button>
          </div>
        ` : ''}
      </div>
    `
  }

  function renderCartEmpty() {
    return `
      <div class="livepro-cart-view__empty">
        <p class="livepro-cart-view__empty-text">Tu carrito está vacío</p>
        <button class="livepro-cart-view__back" type="button">Volver al live</button>
      </div>
    `
  }

  function renderCartItems(cart) {
    return `
      <div class="livepro-cart-view__items">
        ${cart.items.map((item) => renderCartItem(item)).join('')}
      </div>
    `
  }

  function renderCartItem(item) {
    const attrLines = item.attributes && typeof item.attributes === 'object'
      ? Object.entries(item.attributes).map(([key, val]) => `<p class="livepro-cart-view__item-attr">${escapeHtml(key)}: ${escapeHtml(val)}</p>`).join('')
      : ''

    return `
      <div class="livepro-cart-view__item" data-cart-key="${escapeAttribute(item.cart_item_key)}">
        ${item.image ? `<img class="livepro-cart-view__item-image" src="${escapeAttribute(item.image)}" alt="" onerror="this.style.display='none'">` : '<div class="livepro-cart-view__item-image livepro-cart-view__item-image--empty"></div>'}
        <div class="livepro-cart-view__item-info">
          <div class="livepro-cart-view__item-top">
            <div class="livepro-cart-view__item-meta">
              <p class="livepro-cart-view__item-name">${escapeHtml(item.name)}</p>
              ${attrLines}
            </div>
            <button class="livepro-cart-view__remove" type="button" data-cart-key="${escapeAttribute(item.cart_item_key)}" aria-label="Eliminar ${escapeAttribute(item.name)}">
              ${renderTrashIcon()}
            </button>
          </div>
          <div class="livepro-cart-view__item-bottom">
            <p class="livepro-cart-view__item-price">${escapeHtml(item.price)}</p>
            <div class="livepro-cart-view__qty">
              <button class="livepro-cart-view__qty-btn livepro-cart-view__qty-minus" type="button" data-cart-key="${escapeAttribute(item.cart_item_key)}" data-qty="${item.quantity}" ${item.quantity <= 1 ? 'disabled' : ''}>−</button>
              <span class="livepro-cart-view__qty-count">${item.quantity}</span>
              <button class="livepro-cart-view__qty-btn livepro-cart-view__qty-plus" type="button" data-cart-key="${escapeAttribute(item.cart_item_key)}" data-qty="${item.quantity}" data-stock="${item.manages_stock && item.stock_quantity != null ? item.stock_quantity : ''}" ${item.manages_stock && item.stock_quantity != null && item.quantity >= item.stock_quantity ? 'disabled' : ''}>+</button>
            </div>
          </div>
        </div>
      </div>
    `
  }

  function renderCartViewInShell() {
    const shell = root.querySelector('.livepro-shell')
    if (!shell) {
      return false
    }

    const existing = shell.querySelector('.livepro-cart-view')
    if (!state.cartViewOpen && !state.cartViewClosing) {
      if (existing) {
        existing.remove()
      }
      return true
    }

    if (existing) {
      replaceNode(existing, renderCartView())
    } else {
      appendContent(shell, renderCartView())
    }

    bindCartViewInteractions()
    return true
  }

  function openCartView() {
    if (state.cartViewOpen || state.cartViewOpening) {
      return
    }

    if (state.productSheetOpen) {
      closeProductSheet()
    }

    dismissCartConfirmation()

    state.cartViewOpening = true
    state.cartViewOpen = true

    fetchCart().then(() => {
      renderCartViewInShell()
      animateCartViewIn()
    })
  }

  function closeCartView() {
    if (!state.cartViewOpen || state.cartViewClosing) {
      return
    }

    const cartView = root.querySelector('.livepro-cart-view')
    if (!cartView) {
      state.cartViewOpen = false
      state.cartViewOpening = false
      state.cartViewClosing = false
      return
    }

    state.cartViewOpening = false
    state.cartViewClosing = true
    cartView.classList.remove('is-open')
    cartView.classList.add('is-closing')

    window.setTimeout(() => {
      state.cartViewOpen = false
      state.cartViewClosing = false
      if (cartView.parentNode) {
        cartView.remove()
      }
    }, TRANSITION_DELTA_MS)
  }

  function animateCartViewIn() {
    window.requestAnimationFrame(() => {
      const cartView = root.querySelector('.livepro-cart-view')
      if (cartView) {
        cartView.classList.add('is-open')
      }
      state.cartViewOpening = false
    })
  }

  function bindCartViewInteractions() {
    const closeBtn = root.querySelector('.livepro-cart-view__close')
    if (closeBtn && closeBtn.dataset.bound !== '1') {
      closeBtn.addEventListener('click', () => {
        closeCartView()
      })
      closeBtn.dataset.bound = '1'
    }

    const backBtn = root.querySelector('.livepro-cart-view__back')
    if (backBtn && backBtn.dataset.bound !== '1') {
      backBtn.addEventListener('click', () => {
        closeCartView()
      })
      backBtn.dataset.bound = '1'
    }

    root.querySelectorAll('.livepro-cart-view__qty-minus').forEach((btn) => {
      if (btn.dataset.bound === '1') {
        return
      }
      btn.addEventListener('click', () => {
        if (btn.disabled || state.cartLoading) {
          return
        }
        const key = String(btn.dataset.cartKey || '')
        const qty = Number(btn.dataset.qty || 1)
        if (key && qty > 1) {
          updateCartItem(key, qty - 1)
        }
      })
      btn.dataset.bound = '1'
    })

    root.querySelectorAll('.livepro-cart-view__qty-plus').forEach((btn) => {
      if (btn.dataset.bound === '1') {
        return
      }
      btn.addEventListener('click', () => {
        if (btn.disabled || state.cartLoading) {
          return
        }
        const key = String(btn.dataset.cartKey || '')
        const qty = Number(btn.dataset.qty || 1)
        const stock = btn.dataset.stock !== '' ? Number(btn.dataset.stock) : null
        if (key && (stock === null || qty < stock)) {
          updateCartItem(key, qty + 1)
        }
      })
      btn.dataset.bound = '1'
    })

    root.querySelectorAll('.livepro-cart-view__remove').forEach((btn) => {
      if (btn.dataset.bound === '1') {
        return
      }
      btn.addEventListener('click', () => {
        if (state.cartLoading) {
          return
        }
        const key = String(btn.dataset.cartKey || '')
        if (key) {
          removeCartItem(key)
        }
      })
      btn.dataset.bound = '1'
    })

    const checkoutBtn = root.querySelector('.livepro-cart-view__checkout')
    if (checkoutBtn && checkoutBtn.dataset.bound !== '1') {
      checkoutBtn.addEventListener('click', () => {
        if (checkoutBtn.disabled) {
          return
        }

        goToCheckout()
      })
      checkoutBtn.dataset.bound = '1'
    }
  }

  function openShell(options) {
    if (state.expanded && !state.shellClosing) {
      return
    }

    const settings = Object.assign({
      persistPreference: true,
      forceMuted: false,
    }, options || {})

    if (settings.persistPreference) {
      writeAutoOpenPreference(true)
    }

    if (settings.forceMuted) {
      state.isMuted = true
    }

    state.isPaused = !widgetCfg.autoplay
    state.shellClosing = false
    state.shellOpening = true
    state.expanded = true
    render()
    fetchCart().then(() => syncCartBadge())
  }

  function closeShell() {
    if (!state.expanded || state.shellClosing) {
      return
    }

    writeAutoOpenPreference(false)

    const shell = root.querySelector('.livepro-shell')
    if (!shell) {
      state.expanded = false
      state.shellOpening = false
      state.shellClosing = false
      state.dockCollapsed = false
      state.productSheetOpen = false
      state.productSheetOpening = false
      state.productSheetClosing = false
      state.sheetProductSnapshot = null
      state.cartConfirmationVisible = false
      state.cartConfirmationItem = null
      state.cartViewOpen = false
      state.cartViewOpening = false
      state.cartViewClosing = false
      clearSheetSelection()
      state.isPaused = !widgetCfg.autoplay
      render()
      return
    }

    state.shellOpening = false
    state.shellClosing = true
    shell.classList.remove('is-open')
    shell.classList.add('is-closing')

    window.setTimeout(() => {
      state.expanded = false
      state.shellClosing = false
      state.dockCollapsed = false
      state.dockTransitioning = false
      state.pendingProductKey = ''
      state.pendingIncomingProductKey = ''
      state.productSheetOpen = false
      state.productSheetOpening = false
      state.productSheetClosing = false
      state.sheetProductSnapshot = null
      state.cartConfirmationVisible = false
      state.cartConfirmationItem = null
      state.cartViewOpen = false
      state.cartViewOpening = false
      state.cartViewClosing = false
      clearSheetSelection()
      state.isPaused = !widgetCfg.autoplay
      render()
    }, TRANSITION_DELTA_MS)
  }

  function openProductSheet() {
    if (state.productSheetOpen || state.productSheetClosing) {
      return
    }

    const product = getDisplayedProductViewModel(getLiveProductViewModel())
    if (!product.key) {
      return
    }

    state.sheetProductSnapshot = cloneProductSnapshot(product.raw)
    resetSheetSelection(product)
    state.productSheetClosing = false
    state.productSheetOpening = true
    state.productSheetOpen = true

    syncProductState(product)

    if (!renderProductSheetInShell(product)) {
      render()
      return
    }

    animateProductSheetIn()
  }

  function closeProductSheet() {
    if (!state.productSheetOpen || state.productSheetClosing) {
      return
    }

    const sheet = root.querySelector('.livepro-product-sheet')
    if (!sheet) {
      state.productSheetOpen = false
      state.productSheetOpening = false
      state.productSheetClosing = false
      state.sheetProductSnapshot = null
      clearSheetSelection()
      if (!updateExpandedShell()) {
        render()
      }
      return
    }

    state.productSheetClosing = true
    sheet.classList.remove('is-open')
    sheet.classList.add('is-closing')

    window.setTimeout(() => {
      state.productSheetOpen = false
      state.productSheetOpening = false
      state.productSheetClosing = false
      state.sheetProductSnapshot = null
      clearSheetSelection()
      sheet.remove()

      if (!updateExpandedShell()) {
        render()
      }
    }, TRANSITION_DELTA_MS)
  }

  function animateShellIn() {
    const shell = root.querySelector('.livepro-shell')
    if (!shell) {
      state.shellOpening = false
      return
    }

    window.requestAnimationFrame(() => {
      shell.classList.add('is-open')
      window.setTimeout(() => {
        state.shellOpening = false
      }, TRANSITION_DELTA_MS)
    })
  }

  function animateProductSheetIn() {
    const sheet = root.querySelector('.livepro-product-sheet')
    if (!sheet) {
      state.productSheetOpening = false
      return
    }

    window.requestAnimationFrame(() => {
      const currentSheet = root.querySelector('.livepro-product-sheet')
      if (!currentSheet) {
        state.productSheetOpening = false
        return
      }
      currentSheet.classList.add('is-open')
      window.setTimeout(() => {
        state.productSheetOpening = false
      }, TRANSITION_DELTA_MS)
    })
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

  function renderSelectableInfoGroup(label, group, options, fallbackLabel) {
    const safeOptions = Array.isArray(options) ? options.filter((option) => option && option.label) : []
    if (safeOptions.length === 0) {
      return ''
    }

    return `
      <section class="livepro-info-group">
        <p class="livepro-info-group__label">${escapeHtml(label)}</p>
        <div class="livepro-info-group__tokens">
          ${safeOptions.map((option) => renderVariationToken(group, option)).join('')}
        </div>
      </section>
    `
  }

  function renderVariationToken(group, option) {
    const stateClass = `${option.selected ? ' is-selected' : ''}${option.disabled ? ' is-disabled' : ''}`
    const disabledAttribute = option.disabled ? ' disabled aria-disabled="true"' : ''

    return `
      <button
        class="livepro-info-token livepro-info-token--option${stateClass}"
        type="button"
        data-group="${escapeAttribute(group)}"
        data-value="${escapeAttribute(option.label)}"
        ${disabledAttribute}
      >
        ${escapeHtml(option.label)}
      </button>
    `
  }

  function clearSheetSelection() {
    state.sheetSelectionProductKey = ''
    state.sheetSelectedColor = ''
    state.sheetSelectedSize = ''
    state.sheetSelectedVariationId = null
  }

  function resetSheetSelection(product) {
    clearSheetSelection()
    if (!product || !product.key) {
      return
    }

    const initial = getInitialSheetSelection(product)
    state.sheetSelectionProductKey = product.key
    state.sheetSelectedColor = initial.color
    state.sheetSelectedSize = initial.size
    state.sheetSelectedVariationId = initial.variationId
    state.activePhotoIndex = clamp(initial.galleryIndex, 0, product.gallery.length - 1)
  }

  function ensureSheetSelection(product) {
    if (!product || !product.key) {
      clearSheetSelection()
      return
    }

    if (state.sheetSelectionProductKey !== product.key) {
      resetSheetSelection(product)
      return
    }

    if (!state.sheetSelectedColor && !state.sheetSelectedSize && !state.sheetSelectedVariationId) {
      const variationId = Number(product.raw && product.raw.variation_id ? product.raw.variation_id : 0)
      if (variationId > 0 && Array.isArray(product.variationMatrix) && product.variationMatrix.some((variation) => Number(variation.variationId || 0) === variationId)) {
        const initial = getInitialSheetSelection(product)
        state.sheetSelectedColor = initial.color
        state.sheetSelectedSize = initial.size
        state.sheetSelectedVariationId = initial.variationId
        state.activePhotoIndex = clamp(initial.galleryIndex, 0, product.gallery.length - 1)
        return
      }
    }

    sanitizeSheetSelection(product)
  }

  function sanitizeSheetSelection(product) {
    const variationState = getSheetVariationState(product)
    if (!variationState.isVariable) {
      state.sheetSelectedVariationId = null
      return
    }

    const matrix = variationState.matrix
    let nextColor = variationState.hasColorDimension ? state.sheetSelectedColor : ''
    let nextSize = variationState.hasSizeDimension ? state.sheetSelectedSize : ''

    if (variationState.hasColorDimension && nextColor && !hasAvailableColor(matrix, nextColor, nextSize)) {
      nextColor = ''
    }

    if (variationState.hasSizeDimension && nextSize && !hasAvailableSize(matrix, nextSize, nextColor)) {
      nextSize = ''
    }

    state.sheetSelectedColor = nextColor
    state.sheetSelectedSize = nextSize

    const exactVariation = findExactVariation(matrix, nextColor, nextSize, variationState)
    state.sheetSelectedVariationId = exactVariation ? exactVariation.variationId : null
  }

  function getInitialSheetSelection(product) {
    const matrix = Array.isArray(product && product.variationMatrix) ? product.variationMatrix : []
    const variationId = Number(product && product.raw && product.raw.variation_id ? product.raw.variation_id : 0)
    if (variationId > 0) {
      const matchedVariation = matrix.find((variation) => Number(variation.variationId || 0) === variationId)
      if (matchedVariation) {
        return {
          color: matchedVariation.color,
          size: matchedVariation.size,
          variationId: matchedVariation.variationId,
          galleryIndex: matchedVariation.galleryIndex,
        }
      }
    }

    return {
      color: '',
      size: '',
      variationId: null,
      galleryIndex: 0,
    }
  }

  function getSheetVariationState(product) {
    const variationState = getVariationDimensionState(product)
    const matrix = variationState.matrix

    return {
      isVariable: variationState.isVariable,
      matrix,
      hasColorDimension: variationState.hasColorDimension,
      hasSizeDimension: variationState.hasSizeDimension,
      colorOptions: variationState.hasColorDimension
        ? variationState.colors
          .filter((label) => hasAvailableColor(matrix, label, ''))
          .map((label) => ({
            label,
            selected: isSameOption(label, state.sheetSelectedColor),
            disabled: variationState.hasSizeDimension && Boolean(state.sheetSelectedSize) && !hasAvailableColor(matrix, label, state.sheetSelectedSize),
          }))
        : [],
      sizeOptions: variationState.hasSizeDimension
        ? variationState.sizes
          .filter((label) => hasAvailableSize(matrix, label, ''))
          .map((label) => ({
            label,
            selected: isSameOption(label, state.sheetSelectedSize),
            disabled: variationState.hasColorDimension && Boolean(state.sheetSelectedColor) && !hasAvailableSize(matrix, label, state.sheetSelectedColor),
          }))
        : [],
      exactVariation: findExactVariation(matrix, state.sheetSelectedColor, state.sheetSelectedSize, variationState),
      previewVariation: findPreviewVariation(matrix, state.sheetSelectedColor, state.sheetSelectedSize, variationState),
    }
  }

  function getVariationDimensionState(product) {
    const matrix = Array.isArray(product && product.variationMatrix) ? product.variationMatrix : []
    const isVariable = matrix.length > 0
    const colors = product && Array.isArray(product.colors) && product.colors.length > 0
      ? product.colors
      : dedupeList(matrix.map((variation) => variation.color).filter(Boolean))
    const sizes = product && Array.isArray(product.sizes) && product.sizes.length > 0
      ? product.sizes
      : dedupeList(matrix.map((variation) => variation.size).filter(Boolean))
    const hasColorDimension = isVariable && (colors.length > 0 || matrix.some((variation) => String(variation && variation.color ? variation.color : '').trim() !== ''))
    const hasSizeDimension = isVariable && (sizes.length > 0 || matrix.some((variation) => String(variation && variation.size ? variation.size : '').trim() !== ''))

    return {
      isVariable,
      matrix,
      colors,
      sizes,
      hasColorDimension,
      hasSizeDimension,
    }
  }

  function toggleSheetColor(product, value) {
    ensureSheetSelection(product)

    const variationState = getSheetVariationState(product)
    const nextColor = isSameOption(state.sheetSelectedColor, value) ? '' : value
    state.sheetSelectedColor = nextColor

    if (variationState.hasSizeDimension && nextColor && state.sheetSelectedSize && !hasAvailableSize(product.variationMatrix, state.sheetSelectedSize, nextColor)) {
      state.sheetSelectedSize = ''
    }

    sanitizeSheetSelection(product)

    if (state.sheetSelectedColor) {
      const previewVariation = findPreviewVariation(product.variationMatrix, state.sheetSelectedColor, state.sheetSelectedSize, variationState)
      if (previewVariation) {
        state.activePhotoIndex = clamp(previewVariation.galleryIndex, 0, product.gallery.length - 1)
      }
    } else {
      state.activePhotoIndex = 0
    }

    renderProductSheetInShell(product)
  }

  function toggleSheetSize(product, value) {
    ensureSheetSelection(product)

    const variationState = getSheetVariationState(product)
    const previousColor = state.sheetSelectedColor
    state.sheetSelectedSize = isSameOption(state.sheetSelectedSize, value) ? '' : value

    if (variationState.hasColorDimension && state.sheetSelectedColor && state.sheetSelectedSize && !hasAvailableColor(product.variationMatrix, state.sheetSelectedColor, state.sheetSelectedSize)) {
      state.sheetSelectedColor = ''
    }

    sanitizeSheetSelection(product)

    if (state.sheetSelectedColor || state.sheetSelectedSize) {
      const previewVariation = findPreviewVariation(product.variationMatrix, state.sheetSelectedColor, state.sheetSelectedSize, variationState)
      if (previewVariation) {
        state.activePhotoIndex = clamp(previewVariation.galleryIndex, 0, product.gallery.length - 1)
      }
    } else if (previousColor || variationState.hasSizeDimension) {
      state.activePhotoIndex = 0
    }

    renderProductSheetInShell(product)
  }

  function hasAvailableColor(matrix, color, size) {
    return Array.isArray(matrix) && matrix.some((variation) => {
      if (!variation || variation.available === false) {
        return false
      }

      if (!isSameOption(variation.color, color)) {
        return false
      }

      return !size || isSameOption(variation.size, size)
    })
  }

  function hasAvailableSize(matrix, size, color) {
    return Array.isArray(matrix) && matrix.some((variation) => {
      if (!variation || variation.available === false) {
        return false
      }

      if (!isSameOption(variation.size, size)) {
        return false
      }

      return !color || isSameOption(variation.color, color)
    })
  }

  function findExactVariation(matrix, color, size, variationState) {
    const requiresColor = Boolean(variationState && variationState.hasColorDimension)
    const requiresSize = Boolean(variationState && variationState.hasSizeDimension)

    if (!Array.isArray(matrix) || (requiresColor && !color) || (requiresSize && !size)) {
      return null
    }

    return matrix.find((variation) => {
      if (!variation || variation.available === false) {
        return false
      }

      if (requiresColor && !isSameOption(variation.color, color)) {
        return false
      }

      if (requiresSize && !isSameOption(variation.size, size)) {
        return false
      }

      return true
    }) || null
  }

  function findPreviewVariation(matrix, color, size, variationState) {
    if (!Array.isArray(matrix)) {
      return null
    }

    const hasColorSelection = Boolean(variationState && variationState.hasColorDimension && color)
    const hasSizeSelection = Boolean(variationState && variationState.hasSizeDimension && size)

    if (!hasColorSelection && !hasSizeSelection) {
      return null
    }

    return findExactVariation(matrix, color, size, variationState)
      || matrix.find((variation) => variation.available !== false
        && (!hasColorSelection || isSameOption(variation.color, color))
        && (!hasSizeSelection || isSameOption(variation.size, size)))
      || (hasColorSelection
        ? matrix.find((variation) => variation.available !== false && isSameOption(variation.color, color))
        : null)
      || (hasSizeSelection
        ? matrix.find((variation) => variation.available !== false && isSameOption(variation.size, size))
        : null)
      || null
  }

  function isSameOption(left, right) {
    return normalizeKey(left || '') === normalizeKey(right || '')
  }

  function patchDock(product) {
    const dockHost = root.querySelector('.livepro-shell__dock-host')
    if (!dockHost) {
      return
    }

    const hasActiveProduct = Boolean(product.key)
    const existingDock = dockHost.querySelector('.livepro-product-dock')

    if (!hasActiveProduct) {
      dockHost.replaceChildren()
      return
    }

    if (!existingDock) {
      replaceContent(dockHost, renderProductDock(product))
      return
    }

    const track = existingDock.querySelector('.livepro-product-dock__track')
    const wasCollapsed = existingDock.classList.contains('livepro-product-dock--collapsed')

    if (!track || wasCollapsed !== state.dockCollapsed) {
      replaceContent(dockHost, renderProductDock(product))
      return
    }

    const activeKey = String(product.key)
    const latestKey = getLiveActiveProductKey()
    const items = state.dockCollapsed
      ? [getLiveProductByKey(activeKey)].filter((i) => i && i.key)
      : getDockItems()

    const existingSlideKeys = []
    track.querySelectorAll('.livepro-product-dock__slide').forEach((s) => {
      existingSlideKeys.push(s.dataset.productKey)
    })
    const expectedKeys = items.map((i) => String(i.key))

    const sameSlides = expectedKeys.length === existingSlideKeys.length
      && expectedKeys.every((k, i) => k === existingSlideKeys[i])

    if (!sameSlides) {
      replaceContent(track, items.map((item) => renderProductDockSlide(item, activeKey, latestKey)).join(''))
      existingDock.className = `livepro-product-dock livepro-product-dock--count-${items.length}${state.dockCollapsed ? ' livepro-product-dock--collapsed' : ''}`
      return
    }

    track.querySelectorAll('.livepro-product-dock__slide').forEach((slide) => {
      const key = slide.dataset.productKey
      const isActive = key === activeKey

      slide.classList.toggle('is-active', isActive)
      slide.classList.toggle('is-inactive', !isActive)
      slide.dataset.active = isActive ? '1' : '0'

      const card = slide.querySelector('.livepro-product-dock__card')
      if (card) {
        card.classList.toggle('livepro-product-dock__card--active', isActive)
        card.classList.toggle('livepro-product-dock__card--peek', !isActive)
      }
    })
  }

  function updateExpandedShell() {
    if (!state.expanded) {
      return false
    }

    const shell = root.querySelector('.livepro-shell')
    if (!shell) {
      return false
    }

    const liveProduct = getLiveProductViewModel()
    const product = getDisplayedProductViewModel(liveProduct)
    const viewers = getViewerLabel()
    const statusHost = shell.querySelector('.livepro-shell__status')
    const nextSignature = productSignature(product.raw)
    const productChanged = nextSignature !== state.lastProductSignature
    const existingSheet = shell.querySelector('.livepro-product-sheet')
    const sheetVisible = state.productSheetOpen || state.productSheetClosing

    syncProductState(product)

    if (statusHost) {
      replaceContent(statusHost, renderStatusPills(viewers, 'panel'))
    }

    patchDock(product)

    if (!sheetVisible && existingSheet) {
      existingSheet.remove()
    }

    state.lastProductSignature = nextSignature
    bindExpandedInteractions(product)
    syncDockTrackPosition(false)

    return true
  }

  function getLiveProductViewModel() {
    const rawProduct = getLiveActiveProduct()
    return getProductViewModel(getResolvedProductSource(rawProduct))
  }

  function getLiveItems() {
    return Array.isArray(state.liveItems) ? state.liveItems : []
  }

  function getDockItems() {
    return getLiveItems().slice().reverse()
  }

  function getLiveActiveProductKey() {
    const activeKey = state.live && state.live.active_item_key ? String(state.live.active_item_key) : ''
    if (activeKey) {
      return activeKey
    }

    const items = getLiveItems()
    return items.length > 0 ? String(items[items.length - 1].key || '') : ''
  }

  function getLiveActiveProduct() {
    return getLiveProductByKey(getLiveActiveProductKey())
  }

  function getDisplayedLiveProduct() {
    const displayedKey = state.activeProductKey ? String(state.activeProductKey) : ''
    if (displayedKey) {
      const displayed = getLiveProductByKey(displayedKey)
      if (displayed && displayed.key) {
        return displayed
      }
    }

    return getLiveActiveProduct()
  }

  function getLiveProductByKey(key) {
    const safeKey = String(key || '')
    if (!safeKey) {
      return {}
    }

    return getLiveItems().find((item) => String(item && item.key ? item.key : '') === safeKey) || {}
  }

  function getDisplayedProductIndex() {
    const items = getDockItems()
    const displayedKey = getDisplayedLiveProduct().key
    if (!displayedKey) {
      return -1
    }

    return items.findIndex((item) => String(item && item.key ? item.key : '') === String(displayedKey))
  }

  function setDisplayedProductByKey(key) {
    const target = getLiveProductByKey(key)
    if (!target || !target.key) {
      return
    }

    const targetKey = String(target.key)
    if (targetKey === state.activeProductKey) {
      return
    }

    if (state.dockTransitioning) {
      state.pendingProductKey = targetKey
      return
    }

    if (!state.expanded || !animateDockToProductKey(targetKey)) {
      applyDisplayedProductKey(targetKey)
      if (!updateExpandedShell()) {
        render()
      }
    }
  }

  function moveDisplayedProduct(step) {
    const items = getDockItems()
    if (items.length <= 1) {
      return
    }

    const currentIndex = getDisplayedProductIndex()
    if (currentIndex < 0) {
      return
    }

    const nextIndex = clamp(currentIndex + step, 0, items.length - 1)
    if (nextIndex === currentIndex) {
      return
    }

    setDisplayedProductByKey(String(items[nextIndex] && items[nextIndex].key ? items[nextIndex].key : ''))
  }

  function applyDisplayedProductKey(targetKey) {
    state.activeProductKey = String(targetKey || '')
    state.followLive = state.activeProductKey === getLiveActiveProductKey()
    state.activePhotoIndex = 0

    if (state.productSheetOpen || state.productSheetClosing) {
      const target = getResolvedProductSource(getLiveProductByKey(targetKey))
      state.sheetProductSnapshot = cloneProductSnapshot(target)
      resetSheetSelection(getProductViewModel(target))
    }
  }

  function animateDockToProductKey(targetKey) {
    const dock = root.querySelector('.livepro-product-dock')
    const viewport = root.querySelector('.livepro-product-dock__viewport')
    const track = root.querySelector('.livepro-product-dock__track')

    if (!dock || !viewport || !track) {
      return false
    }

    const targetSlide = track.querySelector(`.livepro-product-dock__slide[data-product-key="${escapeSelector(targetKey)}"]`)
    if (!targetSlide) {
      return false
    }

    state.dockTransitioning = true
    state.pendingProductKey = ''

    dock.classList.add('is-transitioning')
    syncDockTrackPosition(true, targetSlide)

    window.setTimeout(() => {
      dock.classList.remove('is-transitioning')
      state.dockTransitioning = false

      const queuedKey = state.pendingProductKey && state.pendingProductKey !== targetKey
        ? state.pendingProductKey
        : ''
      state.pendingProductKey = ''
      applyDisplayedProductKey(targetKey)

      if (!updateExpandedShell()) {
        render()
      }

      if (queuedKey) {
        window.requestAnimationFrame(() => {
          setDisplayedProductByKey(queuedKey)
        })
      }
    }, TRANSITION_DELTA_MS)

    return true
  }

  function syncDockTrackPosition(animate, targetSlide) {
    if (dockPositionRaf) {
      window.cancelAnimationFrame(dockPositionRaf)
      dockPositionRaf = 0
    }

    const viewport = root.querySelector('.livepro-product-dock__viewport')
    const track = root.querySelector('.livepro-product-dock__track')
    if (!viewport || !track) {
      return
    }

    if (state.dockCollapsed) {
      track.style.transition = animate ? `transform ${TRANSITION_DELTA_MS}ms ease` : 'none'
      track.style.transform = 'translate3d(0, 0, 0)'

      if (!animate) {
        dockPositionRaf = window.requestAnimationFrame(() => {
          track.style.transition = ''
          dockPositionRaf = 0
        })
      }
      return
    }

    const viewportWidth = viewport.clientWidth
    if (viewportWidth <= 0) {
      return
    }

    const trackStyles = window.getComputedStyle(track)
    const paddingLeft = Number.parseFloat(trackStyles.paddingLeft || '0') || 0
    const paddingRight = Number.parseFloat(trackStyles.paddingRight || '0') || 0
    const usableWidth = Math.max(0, viewportWidth - paddingLeft - paddingRight)
    const gap = viewportWidth <= 420 ? 8 : 10

    viewport.style.setProperty('--livepro-dock-gap', `${gap}px`)

    const activeSlide = targetSlide || track.querySelector(`.livepro-product-dock__slide[data-product-key="${escapeSelector(state.activeProductKey)}"]`) || track.querySelector('.livepro-product-dock__slide')
    if (!activeSlide) {
      return
    }

    const activeIndex = Array.prototype.indexOf.call(track.children, activeSlide)
    const lastIndex = Math.max(0, track.children.length - 1)
    const lastSlide = track.children[lastIndex] || activeSlide
    const slideWidth = activeSlide.offsetWidth
    const maxTranslate = 0
    const lastSlideRight = lastSlide.offsetLeft + lastSlide.offsetWidth + paddingRight
    const minTranslate = Math.min(0, viewportWidth - lastSlideRight)

    let desiredLeft = paddingLeft
    if (activeIndex === lastIndex && lastIndex > 0) {
      const desiredRight = viewportWidth - paddingRight
      const activeRight = activeSlide.offsetLeft + slideWidth
      const desiredTranslate = desiredRight - activeRight
      track.style.transition = animate ? `transform ${TRANSITION_DELTA_MS}ms ease` : 'none'
      track.style.transform = `translate3d(${clamp(desiredTranslate, minTranslate, maxTranslate)}px, 0, 0)`

      if (!animate) {
        dockPositionRaf = window.requestAnimationFrame(() => {
          track.style.transition = ''
          dockPositionRaf = 0
        })
      }
      return
    } else if (activeIndex > 0 && activeIndex < lastIndex) {
      desiredLeft = paddingLeft + Math.max(0, (usableWidth - slideWidth) / 2)
    }

    const slideLeft = activeSlide.offsetLeft
    const nextTranslate = clamp(desiredLeft - slideLeft, minTranslate, maxTranslate)

    track.style.transition = animate ? `transform ${TRANSITION_DELTA_MS}ms ease` : 'none'
    track.style.transform = `translate3d(${nextTranslate}px, 0, 0)`

    if (!animate) {
      dockPositionRaf = window.requestAnimationFrame(() => {
        track.style.transition = ''
        dockPositionRaf = 0
      })
    }
  }

  function getDisplayedProductViewModel(liveProduct) {
    if (state.sheetProductSnapshot && (state.productSheetOpen || state.productSheetClosing)) {
      return getProductViewModel(state.sheetProductSnapshot)
    }

    const displayedRawProduct = getDisplayedLiveProduct()
    if (displayedRawProduct && displayedRawProduct.key) {
      return getProductViewModel(getResolvedProductSource(displayedRawProduct))
    }

    return liveProduct || getLiveProductViewModel()
  }

  function renderProductSheetInShell(product) {
    const shell = root.querySelector('.livepro-shell')
    if (!shell) {
      return false
    }

    const existingSheet = shell.querySelector('.livepro-product-sheet')
    const existingSheetContent = existingSheet ? existingSheet.querySelector('.livepro-product-sheet__content') : null
    const previousScrollTop = existingSheetContent ? existingSheetContent.scrollTop : 0
    const hadOpenClass = existingSheet ? existingSheet.classList.contains('is-open') : false

    if (!product.key) {
      if (existingSheet) {
        existingSheet.remove()
      }
      return true
    }

    if (existingSheet) {
      replaceNode(existingSheet, renderProductSheet(product))
    } else {
      appendContent(shell, renderProductSheet(product))
    }

    if (hadOpenClass) {
      const nextSheet = shell.querySelector('.livepro-product-sheet')
      if (nextSheet && !nextSheet.classList.contains('is-open')) {
        nextSheet.classList.add('is-open')
      }
    }

    const nextSheetContent = shell.querySelector('.livepro-product-sheet__content')
    if (nextSheetContent) {
      nextSheetContent.scrollTop = previousScrollTop
    }

    bindExpandedInteractions(product)
    return true
  }

  function cloneProductSnapshot(product) {
    try {
      return JSON.parse(JSON.stringify(product || {}))
    } catch (_error) {
      return Object.assign({}, product || {})
    }
  }

  async function pollLive() {
    try {
      const prevVideoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : null

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

      const nextVideoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : null
      if (prevVideoId !== nextVideoId) {
        state.isPaused = !widgetCfg.autoplay
      }
      ensureRelevantProductFallbacks()
      const autoOpenedShell = maybeAutoOpenShell()

      if (state.dockTransitioning) {
        return
      }

      if (autoOpenedShell) {
        flushPendingIncomingProductFocus()
        return
      }

      if (state.expanded && prevVideoId && nextVideoId && prevVideoId === nextVideoId && state.mountedVideoId === nextVideoId) {
        updateExpandedShell()
      } else {
        render()
      }

      flushPendingIncomingProductFocus()
    } catch (_error) {
      state.live = null
      state.liveItems = []
      state.liveRevision = 0
      state.liveSessionKey = ''
      state.followLive = true
      state.dockCollapsed = false
      state.dockTransitioning = false
      state.pendingProductKey = ''
      state.pendingIncomingProductKey = ''
      state.isPaused = !widgetCfg.autoplay
      render()
    }
  }

  async function fetchLiveSnapshot() {
    const response = await fetch(`${cfg.backofficeUrl.replace(/\/$/, '')}/api/v1/live/public/${storeId}/snapshot`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    })

    const body = await response.json()
    if (!response.ok) {
      throw new Error(body.error || 'No se pudo obtener snapshot live')
    }

    return body
  }

  async function fetchLiveDelta() {
    const response = await fetch(`${cfg.backofficeUrl.replace(/\/$/, '')}/api/v1/live/public/${storeId}/delta`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        live_session_key: state.liveSessionKey,
        since_revision: state.liveRevision,
      }),
    })

    const body = await response.json()
    if (!response.ok) {
      throw new Error(body.error || 'No se pudo obtener delta live')
    }

    return body
  }

  function applyLiveSnapshot(snapshot) {
    const normalized = normalizeLivePayload(snapshot)
    const previousActiveKey = getLiveActiveProductKey()

    state.live = normalized.live
    state.liveItems = normalized.items
    state.liveRevision = normalized.revision
    state.liveSessionKey = normalized.sessionKey

    if (!normalized.live.is_live) {
      state.followLive = true
      state.activeProductKey = ''
      state.sheetProductSnapshot = null
      return
    }

    const nextActiveKey = normalized.live.active_item_key || ''
    const shouldFollow = state.followLive || !state.activeProductKey || !hasLiveItem(state.activeProductKey) || previousActiveKey !== nextActiveKey

    if (isProductSheetVisible()) {
      return
    }

    if (shouldFollow) {
      if (state.dockTransitioning && state.expanded) {
        state.pendingProductKey = nextActiveKey
        return
      }

      state.activeProductKey = nextActiveKey
      state.followLive = true
    }
  }

  function applyLiveDelta(delta) {
    const normalized = normalizeLivePayload(delta)
    const previousItemKeys = new Set(getLiveItems().map((item) => String(item && item.key ? item.key : '')))

    if (!normalized.live.is_live) {
      state.live = normalized.live
      state.liveItems = []
      state.liveRevision = 0
      state.liveSessionKey = ''
      state.followLive = true
      state.dockCollapsed = false
      state.activeProductKey = ''
      state.pendingIncomingProductKey = ''
      state.sheetProductSnapshot = null
      return
    }

    state.live = normalized.live
    state.liveRevision = normalized.revision
    state.liveSessionKey = normalized.sessionKey
    state.liveItems = normalized.items.length === 0 && !normalized.live.active_item_key && !normalized.product
      ? []
      : mergeLiveItems(state.liveItems, normalized.items)

    const newestIncomingKey = normalized.items
      .filter((item) => item && item.key && !previousItemKeys.has(String(item.key)))
      .map((item) => String(item.key))
      .pop() || ''

    if (newestIncomingKey) {
      if (isWidgetPanelOpen()) {
        state.pendingIncomingProductKey = ''
        return
      }

      state.followLive = true

      if (state.expanded) {
        state.pendingIncomingProductKey = newestIncomingKey
        return
      }

      state.activeProductKey = newestIncomingKey
      return
    }

    const nextActiveKey = normalized.live.active_item_key || ''
    if (isWidgetPanelOpen()) {
      return
    }

    if (state.followLive || !state.activeProductKey || !hasLiveItem(state.activeProductKey)) {
      if (state.dockTransitioning && state.expanded) {
        state.pendingProductKey = nextActiveKey
        return
      }

      state.activeProductKey = nextActiveKey
      state.followLive = true
    }
  }

  function normalizeLivePayload(payload) {
    const live = {
      is_live: Boolean(payload && payload.is_live),
      live_session_id: payload && payload.live_session_id ? Number(payload.live_session_id) : null,
      active_item_key: payload && payload.active_item_key ? String(payload.active_item_key) : '',
      youtube_url: payload && payload.youtube_url ? String(payload.youtube_url) : '',
      youtube_video_id: payload && payload.youtube_video_id ? String(payload.youtube_video_id) : '',
      poll_interval_ms: payload && payload.poll_interval_ms ? Number(payload.poll_interval_ms) : Number(cfg.pollMs || 5000),
    }

    const rawItems = Array.isArray(payload && payload.items) ? payload.items : (Array.isArray(payload && payload.events) ? payload.events : [])
    const items = rawItems
      .map(normalizeLiveItem)
      .filter((item) => item && item.key)
      .sort((left, right) => Number(left.last_revision || 0) - Number(right.last_revision || 0))

    return {
      live,
      items,
      product: normalizeLiveItem(payload && payload.product),
      revision: payload && payload.revision ? Number(payload.revision) : 0,
      sessionKey: payload && payload.live_session_key ? String(payload.live_session_key) : '',
    }
  }

  function normalizeLiveItem(item) {
    if (!item || Number(item.product_id || 0) <= 0) {
      return null
    }

    return {
      key: String(item.key || `${Number(item.product_id || 0)}:${Number(item.variation_id || 0)}`),
      product_id: Number(item.product_id || 0),
      variation_id: Number(item.variation_id || 0) || null,
      name: String(item.name || item.product_name || ''),
      price: String(item.price || ''),
      image: String(item.image || item.image_url || ''),
      times_emitted: Math.max(1, Number(item.times_emitted || 1)),
      last_revision: Number(item.last_revision || 0),
      last_emitted_at: String(item.last_emitted_at || item.created_at || ''),
    }
  }

  function mergeLiveItems(existingItems, incomingItems) {
    const byKey = {}

    ;(Array.isArray(existingItems) ? existingItems : []).forEach((item) => {
      if (!item || !item.key) {
        return
      }

      byKey[String(item.key)] = Object.assign({}, item)
    })

    ;(Array.isArray(incomingItems) ? incomingItems : []).forEach((item) => {
      if (!item || !item.key) {
        return
      }

      byKey[String(item.key)] = Object.assign({}, byKey[String(item.key)] || {}, item)
    })

    return Object.values(byKey).sort((left, right) => Number(left.last_revision || 0) - Number(right.last_revision || 0))
  }

  function flushPendingIncomingProductFocus() {
    if (!state.pendingIncomingProductKey) {
      return
    }

    if (!state.expanded || isWidgetPanelOpen()) {
      return
    }

    const targetKey = String(state.pendingIncomingProductKey || '')
    state.pendingIncomingProductKey = ''

    if (!targetKey) {
      return
    }

    setDisplayedProductByKey(targetKey)
  }

  function isProductSheetVisible() {
    return state.productSheetOpen || state.productSheetOpening || state.productSheetClosing
  }

  function isWidgetPanelOpen() {
    return isProductSheetVisible()
      || state.cartViewOpen || state.cartViewOpening || state.cartViewClosing
      || state.cartConfirmationVisible
  }

  function hasLiveItem(key) {
    return Boolean(getLiveProductByKey(key).key)
  }

  function ensureRelevantProductFallbacks() {
    const liveProduct = getLiveActiveProduct()
    const displayedProduct = getDisplayedLiveProduct()
    const items = getLiveItems()
    const displayedIndex = getDisplayedProductIndex()

    ensureProductFallback(liveProduct)
    if (displayedProduct && displayedProduct.key !== liveProduct.key) {
      ensureProductFallback(displayedProduct)
    }

    if (displayedIndex > 0) {
      ensureProductFallback(items[displayedIndex - 1])
    }

    if (displayedIndex >= 0 && displayedIndex < items.length - 1) {
      ensureProductFallback(items[displayedIndex + 1])
    }
  }

  function applyLayoutConfig() {
    root.style.setProperty('--livepro-width-desktop', `${widgetCfg.widthDesktop}px`)
    root.style.setProperty('--livepro-width-mobile', widgetCfg.widthMobile ? `${widgetCfg.widthMobile}px` : 'calc(100vw - 24px)')
    root.style.setProperty('--livepro-offset-x', `${widgetCfg.offset.x}px`)
    root.style.setProperty('--livepro-offset-y', `${widgetCfg.offset.y}px`)
    root.style.setProperty('--livepro-product-price-color', widgetCfg.colors.productPrice)
    root.style.setProperty('--livepro-product-tag-text-color', widgetCfg.colors.productTagText)
    root.style.setProperty('--livepro-product-tag-background-color', widgetCfg.colors.productTagBackground)
    root.style.setProperty('--livepro-product-cta-background-color', widgetCfg.colors.productCtaBackground)
    root.style.setProperty('--livepro-product-cta-text-color', widgetCfg.colors.productCtaText)

    root.dataset.orientation = widgetCfg.orientation
    root.dataset.mobilePresentation = widgetCfg.mobilePresentationMode
    root.dataset.vertical = widgetCfg.position.vertical
    root.dataset.horizontal = widgetCfg.position.horizontal
  }

  function syncProductState(product) {
    if (!product.key) {
      state.productSheetOpen = false
      state.productSheetClosing = false
      clearSheetSelection()
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

    const variationMatrix = readVariationMatrix(product.variation_matrix, gallery)

    const colors = dedupeList([
      ...(readTextList(product.colors)),
      ...(readTextList(product.color_options)),
      ...(readAttributeValues(product.attributes, ['color', 'colour', 'colores', 'colores'])),
      ...variationMatrix.map((variation) => variation.color).filter(Boolean),
    ])

    const sizes = dedupeList([
      ...(readTextList(product.sizes)),
      ...(readTextList(product.talles)),
      ...(readTextList(product.size_options)),
      ...(readAttributeValues(product.attributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño'])),
      ...variationMatrix.map((variation) => variation.size).filter(Boolean),
    ])

    const key = String(product.key || [
      String(product.product_id || ''),
      String(product.variation_id || ''),
      String(product.name || ''),
    ].join('|'))

    return {
      key: key === '||' ? '' : key,
      name: String(product.name || 'Producto destacado'),
      price: formatPrice(String(product.price || '')),
      stockLabel: getStockLabel(product),
      primaryImage: gallery[0] || '',
      gallery: gallery.length > 0 ? gallery : [''],
      colors,
      sizes,
      variationMatrix,
      raw: product,
    }
  }

  function normalizeWidgetConfig(config) {
    return {
      widthDesktop: Number(config.widthDesktop || 392),
      widthMobile: config.widthMobile ? Number(config.widthMobile) : null,
      mobilePresentationMode: config.mobilePresentationMode === 'immersive' ? 'immersive' : 'floating',
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
        productTag: String(config.labels && config.labels.productTag ? config.labels.productTag : 'DESTACADO'),
        liveBadge: String(config.labels && config.labels.liveBadge ? config.labels.liveBadge : 'VIVO'),
        cartCta: String(config.labels && config.labels.cartCta ? config.labels.cartCta : 'AGREGAR AL CARRITO'),
        continueBtn: String(config.labels && config.labels.continueBtn ? config.labels.continueBtn : 'SEGUIR VIENDO'),
        checkoutBtn: String(config.labels && config.labels.checkoutBtn ? config.labels.checkoutBtn : 'TERMINAR COMPRA'),
      },
      colors: {
        productPrice: String(config.colors && config.colors.productPrice ? config.colors.productPrice : '#4f4bf0'),
        productTagText: String(config.colors && config.colors.productTagText ? config.colors.productTagText : '#8b9bbb'),
        productTagBackground: String(config.colors && config.colors.productTagBackground ? config.colors.productTagBackground : '#eef2f8'),
        productCtaBackground: String(config.colors && config.colors.productCtaBackground ? config.colors.productCtaBackground : '#4f4bf0'),
        productCtaText: String(config.colors && config.colors.productCtaText ? config.colors.productCtaText : '#ffffff'),
        cartCtaBackground: String(config.colors && config.colors.cartCtaBackground ? config.colors.cartCtaBackground : '#000000'),
        cartCtaText: String(config.colors && config.colors.cartCtaText ? config.colors.cartCtaText : '#ffffff'),
        cartIconBackground: String(config.colors && config.colors.cartIconBackground ? config.colors.cartIconBackground : '#ffffff'),
        cartIconColor: String(config.colors && config.colors.cartIconColor ? config.colors.cartIconColor : '#1e2d49'),
        continueBtnBackground: String(config.colors && config.colors.continueBtnBackground ? config.colors.continueBtnBackground : '#ffffff'),
        continueBtnText: String(config.colors && config.colors.continueBtnText ? config.colors.continueBtnText : '#1e2d49'),
        checkoutBtnBackground: String(config.colors && config.colors.checkoutBtnBackground ? config.colors.checkoutBtnBackground : '#1e2d49'),
        checkoutBtnText: String(config.colors && config.colors.checkoutBtnText ? config.colors.checkoutBtnText : '#ffffff'),
      },
      indicators: {
        showLiveBadge: config.indicators ? config.indicators.showLiveBadge !== false : true,
        showViewers: config.indicators ? config.indicators.showViewers !== false : true,
      },
      productDataStrategy: String(config.productDataStrategy || 'livepro_with_fallback'),
    }
  }

  function formatPrice(price) {
    const value = String(price || '').replace(/\s+/g, ' ').trim()

    if (value === '') {
      return ''
    }

    const currencyMatches = value.match(/[$€£¥]\s*\d[\d.,]*/g)
    if (currencyMatches && currencyMatches.length > 0) {
      return currencyMatches[currencyMatches.length - 1].trim()
    }

    const amountMatches = value.match(/\d[\d.,]*/g)
    if (amountMatches && amountMatches.length > 0) {
      return `$${amountMatches[amountMatches.length - 1]}`
    }

    if (value.includes('$')) {
      return value
    }

    return `$${value}`
  }

  function ensureProductFallback(product) {
    if (widgetCfg.productDataStrategy !== 'livepro_with_fallback') {
      return
    }

    const productId = Number(product && product.product_id ? product.product_id : 0)
    if (!productId || !cfg.rest || !cfg.rest.productViewUrl) {
      return
    }

    if (!needsFallbackData(product)) {
      return
    }

    const cacheKey = getProductCacheKey(product)
    if (!cacheKey || state.fallbackStatus[cacheKey] === 'pending' || state.fallbackStatus[cacheKey] === 'done' || state.fallbackStatus[cacheKey] === 'error') {
      return
    }

    state.fallbackStatus[cacheKey] = 'pending'

    const url = new URL(String(cfg.rest.productViewUrl), window.location.origin)
    url.searchParams.set('product_id', String(productId))

    const variationId = Number(product && product.variation_id ? product.variation_id : 0)
    if (variationId > 0) {
      url.searchParams.set('variation_id', String(variationId))
    }

    fetch(url.toString(), {
      method: 'GET',
      headers: { 'Content-Type': 'application/json' },
    })
      .then(async (response) => {
        const body = await response.json()
        if (!response.ok || !body.ok || !body.product) {
          throw new Error(body && body.error ? body.error : 'No se pudo resolver producto')
        }

        state.fallbackCache[cacheKey] = body.product
        state.fallbackStatus[cacheKey] = 'done'

        if (state.live && state.expanded) {
          updateExpandedShell()
        }
      })
      .catch(() => {
        state.fallbackStatus[cacheKey] = 'error'
      })
  }

  function getResolvedProductSource(product) {
    const fallback = state.fallbackCache[getProductCacheKey(product)]

    if (!fallback) {
      return product || {}
    }

    return mergeProductData(product || {}, fallback)
  }

  function mergeProductData(primary, fallback) {
    const merged = Object.assign({}, fallback, primary)

    merged.image = primary.image || fallback.image || ''
    merged.gallery = dedupeList([
      ...readImageList(primary.gallery),
      ...readImageList(primary.images),
      ...readImageList(primary.photos),
      ...(primary.image ? [primary.image] : []),
      ...readImageList(fallback.gallery),
      ...readImageList(fallback.images),
      ...readImageList(fallback.photos),
      ...(fallback.image ? [fallback.image] : []),
    ])

    merged.colors = dedupeList([
      ...readTextList(primary.colors),
      ...readTextList(primary.color_options),
      ...readTextList(fallback.colors),
      ...readTextList(fallback.color_options),
    ])

    merged.sizes = dedupeList([
      ...readTextList(primary.sizes),
      ...readTextList(primary.talles),
      ...readTextList(primary.size_options),
      ...readTextList(fallback.sizes),
      ...readTextList(fallback.talles),
      ...readTextList(fallback.size_options),
    ])

    merged.color_option_details = Array.isArray(primary.color_option_details) && primary.color_option_details.length > 0
      ? primary.color_option_details
      : (Array.isArray(fallback.color_option_details) ? fallback.color_option_details : [])

    merged.variation_matrix = Array.isArray(primary.variation_matrix) && primary.variation_matrix.length > 0
      ? primary.variation_matrix
      : (Array.isArray(fallback.variation_matrix) ? fallback.variation_matrix : [])

    return merged
  }

  function needsFallbackData(product) {
    if (!product || Number(product.product_id || 0) <= 0) {
      return false
    }

    const gallery = dedupeList([
      product.image,
      ...readImageList(product.gallery),
      ...readImageList(product.images),
      ...readImageList(product.photos),
    ])

    const colors = dedupeList([
      ...readTextList(product.colors),
      ...readTextList(product.color_options),
      ...readAttributeValues(product.attributes, ['color', 'colour', 'colores']),
    ])

    const sizes = dedupeList([
      ...readTextList(product.sizes),
      ...readTextList(product.talles),
      ...readTextList(product.size_options),
      ...readAttributeValues(product.attributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño']),
    ])

    const variationMatrix = readVariationMatrix(product.variation_matrix, gallery)
    const hasVariantOptions = colors.length > 0
      || sizes.length > 0
      || variationMatrix.some((variation) => String(variation && (variation.color || variation.size) ? (variation.color || variation.size) : '').trim() !== '')

    return gallery.length <= 1 || (hasVariantOptions && variationMatrix.length === 0)
  }

  function getProductCacheKey(product) {
    if (!product) {
      return ''
    }

    const productId = Number(product.product_id || 0)
    if (!productId) {
      return ''
    }

    const variationId = Number(product.variation_id || 0)
    return `${productId}:${variationId}`
  }

  function getSheetProduct() {
    const liveProduct = getLiveProductViewModel()
    return getDisplayedProductViewModel(liveProduct)
  }

  async function fetchCart() {
    if (!cfg.rest || !cfg.rest.cartGetUrl) {
      return
    }

    state.cartLoading = true
    state.cartError = null

    try {
      const response = await fetch(cfg.rest.cartGetUrl, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cfg.nonce || '' },
        credentials: 'same-origin',
      })
      const body = await response.json()
      if (!response.ok || !body.ok) {
        throw new Error(body.error || 'Error al obtener carrito')
      }
      state.cart = body.cart
    } catch (err) {
      state.cartError = err.message
    } finally {
      state.cartLoading = false
    }
  }

  async function addToCart(productId, variationId, quantity) {
    if (!cfg.rest || !cfg.rest.cartAddUrl) {
      return
    }

    state.addToCartLoading = true
    renderProductSheetInShell(getSheetProduct())

    try {
      const response = await fetch(cfg.rest.cartAddUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || '',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          product_id: productId,
          variation_id: variationId || 0,
          quantity: quantity || 1,
        }),
      })
      const body = await response.json()
      if (!response.ok || !body.ok) {
        throw new Error(body.error || 'Error al agregar al carrito')
      }
      state.cart = body.cart
      state.cartConfirmationItem = body.added_item
      state.addToCartLoading = false
      closeProductSheet()
      state.cartConfirmationVisible = true
      renderCartConfirmationInShell()
      syncCartBadge()
      return
    } catch (_err) {
    } finally {
      state.addToCartLoading = false
      renderProductSheetInShell(getSheetProduct())
      syncCartBadge()
    }
  }

  async function updateCartItem(cartItemKey, quantity) {
    if (!cfg.rest || !cfg.rest.cartUpdateUrl) {
      return
    }

    state.cartLoading = true

    try {
      const response = await fetch(cfg.rest.cartUpdateUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || '',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          cart_item_key: cartItemKey,
          quantity: quantity,
        }),
      })
      const body = await response.json()
      if (!response.ok || !body.ok) {
        throw new Error(body.error || 'Error al actualizar carrito')
      }
      state.cart = body.cart
    } catch (err) {
      state.cartError = err.message
    } finally {
      state.cartLoading = false
      renderCartViewInShell()
      syncCartBadge()
    }
  }

  async function removeCartItem(cartItemKey) {
    if (!cfg.rest || !cfg.rest.cartRemoveUrl) {
      return
    }

    state.cartLoading = true

    try {
      const response = await fetch(cfg.rest.cartRemoveUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || '',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          cart_item_key: cartItemKey,
        }),
      })
      const body = await response.json()
      if (!response.ok || !body.ok) {
        throw new Error(body.error || 'Error al eliminar del carrito')
      }
      state.cart = body.cart
    } catch (err) {
      state.cartError = err.message
    } finally {
      state.cartLoading = false
      renderCartViewInShell()
      syncCartBadge()
    }
  }

  function syncCartBadge() {
    const count = state.cart ? state.cart.item_count : 0
    const headerBadge = root.querySelector('.livepro-cart-badge__count')
    if (headerBadge) {
      headerBadge.textContent = String(count)
      const badge = headerBadge.closest('.livepro-cart-badge')
      if (badge) {
        badge.classList.toggle('is-hidden', count === 0)
      }
    }

    if (typeof window.jQuery !== 'undefined') {
      window.jQuery(document.body).trigger('wc_fragment_refresh')
    }
  }

  function buildEmbedUrl(videoId, autoplay, muted) {
    const params = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      mute: muted ? '1' : '0',
      enablejsapi: '1',
      origin: window.location.origin,
      playsinline: '1',
      rel: '0',
      controls: '0',
      disablekb: '1',
      fs: '0',
      iv_load_policy: '3',
      modestbranding: '1',
    })

    return `https://www.youtube.com/embed/${encodeURIComponent(String(videoId || ''))}?${params.toString()}`
  }

  function readVariationMatrix(value, gallery) {
    if (!Array.isArray(value)) {
      return []
    }

    return value
      .map((item) => {
        if (!item || typeof item !== 'object') {
          return null
        }

        const variationId = Number(item.variation_id || 0)
        const color = String(item.color || '').trim()
        const size = String(item.size || '').trim()
        const image = String(item.image || '').trim()
        const price = formatPrice(String(item.price || '').trim())
        const fallbackIndex = Number.isFinite(Number(item.image_gallery_index))
          ? clamp(Number(item.image_gallery_index), 0, Math.max(0, gallery.length - 1))
          : 0
        const galleryIndex = image ? findGalleryImageIndex(gallery, image) : fallbackIndex

        return {
          variationId: variationId > 0 ? variationId : null,
          color,
          size,
          image,
          price,
          inStock: item.in_stock !== false,
          purchasable: item.purchasable !== false,
          available: item.in_stock !== false && item.purchasable !== false,
          galleryIndex,
        }
      })
      .filter(Boolean)
  }

  function findGalleryImageIndex(gallery, image) {
    const needle = String(image || '').trim()
    if (!needle) {
      return 0
    }

    const galleryIndex = gallery.findIndex((candidate) => String(candidate || '').trim() === needle)
    return galleryIndex >= 0 ? galleryIndex : 0
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

  function escapeSelector(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(String(value || ''))
    }

    return String(value || '').replace(/["\\]/g, '\\$&')
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
      JSON.stringify(product.variation_matrix || []),
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

  function renderPauseIcon(isPaused) {
    return isPaused
      ? `
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M9 7.8L16.8 12L9 16.2V7.8Z"></path>
        </svg>
      `
      : `
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M9 6.5V17.5"></path>
          <path d="M15 6.5V17.5"></path>
        </svg>
      `
  }

  function getPauseButtonLabel() {
    return state.isPaused ? 'Reanudar video' : 'Pausar video'
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

  function getPlayerFrame() {
    return root.querySelector('.livepro-shell__iframe')
  }

  function sendPlayerCommand(command, args) {
    const frame = getPlayerFrame()
    if (!frame || !frame.contentWindow) {
      return false
    }

    frame.contentWindow.postMessage(JSON.stringify({
      event: 'command',
      func: command,
      args: Array.isArray(args) ? args : [],
    }), 'https://www.youtube.com')

    return true
  }

  function syncPlayerStateToFrame() {
    sendPlayerCommand(state.isMuted ? 'mute' : 'unMute')
    sendPlayerCommand(state.isPaused ? 'pauseVideo' : 'playVideo')
    syncPlayerActionButtons()
  }

  function registerPlayerBridge() {
    const frame = getPlayerFrame()
    if (!frame || !frame.contentWindow) {
      return
    }

    frame.contentWindow.postMessage(JSON.stringify({
      event: 'listening',
      id: `livepro-${state.mountedVideoId || 'player'}`,
      channel: 'livepro-widget',
    }), 'https://www.youtube.com')

    frame.contentWindow.postMessage(JSON.stringify({
      event: 'command',
      func: 'addEventListener',
      args: ['onStateChange'],
    }), 'https://www.youtube.com')
  }

  function syncPlayerActionButtons() {
    const pauseButton = root.querySelector('.livepro-pause')
    if (pauseButton) {
      replaceContent(pauseButton, renderPauseIcon(state.isPaused))
      pauseButton.setAttribute('aria-label', getPauseButtonLabel())
    }

    const muteButton = root.querySelector('.livepro-mute')
    if (muteButton) {
      replaceContent(muteButton, renderVolumeIcon(state.isMuted))
      muteButton.setAttribute('aria-label', 'Silenciar o activar audio')
    }
  }

  function handlePlayerMessage(event) {
    if (!isYouTubeOrigin(event.origin)) {
      return
    }

    const frame = getPlayerFrame()
    if (!frame || event.source !== frame.contentWindow) {
      return
    }

    const payload = parsePlayerMessage(event.data)
    if (!payload || typeof payload !== 'object') {
      return
    }

    const nextMuted = readPlayerMuted(payload)
    if (typeof nextMuted === 'boolean') {
      state.isMuted = nextMuted
    }

    const nextPaused = readPlayerPaused(payload)
    if (typeof nextPaused === 'boolean') {
      state.isPaused = nextPaused
    }

    if (typeof nextMuted === 'boolean' || typeof nextPaused === 'boolean') {
      syncPlayerActionButtons()
    }
  }

  function parsePlayerMessage(value) {
    if (!value) {
      return null
    }

    if (typeof value === 'string') {
      try {
        return JSON.parse(value)
      } catch (_error) {
        return null
      }
    }

    return typeof value === 'object' ? value : null
  }

  function readPlayerMuted(payload) {
    if (payload.info && typeof payload.info.muted === 'boolean') {
      return payload.info.muted
    }

    return null
  }

  function readPlayerPaused(payload) {
    const playerState = payload.event === 'onStateChange' && typeof payload.info === 'number'
      ? payload.info
      : (payload.info && typeof payload.info.playerState === 'number' ? payload.info.playerState : null)

    if (playerState === 1 || playerState === 3) {
      return false
    }

    if (playerState === 0 || playerState === 2 || playerState === 5) {
      return true
    }

    return null
  }

  function isYouTubeOrigin(origin) {
    return origin === 'https://www.youtube.com' || origin === 'https://www.youtube-nocookie.com'
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

  function renderDockCloseIcon() {
    return `
      <svg viewBox="0 0 20 20" aria-hidden="true">
        <path d="M6 6L14 14"></path>
        <path d="M14 6L6 14"></path>
      </svg>
    `
  }

  function renderCartIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="9" cy="21" r="1"></circle>
        <circle cx="20" cy="21" r="1"></circle>
        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"></path>
      </svg>
    `
  }

  function renderSpinnerIcon() {
    return `
      <svg class="livepro-spinner" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4 31.4" stroke-linecap="round"></circle>
      </svg>
    `
  }

  function renderCheckIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="11" fill="#22c55e" stroke="none"></circle>
        <path d="M7 12.5L10.5 16L17 9" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
      </svg>
    `
  }

  function renderTrashIcon() {
    return `
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <polyline points="3 6 5 6 21 6"></polyline>
        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"></path>
        <path d="M10 11v6"></path>
        <path d="M14 11v6"></path>
        <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"></path>
      </svg>
    `
  }

  pollLive()
  window.addEventListener('message', handlePlayerMessage)
  window.addEventListener('resize', () => {
    if (state.expanded) {
      syncDockTrackPosition(false)
    }
  })
  setInterval(pollLive, Number(cfg.pollMs || 5000))
})()
