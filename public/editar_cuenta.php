<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

exigirSesion();

$pdo = obtenerConexion();

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$usuarioId = (int) $_SESSION['usuario_id'];
$empresaId = (int) ($_SESSION['empresa_activa_id'] ?? 0);

if ($empresaId < 1) {
    header('Location: dashboard.php');
    exit;
}

// Verificar que el usuario tenga acceso a la empresa activa.
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

$id = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Identificador de cuenta inválido.');
}

function obtenerCuenta(PDO $pdo, int $id, int $empresaId): array|false
{
    $consulta = $pdo->prepare(
        'SELECT id, codigo, nombre, tipo_cuenta, nivel,
                cuenta_padre_id, acepta_movimientos,
                requiere_tercero, estado
         FROM plan_cuentas
         WHERE id = :id AND empresa_id = :empresa_id
         LIMIT 1'
    );

    $consulta->execute([
        'id' => $id,
        'empresa_id' => $empresaId
    ]);

    return $consulta->fetch();
}

$cuenta = obtenerCuenta($pdo, $id, $empresaId);

if (!$cuenta) {
    http_response_code(404);
    exit('La cuenta no existe en la empresa activa.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud inválida. Recarga la página.');
    }

    $codigo = trim((string) ($_POST['codigo'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));

    if ($codigo === '' || $nombre === '') {
        $error = 'El código y el nombre son obligatorios.';
    } elseif (strlen($codigo) > 20 || strlen($nombre) > 200) {
        $error = 'El código o el nombre supera la longitud permitida.';
    } elseif (!preg_match('/^[A-Za-z0-9.\-]+$/', $codigo)) {
        $error = 'El código solo admite letras, números, puntos y guiones.';
    } else {
        try {
            $pdo->beginTransaction();

            $duplicado = $pdo->prepare(
                'SELECT id
                 FROM plan_cuentas
                 WHERE empresa_id = :empresa_id
                   AND codigo = :codigo
                   AND id <> :id
                 LIMIT 1'
            );

            $duplicado->execute([
                'empresa_id' => $empresaId,
                'codigo' => $codigo,
                'id' => $id
            ]);

            if ($duplicado->fetch()) {
                throw new DomainException(
                    'Ese código ya está registrado en esta empresa.'
                );
            }

            $actualizar = $pdo->prepare(
                'UPDATE plan_cuentas
                 SET codigo = :codigo, nombre = :nombre
                 WHERE id = :id AND empresa_id = :empresa_id'
            );

            $actualizar->execute([
                'codigo' => $codigo,
                'nombre' => $nombre,
                'id' => $id,
                'empresa_id' => $empresaId
            ]);

            $pdo->commit();

            $_SESSION['plan_cuentas_mensaje'] =
                'Cuenta contable actualizada correctamente.';

            header('Location: plan_contable.php');
            exit;

        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Error editando cuenta contable: ' . $e->getMessage());
            $error = 'No se pudo actualizar la cuenta. Revisa el registro de errores del servidor.';
        }
    }

    // Conservar lo escrito si ocurre un error.
    $cuenta['codigo'] = $codigo;
    $cuenta['nombre'] = $nombre;
}

$token = generarTokenCSRF();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar cuenta | EMDESI Contable</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm border-0 mx-auto" style="max-width: 650px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Editar cuenta contable</h1>

            <p class="text-muted">
                Tipo: <?= h($cuenta['tipo_cuenta']) ?> |
                Nivel: <?= (int) $cuenta['nivel'] ?>
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="editar_cuenta.php">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="csrf_token" value="<?= h($token) ?>">

                <div class="mb-3">
                    <label for="codigo" class="form-label">Código</label>
                    <input
                        type="text"
                        id="codigo"
                        name="codigo"
                        class="form-control"
                        maxlength="20"
                        required
                        value="<?= h($cuenta['codigo']) ?>"
                    >
                </div>

                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre</label>
                    <input
                        type="text"
                        id="nombre"
                        name="nombre"
                        class="form-control"
                        maxlength="200"
                        required
                        value="<?= h($cuenta['nombre']) ?>"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">Configuración protegida</label>
                    <input
                        class="form-control mb-2"
                        value="Tipo: <?= h($cuenta['tipo_cuenta']) ?>"
                        disabled
                    >
                    <input
                        class="form-control mb-2"
                        value="Nivel: <?= (int) $cuenta['nivel'] ?>"
                        disabled
                    >
                    <input
                        class="form-control"
                        value="Cuenta superior: <?= $cuenta['cuenta_padre_id'] === null ? 'Ninguna' : 'ID ' . (int) $cuenta['cuenta_padre_id'] ?>"
                        disabled
                    >
                </div>

                <button type="submit" class="btn btn-primary">
                    Guardar cambios
                </button>
                <a href="plan_contable.php" class="btn btn-outline-secondary">
                    Cancelar
                </a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
