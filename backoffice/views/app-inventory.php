<?php include __DIR__ . '/app-layout-start.php'; ?>

<section class="panel">
  <div class="panel-head">
    <h1>Inventario Maestro</h1>
    <?php if ($store): ?>
      <div>
        <button id="sync-start-btn" class="primary" type="button" <?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'disabled' : '' ?>>Sincronizar en tandas</button>
      </div>
    <?php endif; ?>
  </div>
  <p class="panel-sub">Estado centralizado desde WooCommerce (solo productos y variaciones en stock).</p>
  <?php if ($store): ?>
    <div id="sync-status" class="sync-status <?= !empty($syncJob) ? e((string) $syncJob['status']) : 'idle' ?>">
      <?php if (!empty($syncJob)): ?>
        Estado: <?= e((string) $syncJob['status']) ?> |
        Productos procesados: <?= (int) ($syncJob['processed_products'] ?? 0) ?> |
        Filas guardadas: <?= (int) ($syncJob['inserted_count'] ?? 0) ?> |
        <?= e((string) ($syncJob['message'] ?? '')) ?>
      <?php else: ?>
        Estado: idle
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$store): ?>
    <div class="empty">Primero debes conectar la tienda para ver inventario.</div>
  <?php elseif (empty($inventory)): ?>
    <div class="empty">Aún no hay productos sincronizados. Presiona "Sincronizar todos".</div>
  <?php else: ?>
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
          <td><?= e((string) $product['price']) ?></td>
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
                      <span><?= e((string) $var['price']) ?></span>
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
  <?php endif; ?>
</section>

<div id="sync-overlay" class="sync-overlay <?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'show' : '' ?>">
  <div class="sync-overlay-card">
    <div class="sync-loader"></div>
    <h3>Importando inventario</h3>
    <p>Estamos sincronizando productos y variaciones por tandas.</p>
    <p class="sync-warning">No salgas ni cierres esta página hasta finalizar.</p>
  </div>
</div>

<script>
  (function () {
    const syncStatus = document.getElementById('sync-status');
    const syncStartBtn = document.getElementById('sync-start-btn');
    const syncOverlay = document.getElementById('sync-overlay');
    let syncing = false;

    function setSyncUiRunning(running) {
      syncing = running;
      if (syncStartBtn) {
        syncStartBtn.disabled = running;
      }
      if (syncOverlay) {
        syncOverlay.classList.toggle('show', running);
      }
    }

    async function updateSyncStatus() {
      if (!syncStatus) return;
      try {
        const response = await fetch('/app/inventory/sync/status');
        const body = await response.json();
        if (!body.ok) return;
        syncStatus.className = 'sync-status ' + (body.status || 'idle');
        syncStatus.textContent = `Estado: ${body.status || 'idle'} | Productos procesados: ${body.processed_products || 0} | Filas guardadas: ${body.inserted_count || 0} | ${body.message || ''}`;
        setSyncUiRunning(body.status === 'running');
      } catch (_e) {}
    }

    async function runSyncLoop() {
      if (syncing) return;
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

      setSyncUiRunning(false);
      window.location.reload();
    }

    async function startSync() {
      if (syncing) return;
      await fetch('/app/inventory/sync', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      await updateSyncStatus();
      await runSyncLoop();
    }

    if (syncStartBtn) {
      syncStartBtn.addEventListener('click', startSync);
    }

    document.querySelectorAll('.sync-trigger').forEach(function (el) {
      el.addEventListener('click', startSync);
    });

    updateSyncStatus();
    if (<?= (!empty($syncJob) && (string) $syncJob['status'] === 'running') ? 'true' : 'false' ?>) {
      runSyncLoop();
    }

    document.querySelectorAll('.kebab').forEach(function (button) {
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

    document.querySelectorAll('[data-toggle-target]').forEach(function (button) {
      button.addEventListener('click', function () {
        const id = button.getAttribute('data-toggle-target');
        const row = document.getElementById(id);
        if (row) {
          row.classList.toggle('hidden');
        }
      });
    });

    document.addEventListener('click', function () {
      document.querySelectorAll('.action-menu').forEach(function (menu) {
        menu.classList.remove('open');
      });
    });
  })();
</script>

<?php include __DIR__ . '/app-layout-end.php'; ?>
