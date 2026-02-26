<?php

declare(strict_types=1);

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function parseJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pullFlash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

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
