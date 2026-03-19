<?php
$pagination = $inventoryPagination ?? [
  'page' => 1,
  'per_page' => 25,
  'per_page_query' => '25',
  'total_products' => 0,
  'total_pages' => 1,
  'offset' => 0,
];
$perPageOptions = [
  '25' => '25',
  '50' => '50',
  '100' => '100',
  '200' => '200',
  'all' => 'Todos',
];
/** Builds inventory URLs while preserving the selected page size. */
$buildInventoryUrl = static function (int $page, string $perPageQuery): string {
  return '/app/inventory?' . http_build_query([
    'page' => max(1, $page),
    'per_page' => $perPageQuery,
  ]);
};
$rangeStart = !empty($pagination['total_products']) ? ((int) $pagination['offset']) + 1 : 0;
$rangeEnd = !empty($pagination['total_products']) ? ((int) $pagination['offset']) + count($inventory) : 0;
$pageStart = max(1, (int) $pagination['page'] - 2);
$pageEnd = min((int) $pagination['total_pages'], (int) $pagination['page'] + 2);
$syncStatusText = !empty($syncJob)
  ? (string) (($syncProgress['status_text'] ?? '') !== '' ? $syncProgress['status_text'] : ('Estado: ' . (string) $syncJob['status']))
  : 'Estado: idle';
$syncPercent = (int) ($syncProgress['progress_percent'] ?? 0);
$syncProductsText = !empty($syncJob) && !empty($syncProgress['total_products'])
  ? ((int) $syncProgress['processed_products'] . '/' . (int) $syncProgress['total_products'] . ' productos')
  : ((int) ($syncJob['processed_products'] ?? 0) . ' productos procesados');
$syncPagesText = !empty($syncJob) && !empty($syncProgress['total_pages'])
  ? ('Página ' . (int) $syncProgress['pages_processed'] . ' de ' . (int) $syncProgress['total_pages'])
  : 'Preparando importación';
?>
<?php include __DIR__ . '/app-layout-start.php'; ?>

