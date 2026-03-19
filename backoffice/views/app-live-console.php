<?php include __DIR__ . '/app-layout-start.php'; ?>

<section class="panel live-console-panel" id="live-console-app">
  <div class="panel-head">
    <h1>En Vivo</h1>
    <span
      id="live-status-chip"
      class="chip <?= !empty($liveState['liveSession']['is_live']) ? 'on' : '' ?>"
    ><?= !empty($liveState['liveSession']['is_live']) ? 'STREAMING ONLINE' : 'STREAMING OFFLINE' ?></span>
  </div>

  <div id="live-console-feedback" class="flash live-feedback hidden" aria-live="polite"></div>

  <div class="live-grid-v2">
    <div class="live-col left-col">
      <div class="live-card">
        <div class="live-card-title">Catálogo tienda</div>
        <form method="get" action="/app/live" class="live-search-form" id="live-search-form">
          <input
            id="live-search-input"
            type="text"
            name="q"
            value="<?= e((string) ($liveState['query'] ?? '')) ?>"
            placeholder="Buscar producto..."
            autocomplete="off"
          >
        </form>

        <div class="live-catalog-list" id="live-catalog-list">
          <?php if (empty($liveState['catalog'])): ?>
            <div class="empty">No hay productos en stock para mostrar.</div>
          <?php else: ?>
            <?php foreach ($liveState['catalog'] as $item): ?>
              <form method="post" action="/app/live" class="catalog-item <?= !empty($item['selected']) ? 'selected' : '' ?>">
                <input type="hidden" name="action" value="select_product">
                <input type="hidden" name="product_id" value="<?= (int) ($item['product_id'] ?? 0) ?>">
                <input type="hidden" name="q" value="<?= e((string) ($liveState['query'] ?? '')) ?>">

                <button type="submit" class="catalog-item-btn">
                  <div class="catalog-thumb-wrap">
                    <?php if (!empty($item['image_url'])): ?>
                      <img src="<?= e((string) $item['image_url']) ?>" alt="img" class="catalog-thumb">
                    <?php else: ?>
                      <div class="catalog-thumb"></div>
                    <?php endif; ?>
                  </div>
                  <div class="catalog-meta">
                    <div class="catalog-name"><?= e((string) ($item['product_name'] ?? '')) ?></div>
                    <?php if (!empty($item['formatted_price'])): ?>
                      <div class="catalog-price"><?= e((string) $item['formatted_price']) ?></div>
                    <?php endif; ?>
                  </div>
                </button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="live-card launch-card-small">
        <div class="live-card-title">Lanzamiento activo</div>

        <div id="launch-product-state">
          <?php if (!empty($liveState['selectedProduct'])): ?>
            <div class="launch-product compact">
              <?php if (!empty($liveState['selectedProduct']['image_url'])): ?>
                <img src="<?= e((string) $liveState['selectedProduct']['image_url']) ?>" alt="img">
              <?php else: ?>
                <div class="launch-thumb"></div>
              <?php endif; ?>
              <div>
                <div class="launch-name small"><?= e((string) ($liveState['selectedProduct']['product_name'] ?? '')) ?></div>
                <?php if (!empty($liveState['selectedProduct']['formatted_price'])): ?>
                  <div class="launch-price small"><?= e((string) $liveState['selectedProduct']['formatted_price']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="launch-empty">Seleccione producto</div>
          <?php endif; ?>
        </div>

        <div class="launch-actions" id="launch-actions">
          <button
            type="button"
            class="launch-btn"
            data-live-action="launch_product"
            <?= !empty($liveState['selectedProduct']) ? '' : 'disabled' ?>
          >Poner en vivo</button>
          <?php if (!empty($liveState['selectedProduct'])): ?>
            <button type="button" class="secondary" data-live-action="clear_selected">Limpiar</button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="live-col">
      <div class="live-card queue-card">
        <div class="live-card-head">
          <div class="live-card-title">Cola de emisión</div>
          <div class="live-card-actions" id="queue-actions">
            <?php if (!empty($liveState['emissionQueue'])): ?>
              <button type="button" class="secondary" data-live-action="clear_emission_queue">Borrar historial del vivo</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="queue-list" id="queue-list">
          <?php if (empty($liveState['emissionQueue'])): ?>
            <div class="queue-placeholder">Aún no hay productos lanzados.</div>
          <?php else: ?>
            <?php foreach ($liveState['emissionQueue'] as $q): ?>
              <div class="queue-item">
                <?php if (!empty($q['image_url'])): ?>
                  <img src="<?= e((string) $q['image_url']) ?>" alt="img">
                <?php else: ?>
                  <div class="queue-thumb"></div>
                <?php endif; ?>
                <div class="queue-meta">
                  <div class="queue-name"><?= e((string) ($q['product_name'] ?? '')) ?></div>
                  <?php if (!empty($q['formatted_price'])): ?>
                    <div class="queue-price"><?= e((string) $q['formatted_price']) ?></div>
                  <?php endif; ?>
                </div>
                <div class="queue-time"><?= e((string) ($q['created_at'] ?? '')) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="live-col">
      <div class="live-card live-preview-card">
        <div class="live-card-title">Control de vivo</div>
        <div class="preview tiny">
          <?php if (!empty($liveState['liveSession']['youtube_video_id'])): ?>
            <iframe src="https://www.youtube.com/embed/<?= e((string) $liveState['liveSession']['youtube_video_id']) ?>" title="Vista previa live" allowfullscreen></iframe>
          <?php else: ?>
            <div class="preview-empty">Sin vista previa</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="live-card chat-card">
        <div class="live-card-title">Chat de espectadores</div>
        <div class="chat-body">
          <div class="chat-empty">Chat en preparación</div>
        </div>
        <div class="chat-input-wrap">
          <input type="text" placeholder="Escribir..." disabled>
          <button type="button" class="chat-send" disabled>➤</button>
        </div>
      </div>
    </div>
  </div>
</section>

<script id="live-console-state" type="application/json"><?= $liveStateJson ?></script>
<script>
  // Drives the live console UI using server-rendered state plus incremental AJAX updates.
  (function () {
    const root = document.getElementById('live-console-app');
    const stateNode = document.getElementById('live-console-state');
    const feedback = document.getElementById('live-console-feedback');
    const chip = document.getElementById('live-status-chip');
    const searchForm = document.getElementById('live-search-form');
    const searchInput = document.getElementById('live-search-input');
    const catalogList = document.getElementById('live-catalog-list');
    const launchState = document.getElementById('launch-product-state');
    const launchActions = document.getElementById('launch-actions');
    const queueActions = document.getElementById('queue-actions');
    const queueList = document.getElementById('queue-list');

    if (!root || !stateNode || !feedback || !chip || !searchForm || !searchInput || !catalogList || !launchState || !launchActions || !queueActions || !queueList) {
      return;
    }

    let state = {};

    try {
      state = JSON.parse(stateNode.textContent || '{}');
    } catch (_error) {
      state = {};
    }

    let searchTimer = 0;
    let pendingRequest = null;
    let busyCount = 0;

    // Escapes user-facing text before injecting it into HTML strings.
    function escapeHtml(value) {
      return String(value || '').replace(/[&<>"']/g, function (char) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;'
        }[char];
      });
    }

    // Renders a formatted price block when a value is available.
    function renderPrice(value, className) {
      if (!value) {
        return '';
      }
      return '<div class="' + className + '">' + escapeHtml(value) + '</div>';
    }

    // Rebuilds the product catalog list from the current live state.
    function renderCatalog() {
      if (!Array.isArray(state.catalog) || state.catalog.length === 0) {
        catalogList.innerHTML = '<div class="empty">No hay productos en stock para mostrar.</div>';
        return;
      }

      catalogList.innerHTML = state.catalog.map(function (item) {
        return '' +
          '<form method="post" action="/app/live" class="catalog-item ' + (item.selected ? 'selected' : '') + '">' +
            '<input type="hidden" name="action" value="select_product">' +
            '<input type="hidden" name="product_id" value="' + Number(item.product_id || 0) + '">' +
            '<input type="hidden" name="q" value="' + escapeHtml(state.query || '') + '">' +
            '<button type="submit" class="catalog-item-btn">' +
              '<div class="catalog-thumb-wrap">' +
                (item.image_url
                  ? '<img src="' + escapeHtml(item.image_url) + '" alt="img" class="catalog-thumb">'
                  : '<div class="catalog-thumb"></div>') +
              '</div>' +
              '<div class="catalog-meta">' +
                '<div class="catalog-name">' + escapeHtml(item.product_name || '') + '</div>' +
                renderPrice(item.formatted_price, 'catalog-price') +
              '</div>' +
            '</button>' +
          '</form>';
      }).join('');
    }

    // Rebuilds the selected launch product and its action buttons.
    function renderLaunch() {
      if (state.selectedProduct) {
        launchState.innerHTML = '' +
          '<div class="launch-product compact">' +
            (state.selectedProduct.image_url
              ? '<img src="' + escapeHtml(state.selectedProduct.image_url) + '" alt="img">'
              : '<div class="launch-thumb"></div>') +
            '<div>' +
              '<div class="launch-name small">' + escapeHtml(state.selectedProduct.product_name || '') + '</div>' +
              renderPrice(state.selectedProduct.formatted_price, 'launch-price small') +
            '</div>' +
          '</div>';
      } else {
        launchState.innerHTML = '<div class="launch-empty">Seleccione producto</div>';
      }

      launchActions.innerHTML = '' +
        '<button type="button" class="launch-btn" data-live-action="launch_product" ' + (state.selectedProduct ? '' : 'disabled') + '>Poner en vivo</button>' +
        (state.selectedProduct
          ? '<button type="button" class="secondary" data-live-action="clear_selected">Limpiar</button>'
          : '');
    }

    // Rebuilds the recent emission queue from the current live state.
    function renderQueue() {
      queueActions.innerHTML = Array.isArray(state.emissionQueue) && state.emissionQueue.length > 0
        ? '' +
          '<button type="button" class="secondary" data-live-action="clear_emission_queue">Borrar historial del vivo</button>'
        : '';

      if (!Array.isArray(state.emissionQueue) || state.emissionQueue.length === 0) {
        queueList.innerHTML = '<div class="queue-placeholder">Aún no hay productos lanzados.</div>';
        return;
      }

      queueList.innerHTML = state.emissionQueue.map(function (item) {
        return '' +
          '<div class="queue-item">' +
            (item.image_url
              ? '<img src="' + escapeHtml(item.image_url) + '" alt="img">'
              : '<div class="queue-thumb"></div>') +
            '<div class="queue-meta">' +
              '<div class="queue-name">' + escapeHtml(item.product_name || '') + '</div>' +
              renderPrice(item.formatted_price, 'queue-price') +
            '</div>' +
            '<div class="queue-time">' + escapeHtml(item.created_at || '') + '</div>' +
          '</div>';
      }).join('');
    }

    // Updates the live/offline status chip.
    function renderChip() {
      const online = !!(state.liveSession && state.liveSession.is_live);
      chip.classList.toggle('on', online);
      chip.textContent = online ? 'STREAMING ONLINE' : 'STREAMING OFFLINE';
    }

    // Shows or clears the feedback banner message.
    function setFeedback(type, message) {
      const normalizedType = type === 'error' ? 'error' : 'success';
      if (!message) {
        feedback.textContent = '';
        feedback.className = 'flash live-feedback hidden';
        return;
      }

      feedback.textContent = message;
      feedback.className = 'flash live-feedback ' + normalizedType;
    }

    // Synchronizes the current search query with the browser URL.
    function syncUrl() {
      const url = new URL(window.location.href);
      if (state.query) {
        url.searchParams.set('q', state.query);
      } else {
        url.searchParams.delete('q');
      }
      window.history.replaceState({}, '', url);
    }

    // Renders every live console section from the current state snapshot.
    function renderAll() {
      searchInput.value = state.query || '';
      renderChip();
      renderCatalog();
      renderLaunch();
      renderQueue();
      syncUrl();
      applyBusyState();
    }

    // Applies the busy state to buttons and form controls.
    function applyBusyState() {
      const isBusy = busyCount > 0;
      root.classList.toggle('is-busy', isBusy);
      root.querySelectorAll('button').forEach(function (button) {
        const baseDisabled = button.dataset.baseDisabled === 'true' || (!button.dataset.baseDisabled && button.disabled);
        button.dataset.baseDisabled = baseDisabled ? 'true' : 'false';
        button.disabled = isBusy || baseDisabled;
      });
      searchInput.readOnly = isBusy;
    }

    // Increments the busy counter for async UI operations.
    function beginBusy() {
      busyCount += 1;
      applyBusyState();
    }

    // Decrements the busy counter for async UI operations.
    function endBusy() {
      busyCount = Math.max(0, busyCount - 1);
      applyBusyState();
    }

    // Sends an AJAX request and validates the JSON response payload.
    async function requestJson(url, options) {
      const response = await fetch(url, Object.assign({
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      }, options || {}));
      const body = await response.json();
      if (!response.ok || !body.ok) {
        throw new Error(body.error || 'No se pudo completar la acción.');
      }
      return body;
    }

    // Reloads the live console state, optionally filtered by query.
    async function loadState(query) {
      if (pendingRequest) {
        pendingRequest.abort();
      }

      const controller = new AbortController();
      pendingRequest = controller;
      const params = new URLSearchParams();
      if (query) {
        params.set('q', query);
      }

      try {
        const body = await requestJson('/app/live' + (params.toString() ? '?' + params.toString() : ''), {
          method: 'GET',
          signal: controller.signal
        });

        state = body.state || state;
        renderAll();
      } finally {
        if (pendingRequest === controller) {
          pendingRequest = null;
        }
      }
    }

    // Submits a live console action form via AJAX and refreshes local state.
    async function submitForm(form) {
      const formData = new FormData(form);
      if (!formData.has('q')) {
        formData.set('q', searchInput.value.trim());
      }

      beginBusy();
      try {
        const body = await requestJson('/app/live', {
          method: 'POST',
          body: formData
        });
        state = body.state || state;
        setFeedback('success', body.message || '');
        renderAll();
      } catch (error) {
        setFeedback('error', error.message);
      } finally {
        endBusy();
      }
    }

    // Submits a button-driven live console action via AJAX and refreshes local state.
    async function submitAction(action) {
      if (!action) {
        return;
      }

      const formData = new FormData();
      formData.set('action', action);
      formData.set('q', searchInput.value.trim());

      beginBusy();
      try {
        const body = await requestJson('/app/live', {
          method: 'POST',
          body: formData
        });
        state = body.state || state;
        setFeedback('success', body.message || '');
        renderAll();
      } catch (error) {
        setFeedback('error', error.message);
      } finally {
        endBusy();
      }
    }

    searchForm.addEventListener('submit', function (event) {
      event.preventDefault();
      beginBusy();
      loadState(searchInput.value.trim())
        .then(function () {
          setFeedback('', '');
        })
        .catch(function (error) {
          if (error.name !== 'AbortError') {
            setFeedback('error', error.message);
          }
        })
        .finally(function () {
          endBusy();
        });
    });

    searchInput.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(function () {
        searchForm.requestSubmit();
      }, 180);
    });

    root.addEventListener('submit', function (event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || form === searchForm) {
        return;
      }

      event.preventDefault();
      submitForm(form);
    });

    root.addEventListener('click', function (event) {
      if (!(event.target instanceof Element)) {
        return;
      }

      const button = event.target.closest('[data-live-action]');
      if (!(button instanceof HTMLButtonElement) || !root.contains(button) || button.disabled) {
        return;
      }

      event.preventDefault();
      submitAction(button.getAttribute('data-live-action') || '');
    });

    renderAll();
  })();
</script>

<?php include __DIR__ . '/app-layout-end.php'; ?>
