<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';

final class App
{
    private PDO $db;

    /**
     * Boots the application with a shared database connection.
     */
    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Dispatches the incoming request to the API or web layer.
     */
    public function handle(string $method, string $path): void
    {
        if ($this->isApiRequest($path)) {
            $this->handleApi($method, $path);
            return;
        }

        $this->handleWeb($method, $path);
    }

    /**
     * Determines whether the requested path belongs to the API surface.
     */
    private function isApiRequest(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }

    /**
     * Routes API requests to the corresponding controller action.
     */
    private function handleApi(string $method, string $path): void
    {
        $this->setCors();

        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/plugin/connect') {
            $this->pluginConnect();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/live/public/(\d+)/snapshot$#', $path, $matches)) {
            $this->livePublicSnapshot((int) $matches[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/live/public/(\d+)/delta$#', $path, $matches)) {
            $this->livePublicDelta((int) $matches[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/live/public/(\d+)$#', $path, $matches)) {
            $this->livePublic((int) $matches[1]);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/intent/create-pending-order') {
            $this->createPendingOrder();
            return;
        }

        jsonResponse(['error' => 'Not found'], 404);
    }

    /**
     * Routes authenticated backoffice requests to their screen handlers.
     */
    private function handleWeb(string $method, string $path): void
    {
        if ($path === '/' || $path === '/admin') {
            if ($this->currentUser()) {
                redirectTo('/app/store-connection');
            }
            redirectTo('/login');
        }

        if ($path === '/register') {
            if ($method === 'POST') {
                $this->registerUser();
                return;
            }
            $this->renderRegister();
            return;
        }

        if ($path === '/login') {
            if ($method === 'POST') {
                $this->loginUser();
                return;
            }
            $this->renderLogin();
            return;
        }

        if ($path === '/logout') {
            session_destroy();
            redirectTo('/login');
        }

        if ($path === '/app' || $path === '/app/') {
            $this->requireUser();
            redirectTo('/app/store-connection');
        }

        $user = $this->requireUser();

        if ($path === '/app/store-connection') {
            if ($method === 'POST') {
                $this->saveStoreConnection((int) $user['id']);
                return;
            }
            $this->renderStoreConnection((int) $user['id']);
            return;
        }

        if ($path === '/app/live-connection') {
            if ($method === 'POST') {
                $this->saveLiveConnection((int) $user['id']);
                return;
            }
            $this->renderLiveConnection((int) $user['id']);
            return;
        }

        if ($path === '/app/live') {
            if ($method === 'POST') {
                $this->handleLiveConsoleAction((int) $user['id']);
                return;
            }
            $this->renderLiveConsole((int) $user['id']);
            return;
        }

        if ($path === '/app/inventory') {
            $this->renderInventory((int) $user['id']);
            return;
        }

        if ($path === '/app/inventory/sync' && $method === 'POST') {
            $this->startInventorySync((int) $user['id']);
            return;
        }

        if ($path === '/app/inventory/clear' && $method === 'POST') {
            $this->clearInventory((int) $user['id']);
            return;
        }

        if ($path === '/app/inventory/sync/run' && $method === 'POST') {
            $this->runInventorySyncBatch((int) $user['id']);
            return;
        }

        if ($path === '/app/inventory/sync/status' && $method === 'GET') {
            $this->inventorySyncStatus((int) $user['id']);
            return;
        }

        http_response_code(404);
        echo 'Not found';
    }

    /**
     * Returns the currently authenticated user from the session.
     */
    private function currentUser(): ?array
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Ensures there is an authenticated user before continuing.
     */
    private function requireUser(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            redirectTo('/login');
        }

        return $user;
    }

    /**
     * Detects whether the current request expects a JSON response.
     */
    private function expectsJsonRequest(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    /**
     * Creates a new backoffice user and starts the session.
     */
    private function registerUser(): void
    {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($fullName === '' || $email === '' || $password === '') {
            setFlash('error', 'Completa nombre, email y contraseña.');
            redirectTo('/register');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Email inválido.');
            redirectTo('/register');
        }

        if (strlen($password) < 6) {
            setFlash('error', 'La contraseña debe tener al menos 6 caracteres.');
            redirectTo('/register');
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            setFlash('error', 'Ese email ya está registrado.');
            redirectTo('/register');
        }

        $insert = $this->db->prepare(
            'INSERT INTO users (full_name, email, password_hash) VALUES (:full_name, :email, :password_hash)'
        );

        $insert->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $_SESSION['user_id'] = (int) $this->db->lastInsertId();
        setFlash('success', 'Cuenta creada correctamente.');
        redirectTo('/app/store-connection');
    }

    /**
     * Authenticates a user against the stored credentials.
     */
    private function loginUser(): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            setFlash('error', 'Credenciales inválidas.');
            redirectTo('/login');
        }

        $_SESSION['user_id'] = (int) $user['id'];
        setFlash('success', 'Sesión iniciada.');
        redirectTo('/app/store-connection');
    }

    /**
     * Renders the login screen.
     */
    private function renderLogin(): void
    {
        $flash = pullFlash();
        $title = 'Login | LivePro';
        include __DIR__ . '/../views/auth-login.php';
    }

    /**
     * Renders the registration screen.
     */
    private function renderRegister(): void
    {
        $flash = pullFlash();
        $title = 'Registro | LivePro';
        include __DIR__ . '/../views/auth-register.php';
    }

    /**
     * Creates or updates the WooCommerce store connection for the user.
     */
    private function saveStoreConnection(int $userId): void
    {
        $siteUrl = trim((string) ($_POST['site_url'] ?? ''));
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $apiSecret = trim((string) ($_POST['api_secret'] ?? ''));
        $storeName = trim((string) ($_POST['store_name'] ?? 'Mi tienda'));

        if ($siteUrl === '' || $apiKey === '' || $apiSecret === '') {
            setFlash('error', 'Completa URL, Consumer Key y Consumer Secret.');
            redirectTo('/app/store-connection');
        }

        $store = $this->findStoreByUserId($userId);
        if ($store) {
            $stmt = $this->db->prepare(
                'UPDATE stores
                 SET name = :name,
                     site_url = :site_url,
                     api_key = :api_key,
                     api_secret = :api_secret,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            try {
                $stmt->execute([
                    ':name' => $storeName,
                    ':site_url' => $siteUrl,
                    ':api_key' => $apiKey,
                    ':api_secret' => $apiSecret,
                    ':id' => $store['id'],
                ]);
            } catch (Throwable $error) {
                setFlash('error', 'No se pudo guardar la conexión. Revisa si la API key ya está en uso.');
                redirectTo('/app/store-connection');
            }

            $storeId = (int) $store['id'];
        } else {
            $slug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($storeName));
            $stmt = $this->db->prepare(
                'INSERT INTO stores (user_id, slug, name, site_url, api_key, api_secret)
                 VALUES (:user_id, :slug, :name, :site_url, :api_key, :api_secret)'
            );

            try {
                $stmt->execute([
                    ':user_id' => $userId,
                    ':slug' => $slug,
                    ':name' => $storeName,
                    ':site_url' => $siteUrl,
                    ':api_key' => $apiKey,
                    ':api_secret' => $apiSecret,
                ]);
            } catch (Throwable $error) {
                setFlash('error', 'No se pudo guardar la conexión. Revisa si la API key ya está en uso.');
                redirectTo('/app/store-connection');
            }

            $storeId = (int) $this->db->lastInsertId();
        }

        $live = $this->findLiveSession($storeId);
        if (!$live) {
            $stmt = $this->db->prepare('INSERT INTO live_sessions (store_id, is_live) VALUES (:store_id, 0)');
            $stmt->execute([':store_id' => $storeId]);
        }

        setFlash('success', 'Conexión de tienda guardada.');
        redirectTo('/app/store-connection');
    }

    /**
     * Renders the store connection screen.
     */
    private function renderStoreConnection(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        $flash = pullFlash();
        $activeNav = 'store';
        $title = 'Conexión Tienda | LivePro';
        include __DIR__ . '/../views/app-store-connection.php';
    }

    /**
     * Stores the YouTube live connection settings for the active store.
     */
    private function saveLiveConnection(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        $youtubeUrl = trim((string) ($_POST['youtube_url'] ?? ''));
        $action = (string) ($_POST['live_action'] ?? 'start');
        $isLive = $action === 'stop' ? 0 : 1;
        $sessionKey = $isLive ? $this->generatePublicLiveSessionKey() : null;

        if ($youtubeUrl === '' && $isLive === 1) {
            setFlash('error', 'Ingresa la URL de YouTube antes de iniciar el live.');
            redirectTo('/app/live-connection');
        }

        $stmt = $this->db->prepare(
            'UPDATE live_sessions
             SET youtube_url = :youtube_url,
                 youtube_video_id = :video_id,
                 is_live = :is_live,
                 public_session_key = COALESCE(:public_session_key, public_session_key),
                 session_revision = :session_revision,
                 active_product_id = :active_product_id,
                 active_variation_id = :active_variation_id,
                 active_product_name = :active_product_name,
                 active_price = :active_price,
                 active_image = :active_image,
                 updated_at = CURRENT_TIMESTAMP
             WHERE store_id = :store_id'
        );

        $stmt->execute([
            ':youtube_url' => $youtubeUrl,
            ':video_id' => extractYoutubeVideoId($youtubeUrl),
            ':is_live' => $isLive,
            ':public_session_key' => $sessionKey,
            ':session_revision' => $isLive ? 0 : (int) (($this->findLiveSession((int) $store['id'])['session_revision'] ?? 0)),
            ':active_product_id' => null,
            ':active_variation_id' => null,
            ':active_product_name' => null,
            ':active_price' => null,
            ':active_image' => null,
            ':store_id' => (int) $store['id'],
        ]);

        setFlash('success', $isLive ? 'Live iniciado.' : 'Live detenido.');
        redirectTo('/app/live-connection');
    }

    /**
     * Renders the live connection configuration screen.
     */
    private function renderLiveConnection(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        $session = $store ? $this->findLiveSession((int) $store['id']) : null;
        $flash = pullFlash();
        $activeNav = 'live';
        $title = 'Conexión Live | LivePro';
        include __DIR__ . '/../views/app-live-connection.php';
    }

    /**
     * Handles AJAX and form actions for the live console.
     */
    private function handleLiveConsoleAction(int $userId): void
    {
        $expectsJson = $this->expectsJsonRequest();
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => 'Primero configura la conexión de tienda.'], 422);
                return;
            }
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        $action = (string) ($_POST['action'] ?? '');
        $query = $this->currentLiveConsoleQuery();
        $sessionKey = 'live_console_selected_' . (int) $store['id'];

        if ($action === 'select_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                if ($expectsJson) {
                    jsonResponse(['ok' => false, 'error' => 'Selecciona un producto válido.'], 422);
                    return;
                }
                setFlash('error', 'Selecciona un producto válido.');
                redirectTo('/app/live');
            }

            $_SESSION[$sessionKey] = $productId;
            if ($expectsJson) {
                jsonResponse([
                    'ok' => true,
                    'message' => 'Producto cargado en lanzamiento activo.',
                    'state' => $this->buildLiveConsoleState((int) $store['id'], $query),
                ]);
                return;
            }
            setFlash('success', 'Producto cargado en lanzamiento activo.');
            redirectTo('/app/live');
        }

        if ($action === 'launch_product') {
            $selectedProductId = isset($_SESSION[$sessionKey]) ? (int) $_SESSION[$sessionKey] : 0;
            if ($selectedProductId <= 0) {
                if ($expectsJson) {
                    jsonResponse(['ok' => false, 'error' => 'Selecciona un producto antes de poner en vivo.'], 422);
                    return;
                }
                setFlash('error', 'Selecciona un producto antes de poner en vivo.');
                redirectTo('/app/live');
            }

            $product = $this->findInventoryParentByProductId((int) $store['id'], $selectedProductId);
            if (!$product) {
                if ($expectsJson) {
                    jsonResponse(['ok' => false, 'error' => 'El producto seleccionado no existe en inventario.'], 404);
                    return;
                }
                setFlash('error', 'El producto seleccionado no existe en inventario.');
                redirectTo('/app/live');
            }

            $live = $this->findLiveSession((int) $store['id']);
            if (!$live) {
                $insert = $this->db->prepare('INSERT INTO live_sessions (store_id, is_live) VALUES (:store_id, 0)');
                $insert->execute([':store_id' => (int) $store['id']]);
                $live = $this->findLiveSession((int) $store['id']);
            }

            $sessionKey = $this->ensurePublicLiveSessionKey((int) $store['id'], $live ?: null);
            $revision = $this->bumpLiveSessionRevision((int) $store['id']);

            $stmt = $this->db->prepare(
                'UPDATE live_sessions
                 SET active_product_id = :active_product_id,
                     active_variation_id = NULL,
                     active_product_name = :active_product_name,
                     active_price = :active_price,
                     active_image = :active_image,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE store_id = :store_id'
            );
            $stmt->execute([
                ':active_product_id' => (int) $product['product_id'],
                ':active_product_name' => (string) $product['product_name'],
                ':active_price' => (string) $product['price'],
                ':active_image' => (string) $product['image_url'],
                ':store_id' => (int) $store['id'],
            ]);

            $insertQueue = $this->db->prepare(
                'INSERT INTO live_emission_queue (
                    store_id,
                    product_id,
                    variation_id,
                    session_key,
                    event_revision,
                    product_name,
                    price,
                    image_url
                 ) VALUES (
                    :store_id,
                    :product_id,
                    :variation_id,
                    :session_key,
                    :event_revision,
                    :product_name,
                    :price,
                    :image_url
                 )'
            );
            $insertQueue->execute([
                ':store_id' => (int) $store['id'],
                ':product_id' => (int) $product['product_id'],
                ':variation_id' => null,
                ':session_key' => $sessionKey,
                ':event_revision' => $revision,
                ':product_name' => (string) $product['product_name'],
                ':price' => (string) $product['price'],
                ':image_url' => (string) $product['image_url'],
            ]);

            unset($_SESSION[$sessionKey]);

            if ($expectsJson) {
                jsonResponse([
                    'ok' => true,
                    'message' => 'Producto enviado al vivo correctamente.',
                    'state' => $this->buildLiveConsoleState((int) $store['id'], $query),
                ]);
                return;
            }
            setFlash('success', 'Producto enviado al vivo correctamente.');
            redirectTo('/app/live');
        }

        if ($action === 'clear_selected') {
            unset($_SESSION[$sessionKey]);
            if ($expectsJson) {
                jsonResponse([
                    'ok' => true,
                    'message' => 'Lanzamiento activo limpiado.',
                    'state' => $this->buildLiveConsoleState((int) $store['id'], $query),
                ]);
                return;
            }
            setFlash('success', 'Lanzamiento activo limpiado.');
            redirectTo('/app/live');
        }

        if ($action === 'clear_emission_queue') {
            $this->clearEmissionQueue((int) $store['id']);
            if ($expectsJson) {
                jsonResponse([
                    'ok' => true,
                    'message' => 'Historial del vivo borrado.',
                    'state' => $this->buildLiveConsoleState((int) $store['id'], $query),
                ]);
                return;
            }
            setFlash('success', 'Historial del vivo borrado.');
            redirectTo('/app/live');
        }

        if ($expectsJson) {
            jsonResponse(['ok' => false, 'error' => 'Acción inválida.'], 422);
            return;
        }
        setFlash('error', 'Acción inválida.');
        redirectTo('/app/live');
    }

    /**
     * Renders the live console or returns its JSON state.
     */
    private function renderLiveConsole(int $userId): void
    {
        $expectsJson = $this->expectsJsonRequest();
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => 'Primero configura la conexión de tienda.'], 422);
                return;
            }
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        $query = $this->currentLiveConsoleQuery();
        $liveState = $this->buildLiveConsoleState((int) $store['id'], $query);
        if ($expectsJson) {
            jsonResponse(['ok' => true, 'state' => $liveState]);
            return;
        }

        $flash = pullFlash();
        $activeNav = 'onair';
        $title = 'En Vivo | LivePro';
        $liveStateJson = json_encode(
            $liveState,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?: '{}';
        include __DIR__ . '/../views/app-live-console.php';
    }

    /**
     * Starts a regular inventory synchronization.
     */
    private function startInventorySync(int $userId): void
    {
        $this->beginInventorySyncForUser($userId);
    }

    /**
     * Boots the inventory sync flow for the current store and response type.
     */
    private function beginInventorySyncForUser(int $userId): void
    {
        $expectsJson = $this->expectsJsonRequest();
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => 'Primero configura la conexión de tienda.'], 422);
                return;
            }
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        try {
            $job = $this->getLatestSyncJob((int) $store['id']);
            if ($job && (string) $job['status'] === 'running') {
                $message = 'La sincronización ya está en curso.';
                if ($expectsJson) {
                    jsonResponse(['ok' => true, 'status' => 'running', 'message' => $message]);
                    return;
                }
                setFlash('success', $message);
                redirectTo('/app/inventory' . $this->currentInventoryQueryString());
            }

            $createdJob = $this->createInventorySyncJob((int) $store['id']);
            $startedMessage = 'Sincronización iniciada en segundo plano.';

            if ($expectsJson) {
                jsonResponse([
                    'ok' => true,
                    'status' => 'running',
                    'job_type' => (string) $createdJob['job_type'],
                    'message' => $startedMessage,
                ]);
                return;
            }
            setFlash('success', $startedMessage);
        } catch (Throwable $error) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
                return;
            }
            setFlash('error', 'No se pudo iniciar sincronización: ' . $error->getMessage());
        }

        redirectTo('/app/inventory' . $this->currentInventoryQueryString());
    }

    /**
     * Deletes the local inventory and resets the sync status to idle.
     */
    private function clearInventory(int $userId): void
    {
        $expectsJson = $this->expectsJsonRequest();
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => 'Primero configura la conexión de tienda.'], 422);
                return;
            }
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        try {
            $job = $this->getLatestSyncJob((int) $store['id']);
            if ($job && (string) $job['status'] === 'running') {
                $message = 'No puedes borrar el inventario mientras hay una sincronización en curso.';
                if ($expectsJson) {
                    jsonResponse(['ok' => false, 'error' => $message], 422);
                    return;
                }
                setFlash('error', $message);
                redirectTo('/app/inventory' . $this->currentInventoryQueryString());
            }

            $this->clearInventoryData((int) $store['id']);

            if ($expectsJson) {
                jsonResponse([
                    'ok' => true,
                    'status' => 'idle',
                    'message' => 'Inventario borrado.',
                ]);
                return;
            }

            setFlash('success', 'Inventario borrado.');
        } catch (Throwable $error) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
                return;
            }
            setFlash('error', 'No se pudo borrar el inventario: ' . $error->getMessage());
        }

        redirectTo('/app/inventory' . $this->currentInventoryQueryString());
    }

    /**
     * Removes local inventory and sync-job state for the given store.
     */
    private function clearInventoryData(int $storeId): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM inventory_items
                 WHERE store_id = :store_id'
            );
            $stmt->execute([':store_id' => $storeId]);

            $stmt = $this->db->prepare(
                'DELETE FROM sync_jobs
                 WHERE store_id = :store_id'
            );
            $stmt->execute([':store_id' => $storeId]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /**
     * Creates a sync job record for a full inventory import.
     */
    private function createInventorySyncJob(int $storeId): array
    {
        $token = bin2hex(random_bytes(10));
        $jobType = 'sync_full';
        $message = 'Iniciando sincronización por lotes...';

        $stmt = $this->db->prepare(
            'INSERT INTO sync_jobs (store_id, status, job_type, sync_token, current_page, inserted_count, processed_products, total_products, total_pages, message)
             VALUES (:store_id, :status, :job_type, :sync_token, :current_page, 0, 0, 0, 0, :message)'
        );
        $stmt->execute([
            ':store_id' => $storeId,
            ':status' => 'running',
            ':job_type' => $jobType,
            ':sync_token' => $token,
            ':current_page' => 1,
            ':message' => $message,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'job_type' => $jobType,
            'sync_token' => $token,
        ];
    }

    /**
     * Renders the inventory screen using server-side pagination.
     */
    private function renderInventory(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        $inventory = [];
        $syncJob = null;
        $syncProgress = null;
        $inventoryPagination = $this->defaultInventoryPagination();

        if ($store) {
            $syncJob = $this->getLatestSyncJob((int) $store['id']);
            $syncProgress = $syncJob ? $this->buildSyncJobProgress($syncJob) : null;
            $inventoryPage = $this->fetchInventoryPage(
                (int) $store['id'],
                $this->currentInventoryPage(),
                $this->currentInventoryPerPage()
            );
            $inventory = $inventoryPage['items'];
            $inventoryPagination = $inventoryPage['pagination'];
        }

        $flash = pullFlash();
        $activeNav = 'inventory';
        $title = 'Inventario | LivePro';
        include __DIR__ . '/../views/app-inventory.php';
    }

    /**
     * Builds the sync progress payload used by the inventory UI.
     */
    private function buildSyncJobProgress(array $job): array
    {
        $totalProducts = max(0, (int) ($job['total_products'] ?? 0));
        $totalPages = max(0, (int) ($job['total_pages'] ?? 0));
        $processedProducts = max(0, (int) ($job['processed_products'] ?? 0));
        $pagesProcessed = max(0, (int) ($job['current_page'] ?? 1) - 1);

        if ($totalProducts > 0) {
            $processedProducts = min($processedProducts, $totalProducts);
        }

        if ($totalPages > 0) {
            $pagesProcessed = min($pagesProcessed, $totalPages);
        }

        $progressPercent = $totalProducts > 0
            ? min(100, (int) round(($processedProducts / $totalProducts) * 100))
            : 0;

        if ((string) ($job['status'] ?? '') === 'completed') {
            if ($totalProducts > 0) {
                $processedProducts = $totalProducts;
                $progressPercent = 100;
            }
            if ($totalPages > 0) {
                $pagesProcessed = $totalPages;
            }
        }

        return [
            'processed_products' => $processedProducts,
            'total_products' => $totalProducts,
            'pages_processed' => $pagesProcessed,
            'total_pages' => $totalPages,
            'progress_percent' => $progressPercent,
            'status_text' => $this->formatSyncStatusText($job, [
                'processed_products' => $processedProducts,
                'total_products' => $totalProducts,
                'pages_processed' => $pagesProcessed,
                'total_pages' => $totalPages,
                'progress_percent' => $progressPercent,
            ]),
        ];
    }

    /**
     * Formats the sync status line shown in the inventory screen.
     */
    private function formatSyncStatusText(array $job, array $progress): string
    {
        $parts = [
            'Estado: ' . (string) ($job['status'] ?? 'idle'),
        ];

        if ((int) ($progress['total_products'] ?? 0) > 0) {
            $parts[] = 'Productos: ' . (int) ($progress['processed_products'] ?? 0) . '/' . (int) ($progress['total_products'] ?? 0);
            $parts[] = 'Progreso: ' . (int) ($progress['progress_percent'] ?? 0) . '%';
        } else {
            $parts[] = 'Productos procesados: ' . (int) ($progress['processed_products'] ?? 0);
        }

        if ((int) ($progress['total_pages'] ?? 0) > 0) {
            $parts[] = 'Páginas: ' . (int) ($progress['pages_processed'] ?? 0) . '/' . (int) ($progress['total_pages'] ?? 0);
        }

        $parts[] = 'Filas guardadas: ' . (int) ($job['inserted_count'] ?? 0);

        $message = trim((string) ($job['message'] ?? ''));
        if ($message !== '') {
            $parts[] = $message;
        }

        return implode(' | ', $parts);
    }

    /**
     * Returns the default pagination payload for an empty inventory screen.
     */
    private function defaultInventoryPagination(): array
    {
        return [
            'page' => 1,
            'per_page' => 25,
            'per_page_query' => '25',
            'total_products' => 0,
            'total_pages' => 1,
            'offset' => 0,
        ];
    }

    /**
     * Returns the requested inventory page number.
     */
    private function currentInventoryPage(): int
    {
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        return max(1, $page);
    }

    /**
     * Returns the requested inventory page size or null for all results.
     */
    private function currentInventoryPerPage(): ?int
    {
        $raw = strtolower(trim((string) ($_GET['per_page'] ?? '25')));
        if ($raw === 'all' || $raw === 'todos') {
            return null;
        }

        $value = (int) $raw;
        if (!in_array($value, [25, 50, 100, 200], true)) {
            return 25;
        }

        return $value;
    }

    /**
     * Builds the inventory query string for redirects and links.
     */
    private function currentInventoryQueryString(?int $page = null, ?string $perPage = null): string
    {
        $pageValue = $page ?? $this->currentInventoryPage();
        $perPageValue = $perPage ?? $this->currentInventoryPerPageQuery();

        return '?' . http_build_query([
            'page' => max(1, $pageValue),
            'per_page' => $perPageValue,
        ]);
    }

    /**
     * Returns the current page-size value in query-string form.
     */
    private function currentInventoryPerPageQuery(): string
    {
        $perPage = $this->currentInventoryPerPage();
        return $perPage === null ? 'all' : (string) $perPage;
    }

    /**
     * Loads one inventory page and its pagination metadata.
     */
    private function fetchInventoryPage(int $storeId, int $page, ?int $perPage): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT product_id)
             FROM inventory_items
             WHERE store_id = :store_id'
        );
        $stmt->execute([':store_id' => $storeId]);
        $totalProducts = (int) $stmt->fetchColumn();

        $pagination = [
            'page' => 1,
            'per_page' => $perPage,
            'per_page_query' => $perPage === null ? 'all' : (string) $perPage,
            'total_products' => $totalProducts,
            'total_pages' => 1,
            'offset' => 0,
        ];

        if ($totalProducts === 0) {
            return [
                'items' => [],
                'pagination' => $pagination,
            ];
        }

        $totalPages = $perPage === null ? 1 : max(1, (int) ceil($totalProducts / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = $perPage === null ? 0 : (($page - 1) * $perPage);
        $pagination['page'] = $page;
        $pagination['total_pages'] = $totalPages;
        $pagination['offset'] = $offset;

        $productIdSql = 'SELECT DISTINCT product_id
                         FROM inventory_items
                         WHERE store_id = :store_id
                         ORDER BY product_id ASC';
        if ($perPage !== null) {
            $productIdSql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        }

        $stmt = $this->db->prepare($productIdSql);
        $stmt->execute([':store_id' => $storeId]);
        $productIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (empty($productIds)) {
            return [
                'items' => [],
                'pagination' => $pagination,
            ];
        }

        $rows = $this->fetchInventoryRowsByProductIds($storeId, $productIds);

        return [
            'items' => $this->groupInventoryRows($rows),
            'pagination' => $pagination,
        ];
    }

    /**
     * Fetches all inventory rows needed for the requested product groups.
     */
    private function fetchInventoryRowsByProductIds(int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $params = [':store_id' => $storeId];
        $placeholders = [];

        foreach (array_values($productIds) as $index => $productId) {
            $key = ':product_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = (int) $productId;
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM inventory_items
             WHERE store_id = :store_id
               AND product_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY product_id ASC, variation_id ASC, product_name ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Groups inventory rows under their parent product for rendering.
     */
    private function groupInventoryRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $isVariation = !empty($row['variation_id']);

            if (!isset($grouped[$productId])) {
                $grouped[$productId] = [
                    'product' => null,
                    'variations' => [],
                ];
            }

            if ($isVariation) {
                $grouped[$productId]['variations'][] = $row;
                continue;
            }

            $grouped[$productId]['product'] = $row;
        }

        $inventory = [];
        foreach ($grouped as $productId => $item) {
            if ($item['product'] === null && !empty($item['variations'])) {
                $first = $item['variations'][0];
                $item['product'] = [
                    'product_id' => $productId,
                    'variation_id' => null,
                    'sku' => '',
                    'product_name' => (string) ($first['parent_name'] ?? 'Producto variable'),
                    'price' => '',
                    'stock' => '',
                    'image_url' => (string) ($first['image_url'] ?? ''),
                ];
            }

            if ($item['product'] !== null) {
                $inventory[] = $item;
            }
        }

        return $inventory;
    }

    /**
     * Executes the next batch of the active inventory synchronization job.
     */
    private function runInventorySyncBatch(int $userId): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            jsonResponse(['ok' => false, 'error' => 'Primero configura la conexión de tienda.'], 422);
            return;
        }

        $job = $this->getLatestSyncJob((int) $store['id']);
        if (!$job || (string) $job['status'] !== 'running') {
            jsonResponse(['ok' => false, 'error' => 'No hay sincronización activa.'], 422);
            return;
        }

        try {
            $currentPage = max(1, (int) $job['current_page']);
            $batch = $this->fetchWooProductsPage($store, $currentPage, 12);
            $totalProducts = max((int) ($job['total_products'] ?? 0), (int) ($batch['total_products'] ?? 0));
            $totalPages = max((int) ($job['total_pages'] ?? 0), (int) ($batch['total_pages'] ?? 0));

            if ((int) ($batch['product_count'] ?? 0) === 0) {
                $this->finalizeInventorySync((int) $store['id'], (string) $job['sync_token']);
                $this->updateSyncJob((int) $job['id'], [
                    'status' => 'completed',
                    'total_products' => $totalProducts,
                    'total_pages' => $totalPages,
                    'message' => 'Sincronización finalizada.',
                ]);

                $done = $this->getLatestSyncJob((int) $store['id']);
                $progress = $done ? $this->buildSyncJobProgress($done) : null;
                jsonResponse([
                    'ok' => true,
                    'status' => 'completed',
                    'job_type' => (string) ($done['job_type'] ?? 'sync_full'),
                    'inserted_count' => (int) ($done['inserted_count'] ?? 0),
                    'processed_products' => (int) ($done['processed_products'] ?? 0),
                    'total_products' => (int) ($done['total_products'] ?? 0),
                    'total_pages' => (int) ($done['total_pages'] ?? 0),
                    'pages_processed' => (int) (($progress['pages_processed'] ?? 0)),
                    'progress_percent' => (int) (($progress['progress_percent'] ?? 0)),
                    'message' => (string) ($done['message'] ?? 'Sincronización finalizada.'),
                    'status_text' => (string) (($progress['status_text'] ?? '')),
                ]);
                return;
            }

            $inserted = $this->upsertInventoryBatch(
                (int) $store['id'],
                (string) $job['sync_token'],
                $batch['items']
            );
            $nextInsertedCount = ((int) $job['inserted_count']) + $inserted;
            $nextProcessedProducts = ((int) $job['processed_products']) + $batch['product_count'];

            $this->updateSyncJob((int) $job['id'], [
                'current_page' => $currentPage + 1,
                'inserted_count' => $nextInsertedCount,
                'processed_products' => $nextProcessedProducts,
                'total_products' => $totalProducts,
                'total_pages' => $totalPages,
                'message' => 'Página ' . $currentPage . ' procesada.',
            ]);

            $job['current_page'] = $currentPage + 1;
            $job['inserted_count'] = $nextInsertedCount;
            $job['processed_products'] = $nextProcessedProducts;
            $job['total_products'] = $totalProducts;
            $job['total_pages'] = $totalPages;
            $progress = $this->buildSyncJobProgress($job);

            jsonResponse([
                'ok' => true,
                'status' => 'running',
                'job_type' => (string) ($job['job_type'] ?? 'sync_full'),
                'current_page' => $currentPage + 1,
                'inserted_count' => $nextInsertedCount,
                'processed_products' => $nextProcessedProducts,
                'total_products' => $totalProducts,
                'total_pages' => $totalPages,
                'pages_processed' => (int) $progress['pages_processed'],
                'progress_percent' => (int) $progress['progress_percent'],
                'batch_products' => $batch['product_count'],
                'batch_rows' => $inserted,
                'message' => 'Procesando...',
                'status_text' => (string) $progress['status_text'],
            ]);
        } catch (Throwable $error) {
            $this->updateSyncJob((int) $job['id'], [
                'status' => 'failed',
                'message' => 'Error: ' . substr($error->getMessage(), 0, 250),
            ]);

            jsonResponse([
                'ok' => false,
                'status' => 'failed',
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    /**
     * Returns the current sync job status for the inventory screen.
     */
    private function inventorySyncStatus(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            jsonResponse(['ok' => false, 'error' => 'Sin tienda configurada.'], 422);
            return;
        }

        $job = $this->getLatestSyncJob((int) $store['id']);
        if (!$job) {
            jsonResponse(['ok' => true, 'status' => 'idle']);
            return;
        }

        $progress = $this->buildSyncJobProgress($job);
        jsonResponse([
            'ok' => true,
            'status' => (string) $job['status'],
            'job_type' => (string) ($job['job_type'] ?? 'sync_full'),
            'current_page' => (int) $job['current_page'],
            'inserted_count' => (int) $job['inserted_count'],
            'processed_products' => (int) $job['processed_products'],
            'total_products' => (int) ($job['total_products'] ?? 0),
            'total_pages' => (int) ($job['total_pages'] ?? 0),
            'pages_processed' => (int) $progress['pages_processed'],
            'progress_percent' => (int) $progress['progress_percent'],
            'message' => (string) $job['message'],
            'status_text' => (string) $progress['status_text'],
            'updated_at' => (string) $job['updated_at'],
        ]);
    }

    /**
     * Fetches one page of WooCommerce products and flattens them into inventory rows.
     */
    private function fetchWooProductsPage(array $store, int $page, int $perPage): array
    {
        $items = [];
        $productCount = 0;

        $path = '/wp-json/wc/v3/products?per_page=' . $perPage . '&page=' . $page . '&status=publish';
        $response = $this->requestWooJson($store, $path, true);
        $decoded = $response['body'];

        foreach ($decoded as $product) {
            if (!is_array($product)) {
                continue;
            }

            $productCount++;
            $images = isset($product['images']) && is_array($product['images']) ? $product['images'] : [];
            $firstImage = is_array($images[0] ?? null) ? (string) ($images[0]['src'] ?? '') : '';
            $productId = (int) ($product['id'] ?? 0);
            $productName = (string) ($product['name'] ?? 'Sin nombre');

            $productType = (string) ($product['type'] ?? '');
            if ($productType !== 'variable') {
                if (!$this->isWooItemInStock($product)) {
                    continue;
                }

                $items[] = [
                    'product_id' => $productId,
                    'variation_id' => null,
                    'sku' => (string) ($product['sku'] ?? ''),
                    'product_name' => $productName,
                    'parent_name' => $productName,
                    'price' => (string) ($product['price'] ?? ''),
                    'stock' => isset($product['stock_quantity']) && $product['stock_quantity'] !== null
                        ? (string) $product['stock_quantity']
                        : ((bool) ($product['in_stock'] ?? false) ? 'En stock' : 'Sin stock'),
                    'image_url' => $firstImage,
                ];
                continue;
            }

            $variations = $this->fetchWooVariations($store, $productId);
            $inStockVariations = [];
            foreach ($variations as $variation) {
                if (!$this->isWooItemInStock($variation)) {
                    continue;
                }

                $varImage = '';
                if (isset($variation['image']) && is_array($variation['image'])) {
                    $varImage = (string) ($variation['image']['src'] ?? '');
                }

                $attributes = [];
                $varAttrs = isset($variation['attributes']) && is_array($variation['attributes']) ? $variation['attributes'] : [];
                foreach ($varAttrs as $attr) {
                    if (!is_array($attr)) {
                        continue;
                    }

                    $name = trim((string) ($attr['name'] ?? ''));
                    $option = trim((string) ($attr['option'] ?? ''));
                    if ($name !== '' && $option !== '') {
                        $attributes[] = $name . ': ' . $option;
                    } elseif ($option !== '') {
                        $attributes[] = $option;
                    }
                }

                $variationLabel = empty($attributes)
                    ? ('Variación #' . (int) ($variation['id'] ?? 0))
                    : implode(' / ', $attributes);

                $inStockVariations[] = [
                    'product_id' => $productId,
                    'variation_id' => (int) ($variation['id'] ?? 0),
                    'sku' => (string) ($variation['sku'] ?? ''),
                    'product_name' => $variationLabel,
                    'parent_name' => $productName,
                    'price' => (string) ($variation['price'] ?? ''),
                    'stock' => isset($variation['stock_quantity']) && $variation['stock_quantity'] !== null
                        ? (string) $variation['stock_quantity']
                        : ((bool) ($variation['in_stock'] ?? false) ? 'En stock' : 'Sin stock'),
                    'image_url' => $varImage !== '' ? $varImage : $firstImage,
                ];
            }

            if (!empty($inStockVariations)) {
                $items[] = [
                    'product_id' => $productId,
                    'variation_id' => null,
                    'sku' => (string) ($product['sku'] ?? ''),
                    'product_name' => $productName,
                    'parent_name' => $productName,
                    'price' => (string) ($product['price'] ?? ''),
                    'stock' => 'Con variaciones en stock',
                    'image_url' => $firstImage,
                ];

                foreach ($inStockVariations as $row) {
                    $items[] = $row;
                }
            }
        }

        return [
            'items' => $items,
            'product_count' => $productCount,
            'total_products' => (int) ($response['headers']['x-wp-total'] ?? 0),
            'total_pages' => (int) ($response['headers']['x-wp-totalpages'] ?? 0),
        ];
    }

    /**
     * Determines whether a WooCommerce product or variation is currently in stock.
     */
    private function isWooItemInStock(array $item): bool
    {
        if (isset($item['stock_quantity']) && $item['stock_quantity'] !== null) {
            return (int) $item['stock_quantity'] > 0;
        }

        if (isset($item['stock_status'])) {
            return (string) $item['stock_status'] === 'instock';
        }

        return (bool) ($item['in_stock'] ?? false);
    }

    /**
     * Fetches every variation for a variable WooCommerce product.
     */
    private function fetchWooVariations(array $store, int $productId): array
    {
        $all = [];
        $page = 1;

        while (true) {
            $path = '/wp-json/wc/v3/products/' . $productId . '/variations?per_page=100&page=' . $page;
            $rows = $this->requestWooJson($store, $path);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            if (count($rows) < 100) {
                break;
            }

            $page++;
        }

        return $all;
    }

    /**
     * Performs an authenticated WooCommerce JSON request.
     */
    private function requestWooJson(array $store, string $path, bool $includeMeta = false): array
    {
        $url = rtrim((string) $store['site_url'], '/') . $path;
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => (string) $store['api_key'] . ':' . (string) $store['api_secret'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($headerLine);
            },
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Error de red: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta inválida de WooCommerce.');
        }

        if ($code >= 300) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : 'Error de WooCommerce';
            throw new RuntimeException($message);
        }

        if ($includeMeta) {
            return [
                'body' => $decoded,
                'headers' => $responseHeaders,
            ];
        }

        return $decoded;
    }

    /**
     * Replaces the current batch of inventory rows with the latest synced values.
     */
    private function upsertInventoryBatch(int $storeId, string $syncToken, array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        $this->db->beginTransaction();
        $written = 0;

        try {
            $deleteOne = $this->db->prepare(
                'DELETE FROM inventory_items
                 WHERE store_id = :store_id
                   AND product_id = :product_id
                   AND (
                     (variation_id IS NULL AND :variation_id_null IS NULL) OR
                     variation_id = :variation_id_eq
                   )'
            );

            $insert = $this->db->prepare(
                'INSERT INTO inventory_items (store_id, product_id, variation_id, sku, product_name, parent_name, price, stock, image_url, sync_token)
                 VALUES (:store_id, :product_id, :variation_id, :sku, :product_name, :parent_name, :price, :stock, :image_url, :sync_token)'
            );

            foreach ($items as $item) {
                $variationId = $item['variation_id'] ?: null;
                $deleteOne->execute([
                    ':store_id' => $storeId,
                    ':product_id' => $item['product_id'],
                    ':variation_id_null' => $variationId,
                    ':variation_id_eq' => $variationId,
                ]);

                $insert->execute([
                    ':store_id' => $storeId,
                    ':product_id' => $item['product_id'],
                    ':variation_id' => $variationId,
                    ':sku' => $item['sku'],
                    ':product_name' => $item['product_name'],
                    ':parent_name' => $item['parent_name'],
                    ':price' => $item['price'],
                    ':stock' => $item['stock'],
                    ':image_url' => $item['image_url'],
                    ':sync_token' => $syncToken,
                ]);
                $written++;
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $written;
    }

    /**
     * Removes stale inventory rows that were not seen in the latest sync token.
     */
    private function finalizeInventorySync(int $storeId, string $syncToken): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM inventory_items
             WHERE store_id = :store_id
               AND (sync_token IS NULL OR sync_token != :sync_token)'
        );
        $stmt->execute([
            ':store_id' => $storeId,
            ':sync_token' => $syncToken,
        ]);
    }

    /**
     * Returns the most recent sync job for the given store.
     */
    private function getLatestSyncJob(int $storeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM sync_jobs
             WHERE store_id = :store_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':store_id' => $storeId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Updates a sync job with the provided field values.
     */
    private function updateSyncJob(int $jobId, array $data): void
    {
        $parts = [];
        $params = [':id' => $jobId];

        foreach ($data as $key => $value) {
            $parts[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }

        $parts[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE sync_jobs SET ' . implode(', ', $parts) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Registers or updates a store connection from the public plugin endpoint.
     */
    private function pluginConnect(): void
    {
        $body = parseJsonBody();

        $required = ['site_url', 'api_key', 'api_secret'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                jsonResponse(['error' => "Missing field: {$field}"], 422);
                return;
            }
        }

        $slug = isset($body['slug']) ? preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) $body['slug'])) : null;
        $name = isset($body['name']) ? trim((string) $body['name']) : null;
        $orderStatus = isset($body['order_status']) && in_array($body['order_status'], ['pending', 'on-hold'], true)
            ? $body['order_status']
            : 'pending';

        $existing = $this->findStoreByApiKey((string) $body['api_key']);

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE stores
                 SET site_url = :site_url,
                     api_secret = :api_secret,
                     slug = COALESCE(:slug, slug),
                     name = COALESCE(:name, name),
                     order_status = :order_status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $stmt->execute([
                ':site_url' => (string) $body['site_url'],
                ':api_secret' => (string) $body['api_secret'],
                ':slug' => $slug,
                ':name' => $name,
                ':order_status' => $orderStatus,
                ':id' => $existing['id'],
            ]);

            $storeId = (int) $existing['id'];
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO stores (slug, name, site_url, api_key, api_secret, order_status)
                 VALUES (:slug, :name, :site_url, :api_key, :api_secret, :order_status)'
            );

            $stmt->execute([
                ':slug' => $slug,
                ':name' => $name,
                ':site_url' => (string) $body['site_url'],
                ':api_key' => (string) $body['api_key'],
                ':api_secret' => (string) $body['api_secret'],
                ':order_status' => $orderStatus,
            ]);

            $storeId = (int) $this->db->lastInsertId();
        }

        $live = $this->findLiveSession($storeId);
        if (!$live) {
            $stmt = $this->db->prepare('INSERT INTO live_sessions (store_id, is_live) VALUES (:store_id, 0)');
            $stmt->execute([':store_id' => $storeId]);
        }

        jsonResponse([
            'ok' => true,
            'store_id' => $storeId,
            'message' => 'Store connected successfully',
        ]);
    }

    /**
     * Returns the public live session payload consumed by the storefront widget.
     */
    private function livePublic(int $storeId): void
    {
        $this->livePublicSnapshot($storeId);
    }

    /**
     * Returns the public snapshot payload consumed when the widget boots.
     */
    private function livePublicSnapshot(int $storeId): void
    {
        $store = $this->findStoreById($storeId);
        if (!$store) {
            jsonResponse(['error' => 'Store not found'], 404);
            return;
        }

        jsonResponse($this->buildLivePublicSnapshotPayload($storeId));
    }

    /**
     * Returns the incremental live changes emitted after the requested revision.
     */
    private function livePublicDelta(int $storeId): void
    {
        $store = $this->findStoreById($storeId);
        if (!$store) {
            jsonResponse(['error' => 'Store not found'], 404);
            return;
        }

        $body = parseJsonBody();
        $clientSessionKey = trim((string) ($body['live_session_key'] ?? ''));
        $sinceRevision = max(0, (int) ($body['since_revision'] ?? 0));
        $snapshot = $this->buildLivePublicSnapshotPayload($storeId);

        if (empty($snapshot['is_live'])) {
            jsonResponse([
                'is_live' => false,
                'live_session_id' => null,
                'live_session_key' => '',
                'revision' => 0,
                'active_item_key' => null,
                'events' => [],
                'reset' => ($clientSessionKey !== '' || $sinceRevision > 0),
                'poll_interval_ms' => 5000,
            ]);
            return;
        }

        $currentSessionKey = (string) ($snapshot['live_session_key'] ?? '');
        $currentRevision = (int) ($snapshot['revision'] ?? 0);

        if ($clientSessionKey === '' || $clientSessionKey !== $currentSessionKey || $sinceRevision > $currentRevision) {
            jsonResponse([
                'is_live' => true,
                'live_session_id' => $snapshot['live_session_id'],
                'live_session_key' => $currentSessionKey,
                'revision' => $currentRevision,
                'active_item_key' => $snapshot['active_item_key'],
                'youtube_url' => $snapshot['youtube_url'],
                'youtube_video_id' => $snapshot['youtube_video_id'],
                'events' => [],
                'reset' => true,
                'poll_interval_ms' => 5000,
            ]);
            return;
        }

        $events = [];
        foreach (($snapshot['items'] ?? []) as $item) {
            if ((int) ($item['last_revision'] ?? 0) > $sinceRevision) {
                $events[] = $item;
            }
        }

        jsonResponse([
            'is_live' => true,
            'live_session_id' => $snapshot['live_session_id'],
            'live_session_key' => $currentSessionKey,
            'revision' => $currentRevision,
            'active_item_key' => $snapshot['active_item_key'],
            'youtube_url' => $snapshot['youtube_url'],
            'youtube_video_id' => $snapshot['youtube_video_id'],
            'product' => $snapshot['product'],
            'events' => $events,
            'reset' => false,
            'poll_interval_ms' => 5000,
        ]);
    }

    /**
     * Creates a pending order intent from the storefront widget payload.
     */
    private function createPendingOrder(): void
    {
        $body = parseJsonBody();

        $required = ['store_id', 'widget_session_id', 'customer_name', 'phone', 'product_id'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                jsonResponse(['error' => "Missing field: {$field}"], 422);
                return;
            }
        }

        $storeId = (int) $body['store_id'];
        $store = $this->findStoreById($storeId);

        if (!$store) {
            jsonResponse(['error' => 'Store not found'], 404);
            return;
        }

        $liveSession = $this->findLiveSession($storeId);
        $liveSessionId = $liveSession ? (int) $liveSession['id'] : null;

        $payload = [
            'customer_name' => trim((string) $body['customer_name']),
            'phone' => trim((string) $body['phone']),
            'email' => isset($body['email']) ? trim((string) $body['email']) : '',
            'city' => isset($body['city']) ? trim((string) $body['city']) : '',
            'notes' => isset($body['notes']) ? trim((string) $body['notes']) : '',
            'product_id' => (int) $body['product_id'],
            'variation_id' => !empty($body['variation_id']) ? (int) $body['variation_id'] : null,
            'qty' => !empty($body['qty']) ? max(1, (int) $body['qty']) : 1,
            'live_session_id' => $liveSessionId,
            'widget_session_id' => trim((string) $body['widget_session_id']),
            'source' => 'livepro_widget',
            'order_status' => $store['order_status'],
        ];

        $intentId = $this->insertIntentOrder($storeId, $payload);

        try {
            $wooResponse = $this->callWooOrderEndpoint($store, $payload);
            $this->markIntentAsCreated($intentId, (int) $wooResponse['order_id']);

            jsonResponse([
                'ok' => true,
                'message' => 'Pedido recibido, te contactaremos para finalizar la compra.',
                'intent_id' => $intentId,
                'woo_order_id' => (int) $wooResponse['order_id'],
                'status' => (string) $wooResponse['status'],
            ]);
        } catch (Throwable $error) {
            $this->markIntentAsFailed($intentId, $error->getMessage());

            jsonResponse([
                'ok' => false,
                'error' => 'No se pudo crear el pedido pendiente. Intenta nuevamente.',
                'intent_id' => $intentId,
                'details' => $error->getMessage(),
            ], 502);
        }
    }

    /**
     * Persists a pending order intent and returns its identifier.
     */
    private function insertIntentOrder(int $storeId, array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO intent_orders (
                store_id,
                live_session_id,
                widget_session_id,
                customer_name,
                phone,
                email,
                city,
                notes,
                product_id,
                variation_id,
                qty,
                status
            ) VALUES (
                :store_id,
                :live_session_id,
                :widget_session_id,
                :customer_name,
                :phone,
                :email,
                :city,
                :notes,
                :product_id,
                :variation_id,
                :qty,
                :status
            )'
        );

        $stmt->execute([
            ':store_id' => $storeId,
            ':live_session_id' => $payload['live_session_id'],
            ':widget_session_id' => $payload['widget_session_id'],
            ':customer_name' => $payload['customer_name'],
            ':phone' => $payload['phone'],
            ':email' => $payload['email'],
            ':city' => $payload['city'],
            ':notes' => $payload['notes'],
            ':product_id' => $payload['product_id'],
            ':variation_id' => $payload['variation_id'],
            ':qty' => $payload['qty'],
            ':status' => 'processing_local',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Marks an intent order as created after WooCommerce accepts it.
     */
    private function markIntentAsCreated(int $intentId, int $wooOrderId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE intent_orders
             SET status = :status,
                 woo_order_id = :woo_order_id,
                 error_message = NULL
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => 'created',
            ':woo_order_id' => $wooOrderId,
            ':id' => $intentId,
        ]);
    }

    /**
     * Marks an intent order as failed and stores the error summary.
     */
    private function markIntentAsFailed(int $intentId, string $error): void
    {
        $stmt = $this->db->prepare(
            'UPDATE intent_orders
             SET status = :status,
                 error_message = :error
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => 'failed',
            ':error' => substr($error, 0, 500),
            ':id' => $intentId,
        ]);
    }

    /**
     * Sends a pending-order payload to the WooCommerce integration endpoint.
     */
    private function callWooOrderEndpoint(array $store, array $payload): array
    {
        $endpoint = rtrim((string) $store['site_url'], '/') . '/wp-json/livepro/v1/order-pending';
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonBody === false) {
            throw new RuntimeException('Failed to encode order payload');
        }

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $jsonBody, (string) $store['api_secret']);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-LivePro-Key: ' . $store['api_key'],
                'X-LivePro-Timestamp: ' . $timestamp,
                'X-LivePro-Signature: ' . $signature,
            ],
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_TIMEOUT => 12,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Woo request failed: ' . $curlErr);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Woo response');
        }

        if ($httpCode >= 300 || empty($decoded['ok'])) {
            $message = $decoded['error'] ?? 'Woo endpoint rejected request';
            throw new RuntimeException((string) $message);
        }

        return $decoded;
    }

    /**
     * Applies the CORS headers required by public API endpoints.
     */
    private function setCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, X-LivePro-Key, X-LivePro-Timestamp, X-LivePro-Signature');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    /**
     * Finds the latest store connected by the given user.
     */
    private function findStoreByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stores WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $store = $stmt->fetch();

        return $store ?: null;
    }

    /**
     * Finds a store by its WooCommerce API key.
     */
    private function findStoreByApiKey(string $apiKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stores WHERE api_key = :api_key LIMIT 1');
        $stmt->execute([':api_key' => $apiKey]);
        $store = $stmt->fetch();

        return $store ?: null;
    }

    /**
     * Finds a store by its primary identifier.
     */
    private function findStoreById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stores WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $store = $stmt->fetch();

        return $store ?: null;
    }

    /**
     * Finds the live session record for a store.
     */
    private function findLiveSession(int $storeId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM live_sessions WHERE store_id = :store_id LIMIT 1');
        $stmt->execute([':store_id' => $storeId]);
        $session = $stmt->fetch();

        return $session ?: null;
    }

    /**
     * Finds parent inventory products for the live console catalog.
     */
    private function findInventoryParents(int $storeId, string $query = ''): array
    {
        $sql = 'SELECT *
                FROM inventory_items
                WHERE store_id = :store_id
                  AND variation_id IS NULL';
        $params = [':store_id' => $storeId];

        if ($query !== '') {
            $sql .= ' AND (LOWER(product_name) LIKE :q OR LOWER(sku) LIKE :q)';
            $params[':q'] = '%' . strtolower($query) . '%';
        }

        $sql .= ' ORDER BY updated_at DESC, product_name ASC LIMIT 200';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Finds the parent inventory row for a specific WooCommerce product.
     */
    private function findInventoryParentByProductId(int $storeId, int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM inventory_items
             WHERE store_id = :store_id
               AND product_id = :product_id
               AND variation_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':store_id' => $storeId,
            ':product_id' => $productId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Returns the active search query used by the live console.
     */
    private function currentLiveConsoleQuery(): string
    {
        return trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));
    }

    /**
     * Builds the complete live console state payload for the UI.
     */
    private function buildLiveConsoleState(int $storeId, string $query = ''): array
    {
        $query = trim($query);
        $sessionKey = 'live_console_selected_' . $storeId;
        $selectedProductId = isset($_SESSION[$sessionKey]) ? (int) $_SESSION[$sessionKey] : 0;
        $selectedProduct = $selectedProductId > 0
            ? $this->findInventoryParentByProductId($storeId, $selectedProductId)
            : null;
        $liveSession = $this->findLiveSession($storeId);
        $publicSessionKey = trim((string) ($liveSession['public_session_key'] ?? ''));

        return [
            'query' => $query,
            'selectedProductId' => $selectedProductId,
            'selectedProduct' => $selectedProduct ? $this->serializeInventoryProduct($selectedProduct) : null,
            'catalog' => $this->serializeCatalogItems($this->findInventoryParents($storeId, $query), $selectedProductId),
            'emissionQueue' => $this->serializeEmissionQueue($this->getEmissionQueue($storeId, $publicSessionKey)),
            'liveSession' => $this->serializeLiveSession($liveSession),
        ];
    }

    /**
     * Builds the complete public live snapshot used to bootstrap the widget.
     */
    private function buildLivePublicSnapshotPayload(int $storeId): array
    {
        $session = $this->findLiveSession($storeId);
        if (!$session || empty($session['is_live'])) {
            return [
                'is_live' => false,
                'live_session_id' => null,
                'live_session_key' => '',
                'revision' => 0,
                'youtube_url' => null,
                'youtube_video_id' => null,
                'active_item_key' => null,
                'product' => null,
                'items' => [],
                'poll_interval_ms' => 5000,
            ];
        }

        $session = $this->hydratePublicLiveSession($storeId, $session);
        $publicSessionKey = (string) ($session['public_session_key'] ?? '');
        $revision = (int) ($session['session_revision'] ?? 0);
        $activeProduct = $this->serializePublicLiveProduct($session, $revision);
        $items = $this->buildPublicLiveItems($this->getEmissionQueue($storeId, $publicSessionKey));

        if ($activeProduct) {
            $items = $this->mergeActivePublicProductIntoItems($items, $activeProduct);
        }

        if (!$activeProduct && !empty($items)) {
            $activeProduct = $items[count($items) - 1];
        }

        return [
            'is_live' => true,
            'live_session_id' => (int) $session['id'],
            'live_session_key' => $publicSessionKey,
            'revision' => $revision,
            'youtube_url' => $session['youtube_url'],
            'youtube_video_id' => $session['youtube_video_id'],
            'active_item_key' => $activeProduct['key'] ?? null,
            'product' => $activeProduct,
            'items' => $items,
            'poll_interval_ms' => 5000,
        ];
    }

    /**
     * Ensures the current live session has a public key before exposing it to the widget.
     */
    private function hydratePublicLiveSession(int $storeId, array $session): array
    {
        $publicSessionKey = trim((string) ($session['public_session_key'] ?? ''));
        if ($publicSessionKey !== '') {
            return $session;
        }

        $publicSessionKey = $this->generatePublicLiveSessionKey();
        $stmt = $this->db->prepare(
            'UPDATE live_sessions
             SET public_session_key = :public_session_key,
                 updated_at = CURRENT_TIMESTAMP
             WHERE store_id = :store_id'
        );
        $stmt->execute([
            ':public_session_key' => $publicSessionKey,
            ':store_id' => $storeId,
        ]);

        $session['public_session_key'] = $publicSessionKey;
        return $session;
    }

    /**
     * Builds the public product payload for the currently active live item.
     */
    private function serializePublicLiveProduct(array $session, int $revision): ?array
    {
        $productId = (int) ($session['active_product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        $variationId = !empty($session['active_variation_id']) ? (int) $session['active_variation_id'] : null;

        return [
            'key' => $this->buildPublicLiveItemKey($productId, $variationId),
            'product_id' => $productId,
            'variation_id' => $variationId,
            'name' => (string) ($session['active_product_name'] ?? ''),
            'price' => (string) ($session['active_price'] ?? ''),
            'image' => (string) ($session['active_image'] ?? ''),
            'times_emitted' => 1,
            'last_revision' => $revision,
            'last_emitted_at' => (string) ($session['updated_at'] ?? ''),
        ];
    }

    /**
     * Collapses raw queue rows into unique public live items ordered by latest emission.
     */
    private function buildPublicLiveItems(array $rows): array
    {
        $itemsByKey = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variationId = !empty($row['variation_id']) ? (int) $row['variation_id'] : null;
            $key = $this->buildPublicLiveItemKey($productId, $variationId);
            $lastRevision = max(
                (int) ($row['event_revision'] ?? 0),
                (int) ($row['id'] ?? 0)
            );

            if (!isset($itemsByKey[$key])) {
                $itemsByKey[$key] = [
                    'key' => $key,
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'name' => (string) ($row['product_name'] ?? ''),
                    'price' => (string) ($row['price'] ?? ''),
                    'image' => (string) ($row['image_url'] ?? ''),
                    'times_emitted' => 0,
                    'last_revision' => 0,
                    'last_emitted_at' => '',
                ];
            }

            $itemsByKey[$key]['times_emitted']++;

            if ($lastRevision >= (int) $itemsByKey[$key]['last_revision']) {
                $itemsByKey[$key]['name'] = (string) ($row['product_name'] ?? $itemsByKey[$key]['name']);
                $itemsByKey[$key]['price'] = (string) ($row['price'] ?? $itemsByKey[$key]['price']);
                $itemsByKey[$key]['image'] = (string) ($row['image_url'] ?? $itemsByKey[$key]['image']);
                $itemsByKey[$key]['last_revision'] = $lastRevision;
                $itemsByKey[$key]['last_emitted_at'] = (string) ($row['created_at'] ?? $itemsByKey[$key]['last_emitted_at']);
            }
        }

        $items = array_values($itemsByKey);
        usort(static function (array $left, array $right): int {
            $leftRevision = (int) ($left['last_revision'] ?? 0);
            $rightRevision = (int) ($right['last_revision'] ?? 0);
            if ($leftRevision === $rightRevision) {
                return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
            }

            return $leftRevision <=> $rightRevision;
        });

        return $items;
    }

    /**
     * Ensures the active live product is present in the public item list.
     */
    private function mergeActivePublicProductIntoItems(array $items, array $activeProduct): array
    {
        $merged = false;

        foreach ($items as &$item) {
            if (($item['key'] ?? '') !== ($activeProduct['key'] ?? '')) {
                continue;
            }

            $item['name'] = (string) ($activeProduct['name'] ?? $item['name']);
            $item['price'] = (string) ($activeProduct['price'] ?? $item['price']);
            $item['image'] = (string) ($activeProduct['image'] ?? $item['image']);
            $item['last_revision'] = max((int) ($item['last_revision'] ?? 0), (int) ($activeProduct['last_revision'] ?? 0));
            $item['last_emitted_at'] = (string) ($activeProduct['last_emitted_at'] ?? $item['last_emitted_at']);
            $merged = true;
            break;
        }
        unset($item);

        if (!$merged) {
            $items[] = $activeProduct;
        }

        usort(static function (array $left, array $right): int {
            return ((int) ($left['last_revision'] ?? 0)) <=> ((int) ($right['last_revision'] ?? 0));
        });

        return $items;
    }

    /**
     * Builds a stable key for public live items.
     */
    private function buildPublicLiveItemKey(int $productId, ?int $variationId): string
    {
        return $productId . ':' . ($variationId ?: 0);
    }

    /**
     * Serializes catalog rows and marks the currently selected product.
     */
    private function serializeCatalogItems(array $rows, int $selectedProductId): array
    {
        $catalog = [];
        foreach ($rows as $row) {
            $item = $this->serializeInventoryProduct($row);
            $item['selected'] = (int) $item['product_id'] === $selectedProductId;
            $catalog[] = $item;
        }

        return $catalog;
    }

    /**
     * Serializes a single inventory product for frontend consumption.
     */
    private function serializeInventoryProduct(array $row): array
    {
        return [
            'product_id' => (int) ($row['product_id'] ?? 0),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'image_url' => (string) ($row['image_url'] ?? ''),
            'price' => (string) ($row['price'] ?? ''),
            'formatted_price' => formatPrice((string) ($row['price'] ?? '')),
        ];
    }

    /**
     * Serializes the emission queue rows for the live console.
     */
    private function serializeEmissionQueue(array $rows): array
    {
        $queue = [];
        foreach ($rows as $row) {
            $queue[] = [
                'id' => (int) ($row['id'] ?? 0),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'image_url' => (string) ($row['image_url'] ?? ''),
                'price' => (string) ($row['price'] ?? ''),
                'formatted_price' => formatPrice((string) ($row['price'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $queue;
    }

    /**
     * Serializes the live session status for frontend consumption.
     */
    private function serializeLiveSession(?array $session): array
    {
        if (!$session) {
            return [
                'is_live' => false,
                'youtube_video_id' => '',
                'public_session_key' => '',
                'session_revision' => 0,
            ];
        }

        return [
            'is_live' => !empty($session['is_live']),
            'youtube_video_id' => (string) ($session['youtube_video_id'] ?? ''),
            'public_session_key' => (string) ($session['public_session_key'] ?? ''),
            'session_revision' => (int) ($session['session_revision'] ?? 0),
        ];
    }

    /**
     * Removes all queued live-emission history entries for the store.
     */
    private function clearEmissionQueue(int $storeId): void
    {
        $session = $this->findLiveSession($storeId);
        $publicSessionKey = trim((string) ($session['public_session_key'] ?? ''));

        if ($publicSessionKey !== '') {
            $stmt = $this->db->prepare(
                'DELETE FROM live_emission_queue
                 WHERE store_id = :store_id
                   AND session_key = :session_key'
            );
            $stmt->execute([
                ':store_id' => $storeId,
                ':session_key' => $publicSessionKey,
            ]);

            $resetStmt = $this->db->prepare(
                'UPDATE live_sessions
                 SET public_session_key = :public_session_key,
                     session_revision = 0,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE store_id = :store_id'
            );
            $resetStmt->execute([
                ':public_session_key' => $this->generatePublicLiveSessionKey(),
                ':store_id' => $storeId,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM live_emission_queue
             WHERE store_id = :store_id'
        );
        $stmt->execute([':store_id' => $storeId]);
    }

    /**
     * Returns the latest launched products queued for the live console.
     */
    private function getEmissionQueue(int $storeId, string $publicSessionKey = ''): array
    {
        $sql = 'SELECT *
                FROM live_emission_queue
                WHERE store_id = :store_id';
        $params = [':store_id' => $storeId];

        if ($publicSessionKey !== '') {
            $sql .= ' AND session_key = :session_key';
            $params[':session_key'] = $publicSessionKey;
        }

        $sql .= ' ORDER BY event_revision DESC, id DESC LIMIT 100';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Ensures the store has an active public live session key before emitting events.
     */
    private function ensurePublicLiveSessionKey(int $storeId, ?array $session = null): string
    {
        $session = $session ?: $this->findLiveSession($storeId);
        $publicSessionKey = trim((string) ($session['public_session_key'] ?? ''));
        if ($publicSessionKey !== '') {
            return $publicSessionKey;
        }

        $publicSessionKey = $this->generatePublicLiveSessionKey();
        $stmt = $this->db->prepare(
            'UPDATE live_sessions
             SET public_session_key = :public_session_key,
                 updated_at = CURRENT_TIMESTAMP
             WHERE store_id = :store_id'
        );
        $stmt->execute([
            ':public_session_key' => $publicSessionKey,
            ':store_id' => $storeId,
        ]);

        return $publicSessionKey;
    }

    /**
     * Increments the public live revision after a visible emission event.
     */
    private function bumpLiveSessionRevision(int $storeId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE live_sessions
             SET session_revision = session_revision + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE store_id = :store_id'
        );
        $stmt->execute([':store_id' => $storeId]);

        $session = $this->findLiveSession($storeId);
        return (int) ($session['session_revision'] ?? 0);
    }

    /**
     * Generates a unique public identifier for a live run.
     */
    private function generatePublicLiveSessionKey(): string
    {
        return 'live_' . bin2hex(random_bytes(12));
    }
}
