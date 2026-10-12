<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

$pdo = obtenerConexion();
$mensaje = '';
$creado = false;

$cantidad = (int) $pdo->query(
    'SELECT COUNT(*) FROM usuarios'
)->fetchColumn();

if ($cantidad > 0) {
    http_response_code(403);
    exit(
        'La instalación inicial ya fue realizada. ' .
        'Elimina el archivo crear_admin.php del proyecto.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Token de seguridad inválido. Recarga la página.');
    }

    $nombres = trim($_POST['nombres'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $clave = $_POST['clave'] ?? '';

    if (
        $nombres === '' ||
        $apellidos === '' ||
        !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
        strlen($clave) < 12
    ) {
        $mensaje = 'Completa los datos. La contraseña debe tener al menos 12 caracteres.';
    } else {
        $rol = $pdo->prepare(
            "SELECT id FROM roles WHERE nombre = 'SUPERADMIN' AND estado = 1"
        );
        $rol->execute();
        $rolId = $rol->fetchColumn();

        if (!$rolId) {
            $mensaje = 'No existe el rol SUPERADMIN. Revisa la tabla roles.';
        } else {
            $insertar = $pdo->prepare(
                'INSERT INTO usuarios
                    (rol_id, nombres, apellidos, correo, clave_hash)
                 VALUES
                    (:rol_id, :nombres, :apellidos, :correo, :clave_hash)'
            );

            try {
                $insertar->execute([
                    'rol_id' => $rolId,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'correo' => $correo,
                    'clave_hash' => password_hash(
                        $clave,
                        PASSWORD_DEFAULT
                    ),
                ]);

                $creado = true;
                $mensaje = 'Administrador creado correctamente.';
            } catch (PDOException $e) {
                error_log('Error creando administrador: ' . $e->getMessage());
                $mensaje = 'No se pudo crear la cuenta. Revisa los datos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear administrador | EMDESI Contable</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 30px 16px;
            color: #1e293b;
        }
        main {
            max-width: 480px;
            margin: auto;
            background: white;
            padding: 26px;
            border-radius: 12px;
        }
        label { display: block; margin-top: 14px; }
        input {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            margin-top: 6px;
        }
        button {
            margin-top: 20px;
            padding: 12px;
            width: 100%;
            background: #166534;
            color: white;
            border: 0;
            cursor: pointer;
        }
    </style>
</head>
<body>
<main>
    <h1>EMDESI Contable</h1>
    <h2>Administrador inicial</h2>

    <?php if ($mensaje !== ''): ?>
        <p><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($creado): ?>
        <p>Por seguridad, elimina ahora el archivo
            <strong>crear_admin.php</strong> y abre el inicio de sesión.</p>
        <p><a href="login.php">Ir al inicio de sesión</a></p>
    <?php elseif ($cantidad === 0): ?>
        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8') ?>">

            <label for="nombres">Nombres</label>
            <input id="nombres" name="nombres" required maxlength="120">

            <label for="apellidos">Apellidos</label>
            <input id="apellidos" name="apellidos" required maxlength="120">

            <label for="correo">Correo electrónico</label>
            <input id="correo" type="email" name="correo"
                   required maxlength="190">

            <label for="clave">Contraseña (mínimo 12 caracteres)</label>
            <input id="clave" type="password" name="clave"
                   required minlength="12" autocomplete="new-password">

            <button type="submit">Crear administrador</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
