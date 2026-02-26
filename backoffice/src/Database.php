<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = strtolower((string) Config::get('DB_DRIVER', 'sqlite'));
        self::$pdo = $driver === 'mysql' ? self::connectMysql() : self::connectSqlite();

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($driver === 'mysql') {
            self::migrateMysql(self::$pdo);
        } else {
            self::migrateSqlite(self::$pdo);
        }

        return self::$pdo;
    }

    private static function connectMysql(): PDO
    {
        if (PHP_VERSION_ID < 80000) {
            throw new RuntimeException('LivePro requiere PHP 8.0+ (actual: ' . PHP_VERSION . ')');
        }

        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('La extensión pdo_mysql no está habilitada en este hosting');
        }

        $host = (string) Config::get('DB_HOST', '127.0.0.1');
        $port = (string) Config::get('DB_PORT', '3306');
        $db = (string) Config::get('DB_NAME', 'livepro');
        $user = (string) Config::get('DB_USER', 'root');
        $pass = (string) Config::get('DB_PASS', '');
        $charset = (string) Config::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $db, $charset);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function connectSqlite(): PDO
    {
        $storagePath = __DIR__ . '/../storage/livepro.sqlite';
        $dsn = 'sqlite:' . $storagePath;

        return new PDO($dsn);
    }

    private static function migrateSqlite(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                slug TEXT UNIQUE,
                name TEXT,
                site_url TEXT NOT NULL,
                api_key TEXT NOT NULL UNIQUE,
                api_secret TEXT NOT NULL,
                order_status TEXT NOT NULL DEFAULT "pending",
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )'
        );

        self::ensureColumnSqlite($pdo, 'stores', 'user_id', 'INTEGER');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS live_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                youtube_url TEXT,
                youtube_video_id TEXT,
                is_live INTEGER NOT NULL DEFAULT 0,
                active_product_id INTEGER,
                active_variation_id INTEGER,
                active_product_name TEXT,
                active_price TEXT,
                active_image TEXT,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (store_id) REFERENCES stores(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS live_emission_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                product_name TEXT NOT NULL,
                price TEXT,
                image_url TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (store_id) REFERENCES stores(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                variation_id INTEGER,
                sku TEXT,
                product_name TEXT NOT NULL,
                parent_name TEXT,
                sync_token TEXT,
                price TEXT,
                stock TEXT,
                image_url TEXT,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (store_id, product_id, variation_id),
                FOREIGN KEY (store_id) REFERENCES stores(id)
            )'
        );
        self::ensureColumnSqlite($pdo, 'inventory_items', 'parent_name', 'TEXT');
        self::ensureColumnSqlite($pdo, 'inventory_items', 'sync_token', 'TEXT');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sync_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                sync_token TEXT NOT NULL,
                current_page INTEGER NOT NULL DEFAULT 1,
                inserted_count INTEGER NOT NULL DEFAULT 0,
                processed_products INTEGER NOT NULL DEFAULT 0,
                message TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (store_id) REFERENCES stores(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS intent_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                store_id INTEGER NOT NULL,
                live_session_id INTEGER,
                widget_session_id TEXT NOT NULL,
                customer_name TEXT NOT NULL,
                phone TEXT NOT NULL,
                email TEXT,
                city TEXT,
                notes TEXT,
                product_id INTEGER NOT NULL,
                variation_id INTEGER,
                qty INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL,
                woo_order_id INTEGER,
                error_message TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (store_id) REFERENCES stores(id),
                FOREIGN KEY (live_session_id) REFERENCES live_sessions(id)
            )'
        );
    }

    private static function migrateMysql(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(180) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stores (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                slug VARCHAR(190) NULL,
                name VARCHAR(190) NULL,
                site_url VARCHAR(255) NOT NULL,
                api_key VARCHAR(255) NOT NULL,
                api_secret VARCHAR(255) NOT NULL,
                order_status VARCHAR(20) NOT NULL DEFAULT "pending",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_stores_slug (slug),
                UNIQUE KEY uq_stores_api_key (api_key),
                KEY idx_stores_user_id (user_id),
                CONSTRAINT fk_stores_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::ensureColumnMysql($pdo, 'stores', 'user_id', 'INT UNSIGNED NULL');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS live_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NOT NULL,
                youtube_url TEXT NULL,
                youtube_video_id VARCHAR(100) NULL,
                is_live TINYINT(1) NOT NULL DEFAULT 0,
                active_product_id INT UNSIGNED NULL,
                active_variation_id INT UNSIGNED NULL,
                active_product_name VARCHAR(255) NULL,
                active_price VARCHAR(100) NULL,
                active_image TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_live_store_id (store_id),
                CONSTRAINT fk_live_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS live_emission_queue (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                price VARCHAR(100) NULL,
                image_url TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_queue_store_created (store_id, created_at),
                CONSTRAINT fk_queue_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                variation_id INT UNSIGNED NULL,
                sku VARCHAR(190) NULL,
                product_name VARCHAR(255) NOT NULL,
                parent_name VARCHAR(255) NULL,
                sync_token VARCHAR(64) NULL,
                price VARCHAR(100) NULL,
                stock VARCHAR(100) NULL,
                image_url TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_inventory_unique (store_id, product_id, variation_id),
                KEY idx_inventory_store (store_id),
                CONSTRAINT fk_inventory_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::ensureColumnMysql($pdo, 'inventory_items', 'parent_name', 'VARCHAR(255) NULL');
        self::ensureColumnMysql($pdo, 'inventory_items', 'sync_token', 'VARCHAR(64) NULL');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS intent_orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NOT NULL,
                live_session_id INT UNSIGNED NULL,
                widget_session_id VARCHAR(120) NOT NULL,
                customer_name VARCHAR(190) NOT NULL,
                phone VARCHAR(80) NOT NULL,
                email VARCHAR(190) NULL,
                city VARCHAR(120) NULL,
                notes TEXT NULL,
                product_id INT UNSIGNED NOT NULL,
                variation_id INT UNSIGNED NULL,
                qty INT UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(40) NOT NULL,
                woo_order_id INT UNSIGNED NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_intent_store (store_id),
                KEY idx_intent_live (live_session_id),
                CONSTRAINT fk_intent_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
                CONSTRAINT fk_intent_live FOREIGN KEY (live_session_id) REFERENCES live_sessions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sync_jobs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_id INT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL,
                sync_token VARCHAR(64) NOT NULL,
                current_page INT UNSIGNED NOT NULL DEFAULT 1,
                inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
                processed_products INT UNSIGNED NOT NULL DEFAULT 0,
                message VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_sync_store (store_id),
                CONSTRAINT fk_sync_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function ensureColumnSqlite(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt ? $stmt->fetchAll() : [];

        foreach ($columns as $item) {
            if (($item['name'] ?? null) === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function ensureColumnMysql(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        if ($stmt->fetch()) {
            return;
        }

        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}
