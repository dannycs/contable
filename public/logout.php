<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud inválida.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $parametros['path'],
        'domain' => $parametros['domain'],
        'secure' => $parametros['secure'],
        'httponly' => $parametros['httponly'],
        'samesite' => 'Lax',
    ]);
}

session_destroy();

header('Location: login.php');
exit;
