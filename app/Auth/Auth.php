<?php

require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/../View/Icons.php';

class Auth
{
    private PDO $pdo;

    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_ATTEMPT_WINDOW_MINUTES = 15;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function attempt(string $email, string $password): ?array
    {
        if ($this->isRateLimited($email)) {
            return null;
        }

        $statement = $this->pdo->prepare("
            SELECT
                id,
                organization_id,
                branch_id,
                name,
                email,
                password_hash,
                status
            FROM users
            WHERE email = :email
              AND status = 'active'
            LIMIT 1
        ");

        $statement->execute([
            'email' => $email
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($email);
            return null;
        }

        unset($user['password_hash']);

        return $user;
    }

    private function isRateLimited(string $email): bool
    {
        $statement = $this->pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE identifier = :identifier
              AND attempted_at > CURRENT_TIMESTAMP - (:window_minutes || ' minutes')::interval
        ");
        $statement->execute([
            'identifier' => $email,
            'window_minutes' => self::LOGIN_ATTEMPT_WINDOW_MINUTES
        ]);

        return (int) $statement->fetchColumn() >= self::MAX_LOGIN_ATTEMPTS;
    }

    private function recordFailedAttempt(string $email): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (identifier) VALUES (:identifier)'
        );
        $statement->execute(['identifier' => $email]);
    }

    public function login(array $user): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenHash = hash('sha256', $token);

        $expiresAt = date(
            'Y-m-d H:i:s',
            time() + (60 * 60 * 8)
        );

        $statement = $this->pdo->prepare("
            INSERT INTO sessions (
                user_id,
                token_hash,
                expires_at
            )
            VALUES (
                :user_id,
                :token_hash,
                :expires_at
            )
        ");

        $statement->execute([
            'user_id' => $user['id'],
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt
        ]);

        setcookie(
            'garageos_session',
            $token,
            [
                'expires' => time() + (60 * 60 * 8),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $this->isHttps()
            ]
        );
    }

    public function user(): ?array
    {
        $token = $_COOKIE['garageos_session'] ?? null;

        if (!$token) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        $statement = $this->pdo->prepare("
            SELECT
                u.id,
                u.organization_id,
                u.branch_id,
                u.name,
                u.email,
                u.status,
                o.name AS organization_name,
                o.logo_url AS organization_logo_url,
                o.public_page_url AS organization_public_page_url
            FROM sessions s
            INNER JOIN users u
                ON u.id = s.user_id
            INNER JOIN organizations o
                ON o.id = u.organization_id
            WHERE s.token_hash = :token_hash
              AND s.expires_at > CURRENT_TIMESTAMP
              AND u.status = 'active'
            LIMIT 1
        ");

        $statement->execute([
            'token_hash' => $tokenHash
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        $update = $this->pdo->prepare("
            UPDATE sessions
            SET last_activity_at = CURRENT_TIMESTAMP
            WHERE token_hash = :token_hash
        ");

        $update->execute([
            'token_hash' => $tokenHash
        ]);

        $user['permissions'] = $this->permissionsFor((int) $user['id']);

        return $user;
    }

    private function permissionsFor(int $userId): array
    {
        $statement = $this->pdo->prepare("
            SELECT DISTINCT p.code
            FROM user_roles ur
            INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = :user_id
        ");
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function logout(): void
    {
        $token = $_COOKIE['garageos_session'] ?? null;

        if ($token) {
            $tokenHash = hash('sha256', $token);

            $statement = $this->pdo->prepare(
                'DELETE FROM sessions WHERE token_hash = :token_hash'
            );

            $statement->execute([
                'token_hash' => $tokenHash
            ]);
        }

        setcookie('garageos_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps()
        ]);
    }
}
