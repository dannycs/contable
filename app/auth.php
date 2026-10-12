<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/contable/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/csrf.php';

function exigirSesion(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /contable/public/login.php');
        exit;
    }
}
