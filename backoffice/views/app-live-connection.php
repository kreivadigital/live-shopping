<?php include __DIR__ . '/app-layout-start.php'; ?>

<section class="panel">
  <div class="panel-head">
    <h1>Conexión Live</h1>
    <span class="chip <?= !empty($session['is_live']) ? 'on' : '' ?>"><?= !empty($session['is_live']) ? 'STREAMING ONLINE' : 'STREAMING OFFLINE' ?></span>
  </div>
  <p class="panel-sub">Gestione la entrada de video de YouTube.</p>

  <?php if (!$store): ?>
    <div class="empty">Primero debes conectar la tienda en la sección Conexión Tienda.</div>
  <?php else: ?>
    <form method="post" action="/app/live-connection" class="form-grid">
      <label>YouTube URL</label>
      <input type="url" name="youtube_url" value="<?= e((string) ($session['youtube_url'] ?? '')) ?>" placeholder="https://youtube.com/watch?v=...">

      <div class="action-row">
        <button type="submit" name="live_action" value="start" class="danger">Iniciar Live</button>
        <button type="submit" name="live_action" value="stop" class="secondary">Stop Live</button>
      </div>
    </form>

    <div class="preview">
      <?php if (!empty($session['youtube_video_id'])): ?>
        <iframe src="https://www.youtube.com/embed/<?= e((string) $session['youtube_video_id']) ?>" title="Vista previa live" allowfullscreen></iframe>
      <?php else: ?>
        <div class="preview-empty">Sin vista previa</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/app-layout-end.php'; ?>
