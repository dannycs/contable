<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

$mensaje = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | EMDESI Contable</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 40px 16px;
        }
        main {
            max-width: 400px;
            margin: auto;
            background: white;
            padding: 28px;
            border-radius: 12px;
        }
        label { display: block; margin-top: 16px; }
        input {
            box-sizing: border-box;
            width: 100%;
            padding: 12px;
            margin-top: 6px;
        }
        button {
            width: 100%;
            padding: 12px;
            margin-top: 20px;
            border: 0;
            background: #166534;
            color: white;
            cursor: pointer;
        }
        .error { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>EMDESI Contable</h1>
    <p>Sistema Contable Integral</p>

    <?php if ($mensaje !== ''): ?>
        <p class="error">
            <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="post" action="validar_login.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8') ?>">

        <label for="correo">Correo electrónico</label>
        <input id="correo" type="email" name="correo"
               required autocomplete="username">

        <label for="clave">Contraseña</label>
        <input id="clave" type="password" name="clave"
               required autocomplete="current-password">

        <button type="submit">Ingresar al sistema</button>
    </form>
</main>
</body>
</html>
