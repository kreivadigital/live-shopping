(function () {
  if (!window.LiveProWidgetConfig) {
    return;
  }

  const cfg = window.LiveProWidgetConfig;
  const storeId = Number(cfg.storeId || 0);
  if (!storeId || !cfg.backofficeUrl) {
    return;
  }

  const state = {
    expanded: false,
    showForm: false,
    isMuted: true,
    live: null,
    widgetSessionId: getOrCreateSessionId(),
    mountedVideoId: null,
    lastProductSignature: '',
  };

  const root = document.createElement('div');
  root.id = 'livepro-widget-root';
  document.body.appendChild(root);

  function render() {
    if (!state.live || !state.live.is_live) {
      root.classList.remove('livepro-expanded');
      root.innerHTML = '';
      return;
    }

    if (!state.expanded) {
      root.classList.remove('livepro-expanded');
      const youtubePreview = state.live && state.live.youtube_video_id
        ? `https://i.ytimg.com/vi/${encodeURIComponent(String(state.live.youtube_video_id))}/hqdefault.jpg`
        : '';
      const previewImage = cfg.previewImageUrl || youtubePreview;
      const viewers = String(state.live.viewer_count || cfg.previewViewers || '').trim();
      root.innerHTML = `
        <button class="livepro-mini" type="button" aria-label="Abrir live shopping">
          ${previewImage ? `<img class="livepro-mini-bg" src="${escapeAttribute(previewImage)}" alt="Preview live">` : ''}
          <span class="livepro-mini-overlay"></span>
          <span class="livepro-mini-top">
            <span class="livepro-mini-badge">VIVO</span>
            ${viewers ? `<span class="livepro-mini-viewers"><span class="livepro-mini-viewers-icon">◌</span> ${escapeHtml(viewers)}</span>` : ''}
          </span>
          <span class="livepro-mini-play"><span>▶</span></span>
          <span class="livepro-mini-cta">VER AHORA</span>
        </button>
      `;
      root.querySelector('.livepro-mini').addEventListener('click', () => {
        state.expanded = true;
        render();
      });
      return;
    }

    root.classList.add('livepro-expanded');

    const product = state.live.product || {};
    const videoId = state.live.youtube_video_id || '';
    const viewers = String(state.live.viewer_count || cfg.previewViewers || '').trim();
    const hasActiveProduct = Number(product.product_id || 0) > 0;
    state.lastProductSignature = productSignature(product);
    state.mountedVideoId = videoId;

    root.innerHTML = `
      <div class="livepro-panel">
        <div class="livepro-head">
          <div class="livepro-head-left">
            <span class="livepro-badge"><span class="livepro-badge-dot"></span>VIVO</span>
            ${viewers ? `<span class="livepro-head-viewers"><span class="livepro-head-viewers-icon">◌</span>${escapeHtml(viewers)}</span>` : ''}
          </div>
          <div class="livepro-head-right">
            <button class="livepro-icon-btn livepro-mute" type="button" aria-label="Silenciar / activar audio">${state.isMuted ? '🔇' : '🔊'}</button>
            <button class="livepro-icon-btn livepro-close" type="button" aria-label="Cerrar">✕</button>
          </div>
        </div>
        <div class="livepro-video">
          <iframe
            src="https://www.youtube.com/embed/${escapeHtml(videoId)}?autoplay=1&mute=${state.isMuted ? '1' : '0'}"
            title="Live Shopping"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
          ></iframe>
        </div>
        ${hasActiveProduct ? `
          <div class="livepro-product">
            <div class="livepro-product-card">
              <img src="${escapeAttribute(product.image || '')}" alt="Producto" onerror="this.style.display='none'">
              <div class="livepro-product-content">
                <div class="livepro-product-top">
                  <span class="livepro-product-tag">DESTACADO</span>
                  <span class="livepro-product-stock"><span class="livepro-product-stock-icon">◌</span>${escapeHtml(getStockLabel(product))}</span>
                </div>
                <p class="livepro-product-title">${escapeHtml(product.name || 'Producto destacado')}</p>
                <p class="livepro-product-price">${escapeHtml(product.price || '')}</p>
                <button class="livepro-buy" type="button">
                  <span class="livepro-buy-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                      <path d="M6 7h13l-1.2 7.2a2 2 0 0 1-2 1.8H10a2 2 0 0 1-2-1.6L6 4H3"></path>
                      <circle cx="10" cy="20" r="1.6"></circle>
                      <circle cx="17" cy="20" r="1.6"></circle>
                    </svg>
                  </span>
                  COMPRAR AHORA
                </button>
              </div>
            </div>
            <form class="livepro-form ${state.showForm ? 'show' : ''}">
              <input name="customer_name" placeholder="Nombre y apellido *" required>
              <input name="phone" placeholder="Teléfono *" required>
              <input name="email" placeholder="Email">
              <input name="city" placeholder="Departamento / Ciudad">
              <textarea name="notes" placeholder="Notas"></textarea>
              <button class="livepro-submit" type="submit">Enviar pedido pendiente</button>
              <p class="livepro-message"></p>
            </form>
          </div>
        ` : ''}
      </div>
    `;

    root.querySelector('.livepro-close').addEventListener('click', () => {
      state.expanded = false;
      state.showForm = false;
      render();
    });

    const muteButton = root.querySelector('.livepro-mute');
    if (muteButton) {
      muteButton.addEventListener('click', () => {
        state.isMuted = !state.isMuted;
        muteButton.textContent = state.isMuted ? '🔇' : '🔊';
      });
    }

    const buyButton = root.querySelector('.livepro-buy');
    if (!buyButton) {
      return;
    }

    buyButton.addEventListener('click', () => {
      state.showForm = !state.showForm;
      const form = root.querySelector('.livepro-form');
      if (form) {
        form.classList.toggle('show', state.showForm);
      }
    });

    const form = root.querySelector('.livepro-form');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = form.querySelector('.livepro-message');
      message.className = 'livepro-message';
      message.textContent = '';

      const data = new FormData(form);
      const customerName = String(data.get('customer_name') || '').trim();
      const phone = String(data.get('phone') || '').trim();
      const activeProduct = state.live && state.live.product ? state.live.product : {};

      if (!customerName || !phone) {
        message.classList.add('error');
        message.textContent = 'Nombre y teléfono son obligatorios.';
        return;
      }

      const payload = {
        store_id: storeId,
        widget_session_id: state.widgetSessionId,
        live_session_id: state.live.live_session_id,
        customer_name: customerName,
        phone,
        email: String(data.get('email') || '').trim(),
        city: String(data.get('city') || '').trim(),
        notes: String(data.get('notes') || '').trim(),
        product_id: Number(activeProduct.product_id || 0),
        variation_id: activeProduct.variation_id ? Number(activeProduct.variation_id) : null,
        qty: 1,
      };

      if (!payload.product_id) {
        message.classList.add('error');
        message.textContent = 'No hay producto activo para comprar.';
        return;
      }

      message.textContent = 'Enviando...';

      try {
        const response = await fetch(`${cfg.backofficeUrl.replace(/\/$/, '')}/api/v1/intent/create-pending-order`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });

        const body = await response.json();
        if (!response.ok || !body.ok) {
          throw new Error(body.error || 'No se pudo crear el pedido.');
        }

        message.textContent = body.message || 'Pedido recibido, te contactaremos para finalizar la compra.';
        form.reset();
      } catch (error) {
        message.classList.add('error');
        message.textContent = error.message || 'Error creando pedido. Intenta nuevamente.';
      }
    });
  }

  function updateExpandedProductOnly() {
    if (!state.expanded) return false;
    const panel = root.querySelector('.livepro-panel');
    if (!panel) return false;

    const product = state.live && state.live.product ? state.live.product : {};
    const hasActiveProduct = Number(product.product_id || 0) > 0;
    const productBlock = panel.querySelector('.livepro-product');

    if (!hasActiveProduct) {
      if (productBlock) {
        productBlock.remove();
      }
      return true;
    }

    if (!productBlock) {
      return false;
    }

    const title = panel.querySelector('.livepro-product-title');
    const price = panel.querySelector('.livepro-product-price');
    const image = panel.querySelector('.livepro-product-card img');
    const stock = panel.querySelector('.livepro-product-stock');

    const nextSignature = productSignature(product);
    if (state.lastProductSignature !== '' && nextSignature !== state.lastProductSignature) {
      productBlock.classList.remove('product-fade-in');
      productBlock.classList.add('product-fade-out');

      window.setTimeout(() => {
        if (title) {
          title.textContent = product.name || 'Producto destacado';
        }
        if (price) {
          price.textContent = product.price || '';
        }
        if (stock) {
          stock.innerHTML = `<span class="livepro-product-stock-icon">◌</span>${escapeHtml(getStockLabel(product))}`;
        }
        if (image) {
          image.setAttribute('src', product.image || '');
          image.style.display = '';
        }
        productBlock.classList.remove('product-fade-out');
        productBlock.classList.add('product-fade-in');
      }, 150);
    } else {
      if (title) {
        title.textContent = product.name || 'Producto destacado';
      }
      if (price) {
        price.textContent = product.price || '';
      }
      if (stock) {
        stock.innerHTML = `<span class="livepro-product-stock-icon">◌</span>${escapeHtml(getStockLabel(product))}`;
      }
      if (image) {
        const nextSrc = product.image || '';
        if (image.getAttribute('src') !== nextSrc) {
          image.setAttribute('src', nextSrc);
          image.style.display = '';
        }
      }
    }

    state.lastProductSignature = nextSignature;

    return true;
  }

  async function pollLive() {
    try {
      const response = await fetch(`${cfg.backofficeUrl.replace(/\/$/, '')}/api/v1/live/public/${storeId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      });

      const body = await response.json();
      if (!response.ok) {
        throw new Error(body.error || 'No se pudo obtener estado live');
      }

      const prevVideoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : null;
      state.live = body;
      const nextVideoId = state.live && state.live.youtube_video_id ? state.live.youtube_video_id : null;

      // Keep playing stream stable: do not remount iframe while expanded unless the live video id changed.
      if (state.expanded && prevVideoId && nextVideoId && prevVideoId === nextVideoId && state.mountedVideoId === nextVideoId) {
        updateExpandedProductOnly();
      } else {
        render();
      }
    } catch (_error) {
      state.live = null;
      render();
    }
  }

  function getOrCreateSessionId() {
    const key = 'livepro_widget_session_id';
    const fromStorage = localStorage.getItem(key);
    if (fromStorage) {
      return fromStorage;
    }

    const generated = 'w_' + Math.random().toString(36).slice(2, 11);
    localStorage.setItem(key, generated);
    return generated;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function escapeAttribute(value) {
    return escapeHtml(value || '').replaceAll('`', '');
  }

  function productSignature(product) {
    if (!product) return '';
    return [
      String(product.product_id || ''),
      String(product.variation_id || ''),
      String(product.name || ''),
      String(product.price || ''),
      String(product.image || ''),
      String(product.stock || ''),
      String(product.stock_quantity || ''),
      String(product.available_qty || ''),
    ].join('|');
  }

  function getStockLabel(product) {
    const raw = product && (
      product.stock ??
      product.stock_quantity ??
      product.available_qty ??
      product.qty
    );
    const qty = Number(raw);
    if (Number.isFinite(qty) && qty > 0) {
      return `${Math.floor(qty)} disponibles`;
    }
    return 'Disponible';
  }

  pollLive();
  setInterval(pollLive, Number(cfg.pollMs || 5000));
})();
