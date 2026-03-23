<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(assetUrl('app.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-wrap">
  <div class="auth-card">
    <h1>LIVEPRO</h1>
    <h2>Crear cuenta</h2>
    <p>Configura tu panel de Live Commerce.</p>

    <?php if ($flash): ?>
      <div class="flash <?= e((string) $flash['type']) ?>"><?= e((string) $flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="/register">
      <label>Nombre completo</label>
      <input type="text" name="full_name" required>

      <label>Email</label>
      <input type="email" name="email" required>

      <label>Contraseña</label>
      <input type="password" name="password" minlength="6" required>

      <button type="submit">Crear cuenta</button>
    </form>

    <p class="auth-alt">¿Ya tienes cuenta? <a href="/login">Inicia sesión</a></p>
  </div>
</div>
</body>
</html>
