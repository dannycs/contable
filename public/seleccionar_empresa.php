<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

exigirSesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud inválida. Recarga el panel.');
}

$empresaId = filter_var(
    $_POST['empresa_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$empresaId || $empresaId < 1) {
    $_SESSION['empresa_error'] = 'Selecciona una empresa válida.';
    header('Location: dashboard.php');
    exit;
}

try {
    $pdo = obtenerConexion();

    $consulta = $pdo->prepare(
        'SELECT e.id, e.razon_social, e.ruc
         FROM empresas e
         INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
         WHERE ue.usuario_id = :usuario_id
           AND e.id = :empresa_id
           AND e.estado = 1
         LIMIT 1'
    );

    $consulta->execute([
        'usuario_id' => (int) $_SESSION['usuario_id'],
        'empresa_id' => $empresaId,
    ]);

    $empresa = $consulta->fetch();

    if (!$empresa) {
        http_response_code(403);
        exit('No tienes autorización para acceder a esa empresa.');
    }

    session_regenerate_id(true);

    $_SESSION['empresa_activa_id'] = (int) $empresa['id'];
    $_SESSION['empresa_activa_nombre'] = $empresa['razon_social'];
    $_SESSION['empresa_activa_ruc'] = $empresa['ruc'];

    header('Location: dashboard.php');
    exit;
} catch (PDOException $e) {
    error_log('Error seleccionando empresa: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo seleccionar la empresa. Inténtalo nuevamente.');
}
