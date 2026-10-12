<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

exigirSesion();

$pdo = obtenerConexion();
$usuarioId = (int) $_SESSION['usuario_id'];
$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'SUPERADMIN';

$mensaje = '';
$tipoMensaje = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esAdmin) {
        http_response_code(403);
        exit('No tienes permisos para registrar empresas.');
    }

    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Token de seguridad inválido. Recarga la página.');
    }

    $ruc = trim($_POST['ruc'] ?? '');
    $razonSocial = trim($_POST['razon_social'] ?? '');
    $nombreComercial = trim($_POST['nombre_comercial'] ?? '');
    $regimen = trim($_POST['regimen_tributario'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $departamento = trim($_POST['departamento'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
    $distrito = trim($_POST['distrito'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $correo = trim($_POST['correo'] ?? '');

    $regimenesPermitidos = [
        '',
        'NRUS',
        'RER',
        'RMT',
        'GENERAL',
        'OTRO'
    ];

    if (!preg_match('/^[0-9]{11}$/', $ruc)) {
        $mensaje = 'El RUC debe contener exactamente 11 dígitos.';
    } elseif ($razonSocial === '' || mb_strlen($razonSocial) > 200) {
        $mensaje = 'Ingresa una razón social válida de hasta 200 caracteres.';
    } elseif (
        !in_array($regimen, $regimenesPermitidos, true)
    ) {
        $mensaje = 'Selecciona un régimen tributario válido.';
    } elseif (
        $correo !== '' &&
        !filter_var($correo, FILTER_VALIDATE_EMAIL)
    ) {
        $mensaje = 'El correo de la empresa no tiene un formato válido.';
    } else {
        try {
            $pdo->beginTransaction();

            $insertar = $pdo->prepare(
                'INSERT INTO empresas
                    (ruc, razon_social, nombre_comercial,
                     regimen_tributario, direccion, departamento,
                     provincia, distrito, telefono, correo)
                 VALUES
                    (:ruc, :razon_social, :nombre_comercial,
                     :regimen, :direccion, :departamento,
                     :provincia, :distrito, :telefono, :correo)'
            );

            $insertar->execute([
                'ruc' => $ruc,
                'razon_social' => $razonSocial,
                'nombre_comercial' => $nombreComercial !== ''
                    ? $nombreComercial : null,
                'regimen' => $regimen !== '' ? $regimen : null,
                'direccion' => $direccion !== '' ? $direccion : null,
                'departamento' => $departamento !== ''
                    ? $departamento : null,
                'provincia' => $provincia !== '' ? $provincia : null,
                'distrito' => $distrito !== '' ? $distrito : null,
                'telefono' => $telefono !== '' ? $telefono : null,
                'correo' => $correo !== '' ? $correo : null,
            ]);

            $empresaId = (int) $pdo->lastInsertId();

            $contar = $pdo->prepare(
                'SELECT COUNT(*) FROM usuario_empresa
                 WHERE usuario_id = :usuario_id'
            );
            $contar->execute(['usuario_id' => $usuarioId]);
            $esPrincipal = (int) $contar->fetchColumn() === 0 ? 1 : 0;

            $asociar = $pdo->prepare(
                'INSERT INTO usuario_empresa
                    (usuario_id, empresa_id, es_principal)
                 VALUES (:usuario_id, :empresa_id, :es_principal)'
            );

            $asociar->execute([
                'usuario_id' => $usuarioId,
                'empresa_id' => $empresaId,
                'es_principal' => $esPrincipal,
            ]);

            $pdo->commit();

            $mensaje = 'Empresa registrada y asociada a tu usuario correctamente.';
            $tipoMensaje = 'correcto';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Error registrando empresa: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $mensaje = 'No se pudo registrar. Verifica si el RUC ya existe.';
            } else {
                $mensaje = 'Ocurrió un error al guardar. Revisa el registro de errores.';
            }
        }
    }
}

$listar = $pdo->prepare(
    'SELECT e.id, e.ruc, e.razon_social,
            e.nombre_comercial, e.regimen_tributario,
            e.estado, ue.es_principal
     FROM empresas e
     INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
     WHERE ue.usuario_id = :usuario_id
     ORDER BY e.razon_social'
);
$listar->execute(['usuario_id' => $usuarioId]);
$empresas = $listar->fetchAll();

function h(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Empresas | EMDESI Contable</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }
        header {
            background: #14532d;
            color: white;
            padding: 18px 24px;
        }
        main {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px;
        }
        .tarjeta {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px #00000008;
        }
        .formulario {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .campo { display: flex; flex-direction: column; gap: 6px; }
        .completo { grid-column: 1 / -1; }
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }
        button, .enlace {
            display: inline-block;
            padding: 11px 16px;
            border: 0;
            border-radius: 6px;
            background: #166534;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }
        .mensaje {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .correcto { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        .tabla-contenedor { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        th { background: #f8fafc; }
        .acciones { display: flex; gap: 10px; margin-bottom: 20px; }
        @media (max-width: 600px) {
            .formulario { grid-template-columns: 1fr; }
            .completo { grid-column: auto; }
        }
    </style>
</head>
<body>
<header>
    <h1>EMDESI Contable</h1>
    <p>Administración de empresas</p>
</header>

<main>
    <div class="acciones">
        <a class="enlace" href="dashboard.php">Volver al panel</a>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="mensaje <?= $tipoMensaje === 'correcto' ? 'correcto' : 'error' ?>">
            <?= h($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($esAdmin): ?>
        <section class="tarjeta">
            <h2>Registrar empresa</h2>
            <p>Ingresa los datos básicos de la empresa.</p>

            <form method="post" class="formulario">
                <input type="hidden" name="csrf_token"
                       value="<?= h(generarTokenCSRF()) ?>">

                <div class="campo">
                    <label for="ruc">RUC *</label>
                    <input id="ruc" name="ruc" inputmode="numeric"
                           pattern="[0-9]{11}" maxlength="11"
                           minlength="11" required>
                </div>

                <div class="campo">
                    <label for="razon_social">Razón social *</label>
                    <input id="razon_social" name="razon_social"
                           maxlength="200" required>
                </div>

                <div class="campo">
                    <label for="nombre_comercial">Nombre comercial</label>
                    <input id="nombre_comercial" name="nombre_comercial"
                           maxlength="200">
                </div>

                <div class="campo">
                    <label for="regimen_tributario">Régimen tributario</label>
                    <select id="regimen_tributario" name="regimen_tributario">
                        <option value="">Por definir</option>
                        <option value="NRUS">Nuevo RUS</option>
                        <option value="RER">Régimen Especial (RER)</option>
                        <option value="RMT">Régimen MYPE Tributario (RMT)</option>
                        <option value="GENERAL">Régimen General</option>
                        <option value="OTRO">Otro / por revisar</option>
                    </select>
                </div>

                <div class="campo completo">
                    <label for="direccion">Dirección</label>
                    <input id="direccion" name="direccion" maxlength="255">
                </div>

                <div class="campo">
                    <label for="departamento">Departamento</label>
                    <input id="departamento" name="departamento" maxlength="100">
                </div>

                <div class="campo">
                    <label for="provincia">Provincia</label>
                    <input id="provincia" name="provincia" maxlength="100">
                </div>

                <div class="campo">
                    <label for="distrito">Distrito</label>
                    <input id="distrito" name="distrito" maxlength="100">
                </div>

                <div class="campo">
                    <label for="telefono">Teléfono</label>
                    <input id="telefono" name="telefono" maxlength="30">
                </div>

                <div class="campo completo">
                    <label for="correo">Correo electrónico</label>
                    <input id="correo" type="email" name="correo" maxlength="190">
                </div>

                <div class="completo">
                    <button type="submit">Guardar empresa</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="tarjeta">
        <h2>Mis empresas</h2>

        <?php if (!$empresas): ?>
            <p>Aún no tienes empresas asociadas a tu usuario.</p>
        <?php else: ?>
            <div class="tabla-contenedor">
                <table>
                    <thead>
                    <tr>
                        <th>RUC</th>
                        <th>Razón social</th>
                        <th>Régimen</th>
                        <th>Estado</th>
                        <th>Tipo</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($empresas as $empresa): ?>
                        <tr>
                            <td><?= h($empresa['ruc']) ?></td>
                            <td><?= h($empresa['razon_social']) ?></td>
                            <td><?= h($empresa['regimen_tributario']) ?></td>
                            <td><?= (int) $empresa['estado'] === 1 ? 'Activa' : 'Inactiva' ?></td>
                            <td><?= (int) $empresa['es_principal'] === 1 ? 'Principal' : 'Asociada' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