<section class="panel inventory-panel">
  <div class="panel-head">
    <h1>Inventario Maestro</h1>
    <?php if ($store): ?>
      <div class="inventory-head-actions">
        <button id="inventory-reset-btn" class="secondary danger" type="button" <?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'disabled' : '' ?>>Borrar inventario</button>
        <button id="sync-start-btn" class="primary" type="button" <?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'disabled' : '' ?>>Sincronizar en tandas</button>
      </div>
    <?php endif; ?>
  </div>
  <p class="panel-sub">Estado centralizado desde WooCommerce (solo productos y variaciones en stock).</p>
  <?php if ($store): ?>
    <div id="sync-status" class="sync-status <?= !empty($syncJob) ? e((string) $syncJob['status']) : 'idle' ?>">
      <?= e($syncStatusText) ?>
    </div>
  <?php endif; ?>

  <div id="inventory-content">
    <?php if ($store): ?>
      <div class="inventory-toolbar">
        <div class="inventory-toolbar-meta">
          <strong><?= (int) ($pagination['total_products'] ?? 0) ?></strong> productos sincronizados
          <?php if (!empty($pagination['total_products'])): ?>
            <span>Mostrando <?= (int) $rangeStart ?>-<?= (int) $rangeEnd ?></span>
          <?php endif; ?>
        </div>
        <label class="inventory-page-size">
          <span>Por página</span>
          <select id="inventory-per-page">
            <?php foreach ($perPageOptions as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= (string) $pagination['per_page_query'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    <?php endif; ?>

    <?php if (!$store): ?>
      <div class="empty">Primero debes conectar la tienda para ver inventario.</div>
    <?php elseif (empty($inventory)): ?>
      <div class="empty">Aún no hay productos sincronizados. Presiona "Sincronizar en tandas".</div>
    <?php else: ?>
      <div class="inventory-table-wrap">
        <table>
          <thead>
          <tr>
            <th>SKU</th>
            <th>Producto</th>
            <th>Precio</th>
            <th>Stock</th>
            <th>Acciones</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($inventory as $group): ?>
            <?php $product = $group['product']; ?>
            <?php $variations = $group['variations']; ?>
            <?php $menuId = 'menu-' . (int) $product['product_id']; ?>
            <?php $varId = 'vars-' . (int) $product['product_id']; ?>

            <tr>
              <td><?= e((string) ($product['sku'] !== '' ? $product['sku'] : ('#' . (int) $product['product_id']))) ?></td>
              <td class="prod-cell">
                <?php if (!empty($product['image_url'])): ?>
                  <img src="<?= e((string) $product['image_url']) ?>" alt="img">
                <?php endif; ?>
                <span><?= e((string) $product['product_name']) ?></span>
              </td>
              <td><?= e(formatPrice((string) $product['price'])) ?></td>
              <td><?= e((string) $product['stock']) ?></td>
              <td class="actions-cell">
                <button type="button" class="kebab" data-menu-target="<?= e($menuId) ?>">⋮</button>
                <div id="<?= e($menuId) ?>" class="action-menu">
                  <form method="post" action="/app/inventory/sync">
                    <button type="button" class="action-item sync-trigger">Sincronizar</button>
                  </form>
                  <?php if (!empty($variations)): ?>
                    <button type="button" class="action-item" data-toggle-target="<?= e($varId) ?>">Ver variaciones (<?= count($variations) ?>)</button>
                  <?php else: ?>
                    <span class="action-item disabled">Sin variaciones</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>

            <?php if (!empty($variations)): ?>
              <tr id="<?= e($varId) ?>" class="variation-row hidden">
                <td colspan="5">
                  <div class="variation-wrap">
                    <?php foreach ($variations as $var): ?>
                      <div class="variation-item">
                        <div class="variation-left">
                          <span class="variation-sku"><?= e((string) ($var['sku'] !== '' ? $var['sku'] : ('#' . (int) $var['variation_id']))) ?></span>
                          <span class="variation-name"><?= e((string) $var['product_name']) ?></span>
                        </div>
                        <div class="variation-right">
                          <span><?= e(formatPrice((string) $var['price'])) ?></span>
                          <span><?= e((string) $var['stock']) ?></span>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="inventory-pagination">
          <a
            class="page-link <?= (int) $pagination['page'] <= 1 ? 'disabled' : '' ?>"
            href="<?= (int) $pagination['page'] <= 1 ? '#' : e($buildInventoryUrl((int) $pagination['page'] - 1, (string) $pagination['per_page_query'])) ?>"
          >Anterior</a>

          <?php for ($page = $pageStart; $page <= $pageEnd; $page++): ?>
            <a
              class="page-link <?= $page === (int) $pagination['page'] ? 'active' : '' ?>"
              href="<?= e($buildInventoryUrl($page, (string) $pagination['per_page_query'])) ?>"
            ><?= $page ?></a>
          <?php endfor; ?>

          <a
            class="page-link <?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? 'disabled' : '' ?>"
            href="<?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? '#' : e($buildInventoryUrl((int) $pagination['page'] + 1, (string) $pagination['per_page_query'])) ?>"
          >Siguiente</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<div id="sync-overlay" class="sync-overlay <?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'show' : '' ?>">
  <div class="sync-overlay-card">
    <div class="sync-loader"></div>
    <h3>Importando inventario</h3>
    <p>Estamos sincronizando productos y variaciones por tandas.</p>
    <div class="sync-progress-block">
      <div class="sync-progress-head">
        <strong id="sync-progress-percent"><?= $syncPercent ?>%</strong>
        <span id="sync-progress-products"><?= e($syncProductsText) ?></span>
      </div>
      <div class="sync-progress-bar" aria-hidden="true">
        <span id="sync-progress-fill" style="width: <?= $syncPercent ?>%"></span>
      </div>
      <p id="sync-progress-pages" class="sync-progress-pages"><?= e($syncPagesText) ?></p>
      <p id="sync-progress-message" class="sync-progress-message"><?= e((string) ($syncJob['message'] ?? '')) ?></p>
    </div>
    <p class="sync-warning">No salgas ni cierres esta página hasta finalizar.</p>
  </div>
</div>

<script>
  // Controls the inventory UI, pagination refreshes, and sync actions.
  (function () {
    const syncStatus = document.getElementById('sync-status');
    const syncStartBtn = document.getElementById('sync-start-btn');
    const inventoryResetBtn = document.getElementById('inventory-reset-btn');
    const syncOverlay = document.getElementById('sync-overlay');
    const inventoryContent = document.getElementById('inventory-content');
    const syncProgressPercent = document.getElementById('sync-progress-percent');
    const syncProgressProducts = document.getElementById('sync-progress-products');
    const syncProgressFill = document.getElementById('sync-progress-fill');
    const syncProgressPages = document.getElementById('sync-progress-pages');
    const syncProgressMessage = document.getElementById('sync-progress-message');
    let syncing = false;
    let syncLoopActive = false;

    // Closes any open row action menus within the provided scope.
    function closeActionMenus(scope) {
      (scope || document).querySelectorAll('.action-menu').forEach(function (menu) {
        menu.classList.remove('open');
      });
    }

    // Applies the current sync state to buttons and overlay visibility.
    function setSyncUiRunning(running) {
      syncing = running;
      if (syncStartBtn) {
        syncStartBtn.disabled = running;
      }
      if (inventoryResetBtn) {
        inventoryResetBtn.disabled = running;
      }
      if (syncOverlay) {
        syncOverlay.classList.toggle('show', running);
      }
    }

    // Updates the sync progress block rendered inside the overlay.
    function updateSyncOverlay(body) {
      if (syncProgressPercent) {
        syncProgressPercent.textContent = `${body.progress_percent || 0}%`;
      }
      if (syncProgressProducts) {
        if (body.total_products) {
          syncProgressProducts.textContent = `${body.processed_products || 0}/${body.total_products} productos`;
        } else {
          syncProgressProducts.textContent = `${body.processed_products || 0} productos procesados`;
        }
      }
      if (syncProgressFill) {
        syncProgressFill.style.width = `${body.progress_percent || 0}%`;
      }
      if (syncProgressPages) {
        if (body.total_pages) {
          syncProgressPages.textContent = `Página ${body.pages_processed || 0} de ${body.total_pages}`;
        } else {
          syncProgressPages.textContent = 'Preparando importación';
        }
      }
      if (syncProgressMessage) {
        syncProgressMessage.textContent = body.message || '';
      }
    }

    // Refreshes the sync status banner from the backend job record.
    async function updateSyncStatus() {
      if (!syncStatus) return;
      try {
        const response = await fetch('/app/inventory/sync/status');
        const body = await response.json();
        if (!body.ok) return;
        syncStatus.className = 'sync-status ' + (body.status || 'idle');
        syncStatus.textContent = body.status_text || `Estado: ${body.status || 'idle'}`;
        updateSyncOverlay(body);
        setSyncUiRunning(body.status === 'running');
      } catch (_e) {}
    }

    // Reloads only the inventory block while preserving the current query string.
    async function refreshInventoryContent() {
      if (!inventoryContent) return;
      try {
        const response = await fetch('/app/inventory' + window.location.search, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const nextContent = doc.getElementById('inventory-content');
        if (!nextContent) return;
        inventoryContent.innerHTML = nextContent.innerHTML;
        bindInventoryControls(inventoryContent);
      } catch (_e) {}
    }

    // Processes sync batches until the current job finishes or fails.
    async function runSyncLoop() {
      if (syncLoopActive) return;
      syncLoopActive = true;
      setSyncUiRunning(true);

      try {
        while (true) {
          const response = await fetch('/app/inventory/sync/run', { method: 'POST' });
          const body = await response.json();
          await updateSyncStatus();
          if (!body.ok || body.status !== 'running') {
            break;
          }
        }
      } catch (_e) {
        // no-op
      }

      syncLoopActive = false;
      setSyncUiRunning(false);
      await refreshInventoryContent();
      await updateSyncStatus();
    }

    // Starts a regular inventory synchronization job.
    async function startSync() {
      if (syncLoopActive) return;
      await fetch('/app/inventory/sync', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      await updateSyncStatus();
      await runSyncLoop();
    }

    // Clears the local inventory without starting a new synchronization.
    async function clearInventory() {
      if (syncLoopActive) return;
      const confirmed = window.confirm('Se borrará el inventario sincronizado localmente. Esta acción no iniciará una nueva sincronización. ¿Continuar?');
      if (!confirmed) return;

      await fetch('/app/inventory/clear', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      await updateSyncStatus();
      await refreshInventoryContent();
    }

    if (syncStartBtn) {
      syncStartBtn.addEventListener('click', startSync);
    }

    if (inventoryResetBtn) {
      inventoryResetBtn.addEventListener('click', clearInventory);
    }

    updateSyncStatus();
    if (<?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'true' : 'false' ?>) {
      runSyncLoop();
    }

    // Rebinds interactive inventory controls after partial HTML refreshes.
    function bindInventoryControls(scope) {
      (scope || document).querySelectorAll('.sync-trigger').forEach(function (el) {
        el.addEventListener('click', startSync);
      });

      (scope || document).querySelectorAll('.kebab').forEach(function (button) {
        button.addEventListener('click', function (event) {
          event.stopPropagation();
          const id = button.getAttribute('data-menu-target');
          document.querySelectorAll('.action-menu').forEach(function (menu) {
            if (menu.id !== id) {
              menu.classList.remove('open');
            }
          });
          const target = document.getElementById(id);
          if (target) {
            target.classList.toggle('open');
          }
        });
      });

      (scope || document).querySelectorAll('[data-toggle-target]').forEach(function (button) {
        button.addEventListener('click', function () {
          const id = button.getAttribute('data-toggle-target');
          const row = document.getElementById(id);
          if (row) {
            row.classList.toggle('hidden');
          }
        });
      });

      (scope || document).querySelectorAll('.page-link.disabled').forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
        });
      });

      (scope || document).querySelectorAll('#inventory-per-page').forEach(function (select) {
        select.addEventListener('change', function () {
          const params = new URLSearchParams(window.location.search);
          params.set('page', '1');
          params.set('per_page', select.value);
          window.location.search = params.toString();
        });
      });
    }

    bindInventoryControls(inventoryContent || document);

    document.addEventListener('click', function () {
      closeActionMenus(document);
    });
  })();
</script>

<?php include __DIR__ . '/app-layout-end.php'; ?>
