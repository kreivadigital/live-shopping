<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-body">
<div class="auth-wrap">
  <div class="auth-card">
    <h1>LIVEPRO</h1>
    <h2>Iniciar sesión</h2>
    <p>Accede al backoffice de Live Shopping.</p>

    <?php if ($flash): ?>
      <div class="flash <?= e((string) $flash['type']) ?>"><?= e((string) $flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
      <label>Email</label>
      <input type="email" name="email" required>

      <label>Contraseña</label>
      <input type="password" name="password" required>

      <button type="submit">Ingresar</button>
    </form>

    <p class="auth-alt">¿No tienes cuenta? <a href="/register">Crear cuenta</a></p>
  </div>
</div>
</body>
</html>
