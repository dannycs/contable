<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud inválida. Recarga el formulario de inicio de sesión.');
}

$correo = trim($_POST['correo'] ?? '');
$clave = $_POST['clave'] ?? '';

if (
    !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
    !is_string($clave) ||
    $clave === ''
) {
    $_SESSION['login_error'] = 'Credenciales incorrectas.';
    header('Location: login.php');
    exit;
}

try {
    $pdo = obtenerConexion();

    $consulta = $pdo->prepare(
        'SELECT u.id, u.nombres, u.apellidos, u.correo,
                u.clave_hash, u.estado, r.nombre AS rol
         FROM usuarios u
         INNER JOIN roles r ON r.id = u.rol_id
         WHERE u.correo = :correo
         LIMIT 1'
    );

    $consulta->execute(['correo' => $correo]);
    $usuario = $consulta->fetch();

    if (
        !$usuario ||
        (int) $usuario['estado'] !== 1 ||
        !password_verify($clave, $usuario['clave_hash'])
    ) {
        $_SESSION['login_error'] = 'Credenciales incorrectas.';
        header('Location: login.php');
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['usuario_nombre'] =
        $usuario['nombres'] . ' ' . $usuario['apellidos'];
    $_SESSION['usuario_correo'] = $usuario['correo'];
    $_SESSION['usuario_rol'] = $usuario['rol'];

    $actualizar = $pdo->prepare(
        'UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id'
    );
    $actualizar->execute(['id' => $usuario['id']]);

    header('Location: dashboard.php');
    exit;
} catch (PDOException $e) {
    error_log('Error de autenticación: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo procesar el acceso. Inténtalo más tarde.');
}
