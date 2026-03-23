<?php

declare(strict_types=1);

/**
 * Sends a JSON response with the provided HTTP status code.
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Parses the request body as JSON and returns an array payload.
 */
function parseJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Redirects the current request to another path and stops execution.
 */
function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Escapes a string for safe HTML output.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Stores a flash message in the session.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Returns the current flash message and removes it from the session.
 */
function pullFlash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Normalizes numeric prices to the UI currency format.
 */
function formatPrice(?string $value): string
{
    $price = trim((string) $value);
    if ($price === '') {
        return '';
    }

    if (preg_match('/^\$/', $price) === 1) {
        return $price;
    }

    if (preg_match('/^-?\d[\d.,]*$/', $price) === 1) {
        return '$' . $price;
    }

    return $price;
}

/**
 * Extracts a YouTube video identifier from a supported URL.
 */
function extractYoutubeVideoId(?string $url): ?string
{
    if (!$url) {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['v'])) {
            return (string) $query['v'];
        }
    }

    if (!empty($parts['host']) && str_contains((string) $parts['host'], 'youtu.be')) {
        return trim((string) ($parts['path'] ?? ''), '/');
    }

    if (!empty($parts['path'])) {
        $path = trim((string) $parts['path'], '/');
        if (str_starts_with($path, 'live/')) {
            return substr($path, 5);
        }

        if ($path !== '' && !str_contains($path, '/')) {
            return $path;
        }
    }

    return null;
}

/**
 * Returns the public asset base URL for the current entrypoint.
 */
function assetBaseUrl(): string
{
    if (defined('LIVEPRO_ASSET_BASE_URL')) {
        return rtrim((string) LIVEPRO_ASSET_BASE_URL, '/');
    }

    return '/assets';
}

/**
 * Builds a versioned public asset URL for the backoffice frontend.
 */
function assetUrl(string $path): string
{
    $normalizedPath = ltrim($path, '/');
    $version = assetVersion($normalizedPath);

    return assetBaseUrl() . '/' . $normalizedPath . '?v=' . $version;
}

/**
 * Returns the filemtime version for a public backoffice asset.
 */
function assetVersion(string $path): int
{
    $normalizedPath = ltrim($path, '/');
    $assetFile = __DIR__ . '/../public/assets/' . $normalizedPath;
    return @filemtime($assetFile) ?: time();
}
