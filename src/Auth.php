<?php
declare(strict_types=1);

namespace Salvest;

final class Auth
{
    /** @param array<string,mixed> $appConfig */
    public function __construct(private Database $db, array $appConfig)
    {
        session_name((string)($appConfig['session_name'] ?? 'salvest_session'));
        session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>(bool)($appConfig['cookie_secure'] ?? true),'path'=>'/']);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function userId(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
    public function csrf(): string { return (string)$_SESSION['csrf']; }
    public function verifyCsrf(string $token): void { if (!hash_equals($this->csrf(),$token)) throw new \RuntimeException('La sesión ha caducado. Recarga la página.'); }

    public function login(string $username, string $password): bool
    {
        $username = mb_strtolower(trim($username));
        $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),0,100);
        $recent = $this->db->one("SELECT COUNT(*) attempts FROM login_attempts WHERE username=? AND ip_address=? AND succeeded=0 AND attempted_at >= DATE_SUB(NOW(),INTERVAL 15 MINUTE)",[$username,$ip]);
        if ((int)($recent['attempts'] ?? 0) >= 5) {
            throw new \RuntimeException('Demasiados intentos. Espera 15 minutos antes de volver a probar.');
        }
        $user = $this->db->one('SELECT * FROM users WHERE username=? AND active=1',[$username]);
        $valid = $user && password_verify($password,(string)$user['password_hash']);
        $this->db->execute('INSERT INTO login_attempts(username,ip_address,succeeded) VALUES (?,?,?)',[$username,$ip,$valid?1:0]);
        if (!$valid) return false;
        session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
        $this->db->execute('UPDATE users SET last_login_at=NOW() WHERE id=?',[$user['id']]); return true;
    }

    public function logout(): void { $_SESSION=[]; if (session_status()===PHP_SESSION_ACTIVE) session_destroy(); }
}
