<?php include __DIR__ . '/app-layout-start.php'; ?>

<section class="panel live-console-panel">
  <div class="panel-head">
    <h1>En Vivo</h1>
    <span class="chip <?= !empty($liveSession['is_live']) ? 'on' : '' ?>"><?= !empty($liveSession['is_live']) ? 'STREAMING ONLINE' : 'STREAMING OFFLINE' ?></span>
  </div>

  <div class="live-grid-v2">
    <div class="live-col left-col">
      <div class="live-card">
        <div class="live-card-title">Catálogo tienda</div>
        <form method="get" action="/app/live" class="live-search-form">
          <input type="text" name="q" value="<?= e($query) ?>" placeholder="Buscar producto...">
        </form>

        <div class="live-catalog-list">
          <?php if (empty($catalog)): ?>
            <div class="empty">No hay productos en stock para mostrar.</div>
          <?php else: ?>
            <?php foreach ($catalog as $item): ?>
              <?php $isSelected = (int) ($item['product_id'] ?? 0) === (int) ($selectedProduct['product_id'] ?? 0); ?>
              <form method="post" action="/app/live" class="catalog-item <?= $isSelected ? 'selected' : '' ?>">
                <input type="hidden" name="action" value="select_product">
                <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">

                <button type="submit" class="catalog-item-btn">
                  <div class="catalog-thumb-wrap">
                    <?php if (!empty($item['image_url'])): ?>
                      <img src="<?= e((string) $item['image_url']) ?>" alt="img" class="catalog-thumb">
                    <?php else: ?>
                      <div class="catalog-thumb"></div>
                    <?php endif; ?>
                  </div>
                  <div class="catalog-meta">
                    <div class="catalog-name"><?= e((string) $item['product_name']) ?></div>
                    <div class="catalog-price"><?= e((string) $item['price']) ?></div>
                  </div>
                </button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="live-card launch-card-small">
        <div class="live-card-title">Lanzamiento activo</div>

        <?php if ($selectedProduct): ?>
          <div class="launch-product compact">
            <?php if (!empty($selectedProduct['image_url'])): ?>
              <img src="<?= e((string) $selectedProduct['image_url']) ?>" alt="img">
            <?php else: ?>
              <div class="launch-thumb"></div>
            <?php endif; ?>
            <div>
              <div class="launch-name small"><?= e((string) $selectedProduct['product_name']) ?></div>
              <div class="launch-price small"><?= e((string) $selectedProduct['price']) ?></div>
            </div>
          </div>
        <?php else: ?>
          <div class="launch-empty">Seleccione producto</div>
        <?php endif; ?>

        <div class="launch-actions">
          <form method="post" action="/app/live">
            <input type="hidden" name="action" value="launch_product">
            <button type="submit" class="launch-btn" <?= $selectedProduct ? '' : 'disabled' ?>>Poner en vivo</button>
          </form>
          <?php if ($selectedProduct): ?>
            <form method="post" action="/app/live">
              <input type="hidden" name="action" value="clear_selected">
              <button type="submit" class="secondary">Limpiar</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="live-col">
      <div class="live-card queue-card">
        <div class="live-card-title">Cola de emisión</div>
        <div class="queue-list">
          <?php if (empty($emissionQueue)): ?>
            <div class="queue-placeholder">Aún no hay productos lanzados.</div>
          <?php else: ?>
            <?php foreach ($emissionQueue as $q): ?>
              <div class="queue-item">
                <?php if (!empty($q['image_url'])): ?>
                  <img src="<?= e((string) $q['image_url']) ?>" alt="img">
                <?php else: ?>
                  <div class="queue-thumb"></div>
                <?php endif; ?>
                <div class="queue-meta">
                  <div class="queue-name"><?= e((string) $q['product_name']) ?></div>
                  <div class="queue-price"><?= e((string) $q['price']) ?></div>
                </div>
                <div class="queue-time"><?= e((string) $q['created_at']) ?></div>
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
          <?php if (!empty($liveSession['youtube_video_id'])): ?>
            <iframe src="https://www.youtube.com/embed/<?= e((string) $liveSession['youtube_video_id']) ?>" title="Vista previa live" allowfullscreen></iframe>
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

<?php include __DIR__ . '/app-layout-end.php'; ?>
