<?php

class Auth
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function attempt(string $email, string $password): ?array
    {
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

        if (!$user) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        unset($user['password_hash']);

        return $user;
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
                'secure' => false
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
                u.status
            FROM sessions s
            INNER JOIN users u
                ON u.id = s.user_id
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

        return $user;
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
            'secure' => false
        ]);
    }
}
