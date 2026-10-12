<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

exigirSesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: plan_contable.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitud inválida. Recarga la página.');
}

$pdo = obtenerConexion();

$usuarioId = (int) $_SESSION['usuario_id'];
$empresaId = (int) ($_SESSION['empresa_activa_id'] ?? 0);
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$accion = (string) ($_POST['accion'] ?? '');

if (
    $empresaId < 1 ||
    !$id ||
    $id < 1 ||
    !in_array($accion, ['activar', 'desactivar'], true)
) {
    http_response_code(400);
    exit('Los datos enviados no son válidos.');
}

try {
    // Comprobar que la empresa sigue autorizada.
    $acceso = $pdo->prepare(
        'SELECT e.id
         FROM empresas e
         INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
         WHERE e.id = :empresa_id
           AND ue.usuario_id = :usuario_id
           AND e.estado = 1
         LIMIT 1'
    );
    $acceso->execute([
        'empresa_id' => $empresaId,
        'usuario_id' => $usuarioId
    ]);

    if (!$acceso->fetch()) {
        http_response_code(403);
        exit('No tienes acceso a esta empresa.');
    }

    $pdo->beginTransaction();

    $consulta = $pdo->prepare(
        'SELECT id, estado, cuenta_padre_id
         FROM plan_cuentas
         WHERE id = :id AND empresa_id = :empresa_id
         FOR UPDATE'
    );
    $consulta->execute([
        'id' => $id,
        'empresa_id' => $empresaId
    ]);

    $cuenta = $consulta->fetch();

    if (!$cuenta) {
        throw new DomainException(
            'La cuenta no existe en la empresa activa.'
        );
    }

    if ($accion === 'desactivar') {
        // No desactivar una cuenta que tenga cuentas hijas activas.
        $hijas = $pdo->prepare(
            'SELECT COUNT(*)
             FROM plan_cuentas
             WHERE empresa_id = :empresa_id
               AND cuenta_padre_id = :id
               AND estado = 1'
        );
        $hijas->execute([
            'empresa_id' => $empresaId,
            'id' => $id
        ]);

        if ((int) $hijas->fetchColumn() > 0) {
            throw new DomainException(
                'Primero desactiva las cuentas hijas activas.'
            );
        }
    } else {
        // No reactivar una cuenta cuya cuenta superior esté inactiva.
        if ($cuenta['cuenta_padre_id'] !== null) {
            $padre = $pdo->prepare(
                'SELECT estado
                 FROM plan_cuentas
                 WHERE id = :padre_id
                   AND empresa_id = :empresa_id
                 LIMIT 1'
            );
            $padre->execute([
                'padre_id' => $cuenta['cuenta_padre_id'],
                'empresa_id' => $empresaId
            ]);

            $estadoPadre = $padre->fetchColumn();

            if ($estadoPadre === false || (int) $estadoPadre !== 1) {
                throw new DomainException(
                    'Primero reactiva la cuenta superior.'
                );
            }
        }
    }

    $nuevoEstado = $accion === 'activar' ? 1 : 0;

    $actualizar = $pdo->prepare(
        'UPDATE plan_cuentas
         SET estado = :estado
         WHERE id = :id AND empresa_id = :empresa_id'
    );

    $actualizar->execute([
        'estado' => $nuevoEstado,
        'id' => $id,
        'empresa_id' => $empresaId
    ]);

    $pdo->commit();

    $_SESSION['plan_cuentas_mensaje'] =
        $accion === 'activar'
            ? 'Cuenta reactivada correctamente.'
            : 'Cuenta desactivada correctamente.';

    header('Location: plan_contable.php');
    exit;

} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['plan_cuentas_error'] = $e->getMessage();
    header('Location: plan_contable.php');
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Error cambiando estado de cuenta: ' . $e->getMessage());

    http_response_code(500);
    exit('No se pudo cambiar el estado de la cuenta. Revisa el registro de errores.');
}
