<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(assetUrl('app.css')) ?>">
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">LIVE<span>PRO</span></div>
    <nav>
      <a href="/app/live" class="<?= $activeNav === 'onair' ? 'active' : '' ?>">En Vivo</a>
      <a href="/app/store-connection" class="<?= $activeNav === 'store' ? 'active' : '' ?>">Conexión Tienda</a>
      <a href="/app/live-connection" class="<?= $activeNav === 'live' ? 'active' : '' ?>">Conexión Live</a>
      <a href="/app/inventory" class="<?= $activeNav === 'inventory' ? 'active' : '' ?>">Inventario</a>
    </nav>
    <a class="logout" href="/logout">Cerrar sesión</a>
  </aside>

  <main class="main-content">
    <?php if ($flash): ?>
      <div class="flash <?= e((string) $flash['type']) ?>"><?= e((string) $flash['message']) ?></div>
    <?php endif; ?>
