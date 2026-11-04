<?php
declare(strict_types=1);

/** Sessões PHP guardadas no PostgreSQL para sobreviver a reinícios do container Render. */
final class NexoDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT session_data FROM nexo_sessions WHERE session_id = ? AND last_activity >= ? LIMIT 1');
        $stmt->execute([$id, time() - 60 * 60 * 24 * 7]);
        $data = $stmt->fetchColumn();
        if ($data === false) {
            return '';
        }
        $decoded = base64_decode((string)$data, true);
        return $decoded === false ? '' : $decoded;
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nexo_sessions (session_id, session_data, last_activity) VALUES (?, ?, ?) '
            . 'ON CONFLICT (session_id) DO UPDATE SET session_data = EXCLUDED.session_data, last_activity = EXCLUDED.last_activity'
        );
        return $stmt->execute([$id, base64_encode($data), time()]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM nexo_sessions WHERE session_id = ?');
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM nexo_sessions WHERE last_activity < ?');
        $stmt->execute([time() - $max_lifetime]);
        return $stmt->rowCount();
    }

    public function validateId(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM nexo_sessions WHERE session_id = ? AND last_activity >= ? LIMIT 1');
        $stmt->execute([$id, time() - 60 * 60 * 24 * 7]);
        return (bool)$stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare('UPDATE nexo_sessions SET last_activity = ? WHERE session_id = ?');
        $stmt->execute([time(), $id]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        return $this->write($id, $data);
    }
}
