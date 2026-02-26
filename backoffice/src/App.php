<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';

final class App
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function handle(string $method, string $path): void
    {
        if ($this->isApiRequest($path)) {
            $this->handleApi($method, $path);
            return;
        }

        $this->handleWeb($method, $path);
    }

    private function isApiRequest(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }

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

    private function requireUser(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            redirectTo('/login');
        }

        return $user;
    }

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

    private function renderLogin(): void
    {
        $flash = pullFlash();
        $title = 'Login | LivePro';
        include __DIR__ . '/../views/auth-login.php';
    }

    private function renderRegister(): void
    {
        $flash = pullFlash();
        $title = 'Registro | LivePro';
        include __DIR__ . '/../views/auth-register.php';
    }

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

    private function renderStoreConnection(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        $flash = pullFlash();
        $activeNav = 'store';
        $title = 'Conexión Tienda | LivePro';
        include __DIR__ . '/../views/app-store-connection.php';
    }

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

        if ($youtubeUrl === '' && $isLive === 1) {
            setFlash('error', 'Ingresa la URL de YouTube antes de iniciar el live.');
            redirectTo('/app/live-connection');
        }

        $stmt = $this->db->prepare(
            'UPDATE live_sessions
             SET youtube_url = :youtube_url,
                 youtube_video_id = :video_id,
                 is_live = :is_live,
                 updated_at = CURRENT_TIMESTAMP
             WHERE store_id = :store_id'
        );

        $stmt->execute([
            ':youtube_url' => $youtubeUrl,
            ':video_id' => extractYoutubeVideoId($youtubeUrl),
            ':is_live' => $isLive,
            ':store_id' => (int) $store['id'],
        ]);

        setFlash('success', $isLive ? 'Live iniciado.' : 'Live detenido.');
        redirectTo('/app/live-connection');
    }

    private function renderLiveConnection(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        $session = $store ? $this->findLiveSession((int) $store['id']) : null;
        $flash = pullFlash();
        $activeNav = 'live';
        $title = 'Conexión Live | LivePro';
        include __DIR__ . '/../views/app-live-connection.php';
    }

    private function handleLiveConsoleAction(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        $action = (string) ($_POST['action'] ?? '');
        $sessionKey = 'live_console_selected_' . (int) $store['id'];

        if ($action === 'select_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                setFlash('error', 'Selecciona un producto válido.');
                redirectTo('/app/live');
            }

            $_SESSION[$sessionKey] = $productId;
            setFlash('success', 'Producto cargado en lanzamiento activo.');
            redirectTo('/app/live');
        }

        if ($action === 'launch_product') {
            $selectedProductId = isset($_SESSION[$sessionKey]) ? (int) $_SESSION[$sessionKey] : 0;
            if ($selectedProductId <= 0) {
                setFlash('error', 'Selecciona un producto antes de poner en vivo.');
                redirectTo('/app/live');
            }

            $product = $this->findInventoryParentByProductId((int) $store['id'], $selectedProductId);
            if (!$product) {
                setFlash('error', 'El producto seleccionado no existe en inventario.');
                redirectTo('/app/live');
            }

            $live = $this->findLiveSession((int) $store['id']);
            if (!$live) {
                $insert = $this->db->prepare('INSERT INTO live_sessions (store_id, is_live) VALUES (:store_id, 0)');
                $insert->execute([':store_id' => (int) $store['id']]);
            }

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
                'INSERT INTO live_emission_queue (store_id, product_id, product_name, price, image_url)
                 VALUES (:store_id, :product_id, :product_name, :price, :image_url)'
            );
            $insertQueue->execute([
                ':store_id' => (int) $store['id'],
                ':product_id' => (int) $product['product_id'],
                ':product_name' => (string) $product['product_name'],
                ':price' => (string) $product['price'],
                ':image_url' => (string) $product['image_url'],
            ]);

            unset($_SESSION[$sessionKey]);

            setFlash('success', 'Producto enviado al vivo correctamente.');
            redirectTo('/app/live');
        }

        if ($action === 'clear_selected') {
            unset($_SESSION[$sessionKey]);
            setFlash('success', 'Lanzamiento activo limpiado.');
            redirectTo('/app/live');
        }

        setFlash('error', 'Acción inválida.');
        redirectTo('/app/live');
    }

    private function renderLiveConsole(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        if (!$store) {
            setFlash('error', 'Primero configura la conexión de tienda.');
            redirectTo('/app/store-connection');
        }

        $liveSession = $this->findLiveSession((int) $store['id']);
        $query = trim((string) ($_GET['q'] ?? ''));
        $catalog = $this->findInventoryParents((int) $store['id'], $query);

        $sessionKey = 'live_console_selected_' . (int) $store['id'];
        $selectedProductId = isset($_SESSION[$sessionKey]) ? (int) $_SESSION[$sessionKey] : 0;
        $selectedProduct = $selectedProductId > 0
            ? $this->findInventoryParentByProductId((int) $store['id'], $selectedProductId)
            : null;
        $emissionQueue = $this->getEmissionQueue((int) $store['id']);

        $flash = pullFlash();
        $activeNav = 'onair';
        $title = 'En Vivo | LivePro';
        include __DIR__ . '/../views/app-live-console.php';
    }

    private function startInventorySync(int $userId): void
    {
        $expectsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
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
                if ($expectsJson) {
                    jsonResponse(['ok' => true, 'status' => 'running', 'message' => 'Ya en curso']);
                    return;
                }
                setFlash('success', 'La sincronización ya está en curso.');
                redirectTo('/app/inventory');
            }

            $token = bin2hex(random_bytes(10));
            $stmt = $this->db->prepare(
                'INSERT INTO sync_jobs (store_id, status, sync_token, current_page, inserted_count, processed_products, message)
                 VALUES (:store_id, :status, :sync_token, :current_page, 0, 0, :message)'
            );
            $stmt->execute([
                ':store_id' => (int) $store['id'],
                ':status' => 'running',
                ':sync_token' => $token,
                ':current_page' => 1,
                ':message' => 'Iniciando sincronización por lotes...',
            ]);

            if ($expectsJson) {
                jsonResponse(['ok' => true, 'status' => 'running', 'message' => 'Sincronización iniciada']);
                return;
            }
            setFlash('success', 'Sincronización iniciada en segundo plano.');
        } catch (Throwable $error) {
            if ($expectsJson) {
                jsonResponse(['ok' => false, 'error' => $error->getMessage()], 500);
                return;
            }
            setFlash('error', 'No se pudo iniciar sincronización: ' . $error->getMessage());
        }

        redirectTo('/app/inventory');
    }

    private function renderInventory(int $userId): void
    {
        $store = $this->findStoreByUserId($userId);
        $inventory = [];
        $syncJob = null;

        if ($store) {
            $syncJob = $this->getLatestSyncJob((int) $store['id']);
            $stmt = $this->db->prepare(
                'SELECT * FROM inventory_items
                 WHERE store_id = :store_id
                 ORDER BY product_id ASC, variation_id ASC, product_name ASC
                 LIMIT 800'
            );
            $stmt->execute([':store_id' => (int) $store['id']]);
            $rows = $stmt->fetchAll();

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
        }

        $flash = pullFlash();
        $activeNav = 'inventory';
        $title = 'Inventario | LivePro';
        include __DIR__ . '/../views/app-inventory.php';
    }

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

            if (empty($batch)) {
                $this->finalizeInventorySync((int) $store['id'], (string) $job['sync_token']);
                $this->updateSyncJob((int) $job['id'], [
                    'status' => 'completed',
                    'message' => 'Sincronización finalizada.',
                ]);

                $done = $this->getLatestSyncJob((int) $store['id']);
                jsonResponse([
                    'ok' => true,
                    'status' => 'completed',
                    'inserted_count' => (int) ($done['inserted_count'] ?? 0),
                    'processed_products' => (int) ($done['processed_products'] ?? 0),
                    'message' => 'Sincronización finalizada.',
                ]);
                return;
            }

            $inserted = $this->upsertInventoryBatch(
                (int) $store['id'],
                (string) $job['sync_token'],
                $batch['items']
            );

            $this->updateSyncJob((int) $job['id'], [
                'current_page' => $currentPage + 1,
                'inserted_count' => ((int) $job['inserted_count']) + $inserted,
                'processed_products' => ((int) $job['processed_products']) + $batch['product_count'],
                'message' => 'Página ' . $currentPage . ' procesada.',
            ]);

            jsonResponse([
                'ok' => true,
                'status' => 'running',
                'current_page' => $currentPage + 1,
                'inserted_count' => ((int) $job['inserted_count']) + $inserted,
                'processed_products' => ((int) $job['processed_products']) + $batch['product_count'],
                'batch_products' => $batch['product_count'],
                'batch_rows' => $inserted,
                'message' => 'Procesando...',
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

        jsonResponse([
            'ok' => true,
            'status' => (string) $job['status'],
            'current_page' => (int) $job['current_page'],
            'inserted_count' => (int) $job['inserted_count'],
            'processed_products' => (int) $job['processed_products'],
            'message' => (string) $job['message'],
            'updated_at' => (string) $job['updated_at'],
        ]);
    }

    private function fetchWooProductsPage(array $store, int $page, int $perPage): array
    {
        $items = [];
        $productCount = 0;

        $path = '/wp-json/wc/v3/products?per_page=' . $perPage . '&page=' . $page . '&status=publish';
        $decoded = $this->requestWooJson($store, $path);

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
        ];
    }

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

    private function requestWooJson(array $store, string $path): array
    {
        $url = rtrim((string) $store['site_url'], '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => (string) $store['api_key'] . ':' . (string) $store['api_secret'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
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

        return $decoded;
    }

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

    private function livePublic(int $storeId): void
    {
        $store = $this->findStoreById($storeId);
        if (!$store) {
            jsonResponse(['error' => 'Store not found'], 404);
            return;
        }

        $session = $this->findLiveSession($storeId);
        if (!$session) {
            jsonResponse([
                'is_live' => false,
                'live_session_id' => null,
                'youtube_video_id' => null,
                'product' => null,
            ]);
            return;
        }

        $product = null;
        if (!empty($session['active_product_id'])) {
            $product = [
                'product_id' => (int) $session['active_product_id'],
                'variation_id' => $session['active_variation_id'] ? (int) $session['active_variation_id'] : null,
                'name' => $session['active_product_name'],
                'price' => $session['active_price'],
                'image' => $session['active_image'],
            ];
        }

        jsonResponse([
            'is_live' => (bool) $session['is_live'],
            'live_session_id' => (int) $session['id'],
            'youtube_url' => $session['youtube_url'],
            'youtube_video_id' => $session['youtube_video_id'],
            'product' => $product,
            'poll_interval_ms' => 5000,
        ]);
    }

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

    private function setCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, X-LivePro-Key, X-LivePro-Timestamp, X-LivePro-Signature');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }

    private function findStoreByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stores WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $store = $stmt->fetch();

        return $store ?: null;
    }

    private function findStoreByApiKey(string $apiKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stores WHERE api_key = :api_key LIMIT 1');
        $stmt->execute([':api_key' => $apiKey]);
        $store = $stmt->fetch();

        return $store ?: null;
    }

    private function findStoreById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stores WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $store = $stmt->fetch();

        return $store ?: null;
    }

    private function findLiveSession(int $storeId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM live_sessions WHERE store_id = :store_id LIMIT 1');
        $stmt->execute([':store_id' => $storeId]);
        $session = $stmt->fetch();

        return $session ?: null;
    }

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

    private function getEmissionQueue(int $storeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM live_emission_queue
             WHERE store_id = :store_id
             ORDER BY id DESC
             LIMIT 100'
        );
        $stmt->execute([':store_id' => $storeId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}
