<?php include __DIR__ . '/app-layout-start.php'; ?>

<section class="panel">
  <div class="panel-head">
    <h1>Conexión Tienda</h1>
    <span class="chip"><?= $store ? 'CONECTADA' : 'SIN CONEXIÓN' ?></span>
  </div>
  <p class="panel-sub">Sincronice sus productos y variaciones directamente desde WooCommerce.</p>

  <form method="post" action="/app/store-connection" class="form-grid">
    <label>Nombre de la tienda</label>
    <input type="text" name="store_name" value="<?= e((string) ($store['name'] ?? '')) ?>" placeholder="Mi tienda">

    <label>URL de la web</label>
    <input type="url" name="site_url" value="<?= e((string) ($store['site_url'] ?? '')) ?>" placeholder="https://tu-tienda.com" required>

    <div class="cols">
      <div>
        <label>Consumer Key</label>
        <input type="text" name="api_key" value="<?= e((string) ($store['api_key'] ?? '')) ?>" placeholder="ck_xxx" required>
      </div>
      <div>
        <label>Consumer Secret</label>
        <input type="text" name="api_secret" value="<?= e((string) ($store['api_secret'] ?? '')) ?>" placeholder="cs_xxx" required>
      </div>
    </div>

    <button type="submit" class="primary">Conectar y guardar</button>
  </form>
</section>

<?php include __DIR__ . '/app-layout-end.php'; ?>
