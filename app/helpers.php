<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function nexo_limit_name(string $value, int $limit = 24): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    preg_match_all('/./us', $value, $matches);
    return trim(implode('', array_slice($matches[0] ?? [], 0, $limit)));
}

function nexo_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function nexo_check_csrf(): void
{
    $sent = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
    }
}

function nexo_redirect(string $url = 'index.php'): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function nexo_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function nexo_take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function nexo_room_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function nexo_current(PDO $pdo): array
{
    $playerId = (int)($_SESSION['player_id'] ?? 0);
    if ($playerId <= 0) {
        return [null, null];
    }
    $stmt = $pdo->prepare('SELECT p.*, r.room_code, r.module_count, r.duration_seconds, r.status, r.host_player_id, r.started_at, r.errors, r.game_json FROM players p INNER JOIN rooms r ON r.id = p.room_id WHERE p.id = ? LIMIT 1');
    $stmt->execute([$playerId]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['player_id']);
        return [null, null];
    }
    $room = [
        'id' => (int)$row['room_id'],
        'code' => $row['room_code'],
        'module_count' => (int)$row['module_count'],
        'duration_seconds' => (int)$row['duration_seconds'],
        'status' => $row['status'],
        'host_player_id' => (int)$row['host_player_id'],
        'started_at' => $row['started_at'] === null ? null : (int)$row['started_at'],
        'errors' => (int)$row['errors'],
        'game_json' => $row['game_json'],
    ];
    $player = [
        'id' => (int)$row['id'],
        'name' => $row['player_name'],
        'role' => $row['role'],
        'is_host' => (bool)$row['is_host'],
        'ready' => (bool)$row['ready'],
    ];
    return [$player, $room];
}

function nexo_get_players(PDO $pdo, int $roomId): array
{
    $stmt = $pdo->prepare('SELECT id, player_name, role, is_host, ready FROM players WHERE room_id = ? ORDER BY joined_at ASC');
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}


/** Assinatura curta do estado compartilhado; muda quando lobby ou partida muda. */
function nexo_room_sync_stamp(PDO $pdo, int $roomId): string
{
    $stmt = $pdo->prepare('SELECT status, module_count, duration_seconds, started_at, errors, game_json FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$roomId]);
    $roomState = $stmt->fetch();
    if (!$roomState) {
        return '';
    }
    $players = nexo_get_players($pdo, $roomId);
    $payload = [
        'room' => $roomState,
        'players' => array_map(static fn(array $p): array => [
            'id' => (int)$p['id'],
            'name' => (string)$p['player_name'],
            'role' => (string)$p['role'],
            'host' => (int)$p['is_host'],
            'ready' => (int)$p['ready'],
        ], $players),
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
