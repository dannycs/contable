<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/auth.php';

exigirSesion();

$pdo = obtenerConexion();

function h($valor): string
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

$usuarioId = (int) $_SESSION['usuario_id'];
$empresaId = (int) ($_SESSION['empresa_activa_id'] ?? 0);
$empresaNombre = $_SESSION['empresa_activa_nombre'] ?? '';

$mensaje = '';
$error = '';

$tiposCuenta = [
    'ACTIVO',
    'PASIVO',
    'PATRIMONIO',
    'INGRESO',
    'GASTO',
    'COSTO',
    'ORDEN'
];

/*
|--------------------------------------------------------------------------
| Verificar empresa activa y autorización del usuario
|--------------------------------------------------------------------------
*/

if ($empresaId < 1) {
    header('Location: dashboard.php');
    exit;
}

$verificar = $pdo->prepare(
    'SELECT e.id, e.razon_social, e.ruc
     FROM empresas e
     INNER JOIN usuario_empresa ue
        ON ue.empresa_id = e.id
     WHERE e.id = :empresa_id
       AND ue.usuario_id = :usuario_id
       AND e.estado = 1
     LIMIT 1'
);

$verificar->execute([
    'empresa_id' => $empresaId,
    'usuario_id' => $usuarioId
]);

$empresa = $verificar->fetch();

if (!$empresa) {
    unset(
        $_SESSION['empresa_activa_id'],
        $_SESSION['empresa_activa_nombre'],
        $_SESSION['empresa_activa_ruc']
    );

    header('Location: dashboard.php');
    exit;
}

$empresaNombre = $empresa['razon_social'];

/*
|--------------------------------------------------------------------------
| Registrar cuenta contable
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud inválida. Recarga la página e inténtalo nuevamente.');
    }

    $codigo = trim((string) ($_POST['codigo'] ?? ''));
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $tipo = strtoupper(trim((string) ($_POST['tipo_cuenta'] ?? '')));
    $nivel = filter_var(
        $_POST['nivel'] ?? 1,
        FILTER_VALIDATE_INT
    );
    $padreId = filter_var(
        $_POST['cuenta_padre_id'] ?? '',
        FILTER_VALIDATE_INT
    );
    $aceptaMovimientos = isset($_POST['acepta_movimientos']) ? 1 : 0;
    $requiereTercero = isset($_POST['requiere_tercero']) ? 1 : 0;

    if ($codigo === '' || $nombre === '') {
        $error = 'Ingresa el código y el nombre de la cuenta.';
    } elseif (strlen($codigo) > 20 || strlen($nombre) > 200) {
        $error = 'El código o el nombre supera la longitud permitida.';
    } elseif (!preg_match('/^[A-Za-z0-9.\-]+$/', $codigo)) {
        $error = 'El código solo puede contener letras, números, puntos y guiones.';
    } elseif (!in_array($tipo, $tiposCuenta, true)) {
        $error = 'Selecciona un tipo de cuenta válido.';
    } elseif ($nivel === false || $nivel < 1 || $nivel > 9) {
        $error = 'El nivel debe estar entre 1 y 9.';
    } elseif ($padreId !== false && $padreId !== null && $padreId < 1) {
        $error = 'La cuenta superior seleccionada no es válida.';
    } elseif ($aceptaMovimientos === 0 && $requiereTercero === 1) {
        $error = 'Una cuenta que no acepta movimientos no puede exigir tercero.';
    } else {
        try {
            $pdo->beginTransaction();

            /*
             * Comprobar que el código no esté repetido
             * dentro de esta empresa.
             */
            $duplicado = $pdo->prepare(
                'SELECT id
                 FROM plan_cuentas
                 WHERE empresa_id = :empresa_id
                   AND codigo = :codigo
                 LIMIT 1'
            );

            $duplicado->execute([
                'empresa_id' => $empresaId,
                'codigo' => $codigo
            ]);

            if ($duplicado->fetch()) {
                throw new DomainException(
                    'Ya existe una cuenta con ese código en esta empresa.'
                );
            }

            /*
             * Si se selecciona una cuenta superior,
             * debe pertenecer a la misma empresa y estar activa.
             */
            $cuentaPadreId = null;

            if ($padreId !== false && $padreId !== null && $padreId > 0) {
                $consultaPadre = $pdo->prepare(
                    'SELECT id, nivel, acepta_movimientos, tipo_cuenta
                     FROM plan_cuentas
                     WHERE id = :id
                       AND empresa_id = :empresa_id
                       AND estado = 1
                     LIMIT 1'
                );

                $consultaPadre->execute([
                    'id' => $padreId,
                    'empresa_id' => $empresaId
                ]);

                $padre = $consultaPadre->fetch();

                if (!$padre) {
                    throw new DomainException(
                        'La cuenta superior no existe o no pertenece a esta empresa.'
                    );
                }

                if ((int) $padre['acepta_movimientos'] === 1) {
                    throw new DomainException(
                        'La cuenta superior debe ser una cuenta de agrupación que no acepte movimientos.'
                    );
                }

                if ((int) $nivel !== ((int) $padre['nivel'] + 1)) {
                    throw new DomainException(
                        'El nivel de la cuenta debe ser exactamente un nivel superior al de su cuenta padre.'
                    );
                }

                if ($tipo !== $padre['tipo_cuenta']) {
                    throw new DomainException(
                        'La cuenta y su cuenta superior deben tener el mismo tipo contable.'
                    );
                }

                $cuentaPadreId = (int) $padre['id'];
            } elseif ((int) $nivel !== 1) {
                throw new DomainException(
                    'Una cuenta sin cuenta superior debe tener nivel 1.'
                );
            }

            $insertar = $pdo->prepare(
                'INSERT INTO plan_cuentas
                    (
                        empresa_id,
                        codigo,
                        nombre,
                        cuenta_padre_id,
                        nivel,
                        tipo_cuenta,
                        acepta_movimientos,
                        requiere_tercero,
                        estado
                    )
                 VALUES
                    (
                        :empresa_id,
                        :codigo,
                        :nombre,
                        :cuenta_padre_id,
                        :nivel,
                        :tipo_cuenta,
                        :acepta_movimientos,
                        :requiere_tercero,
                        1
                    )'
            );

            $insertar->execute([
                'empresa_id' => $empresaId,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'cuenta_padre_id' => $cuentaPadreId,
                'nivel' => $nivel,
                'tipo_cuenta' => $tipo,
                'acepta_movimientos' => $aceptaMovimientos,
                'requiere_tercero' => $requiereTercero
            ]);

            $pdo->commit();

            $_SESSION['plan_cuentas_mensaje'] =
                'Cuenta contable registrada correctamente.';

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

            error_log('Error al registrar cuenta contable: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $error = 'No se pudo registrar la cuenta porque existe un dato duplicado o una relación inválida.';
            } else {
                $error = 'Ocurrió un error al registrar la cuenta. Revisa el registro de errores del servidor.';
            }
        }
    }
}

if (isset($_SESSION['plan_cuentas_mensaje'])) {
    $mensaje = (string) $_SESSION['plan_cuentas_mensaje'];
    unset($_SESSION['plan_cuentas_mensaje']);
}

/*
|--------------------------------------------------------------------------
| Listar cuentas de la empresa activa
|--------------------------------------------------------------------------
*/

$listado = $pdo->prepare(
    'SELECT
        pc.id,
        pc.codigo,
        pc.nombre,
        pc.nivel,
        pc.tipo_cuenta,
        pc.acepta_movimientos,
        pc.requiere_tercero,
        pc.estado,
        padre.codigo AS codigo_padre,
        padre.nombre AS nombre_padre
     FROM plan_cuentas pc
     LEFT JOIN plan_cuentas padre
        ON padre.id = pc.cuenta_padre_id
       AND padre.empresa_id = pc.empresa_id
     WHERE pc.empresa_id = :empresa_id
     ORDER BY pc.codigo ASC'
);

$listado->execute([
    'empresa_id' => $empresaId
]);

$cuentas = $listado->fetchAll();

/*
 * Solo mostrar cuentas de agrupación activas
 * como posibles cuentas superiores.
 */
$consultaPadres = $pdo->prepare(
    'SELECT id, codigo, nombre, nivel, tipo_cuenta
     FROM plan_cuentas
     WHERE empresa_id = :empresa_id
       AND estado = 1
       AND acepta_movimientos = 0
     ORDER BY codigo ASC'
);

$consultaPadres->execute([
    'empresa_id' => $empresaId
]);

$cuentasPadre = $consultaPadres->fetchAll();

$csrfToken = generarTokenCSRF();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan contable | EMDESI Contable</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        body {
            background: #f4f7fb;
            color: #263445;
        }

        .encabezado {
            background: #123b5d;
            color: #fff;
            border-radius: 14px;
            padding: 24px;
        }

        .tarjeta {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(25, 50, 75, .07);
        }

        .codigo {
            font-family: Consolas, monospace;
            font-weight: 700;
        }

        .tabla-contenedor {
            overflow-x: auto;
        }
    </style>
</head>
<body>

<div class="container py-4">

    <header class="encabezado mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
            <div>
                <h1 class="h3 mb-2">Plan contable</h1>
                <div><?= h($empresaNombre) ?></div>
                <small>RUC: <?= h($empresa['ruc']) ?></small>
            </div>

            <a href="dashboard.php" class="btn btn-light">
                Volver al panel
            </a>
        </div>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success">
            <?= h($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['plan_cuentas_error'])): ?>
    <div class="alert alert-danger">
        <?= h($_SESSION['plan_cuentas_error']) ?>
    </div>
    <?php unset($_SESSION['plan_cuentas_error']); ?>
<?php endif; ?>

    <section class="card tarjeta mb-4">
        <div class="card-body p-4">
            <h2 class="h5 mb-3">Registrar cuenta contable</h2>

            <form method="POST" action="plan_contable.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <div class="row g-3">

                    <div class="col-md-3">
                        <label for="codigo" class="form-label">Código</label>
                        <input
                            type="text"
                            class="form-control"
                            id="codigo"
                            name="codigo"
                            maxlength="20"
                            required
                            value="<?= h($_POST['codigo'] ?? '') ?>"
                            placeholder="10"
                        >
                    </div>

                    <div class="col-md-9">
                        <label for="nombre" class="form-label">Nombre de la cuenta</label>
                        <input
                            type="text"
                            class="form-control"
                            id="nombre"
                            name="nombre"
                            maxlength="200"
                            required
                            value="<?= h($_POST['nombre'] ?? '') ?>"
                            placeholder="Efectivo y equivalentes de efectivo"
                        >
                    </div>

                    <div class="col-md-4">
                        <label for="tipo_cuenta" class="form-label">Tipo de cuenta</label>
                        <select
                            class="form-select"
                            id="tipo_cuenta"
                            name="tipo_cuenta"
                            required
                        >
                            <option value="">Seleccionar...</option>
                            <?php foreach ($tiposCuenta as $tipo): ?>
                                <option
                                    value="<?= h($tipo) ?>"
                                    <?= (($_POST['tipo_cuenta'] ?? '') === $tipo) ? 'selected' : '' ?>
                                >
                                    <?= h($tipo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="cuenta_padre_id" class="form-label">
                            Cuenta superior
                        </label>
                        <select
                            class="form-select"
                            id="cuenta_padre_id"
                            name="cuenta_padre_id"
                        >
                            <option value="">Sin cuenta superior</option>

                            <?php foreach ($cuentasPadre as $padre): ?>
                                <option
                                    value="<?= (int) $padre['id'] ?>"
                                    <?= ((string) ($_POST['cuenta_padre_id'] ?? '') === (string) $padre['id']) ? 'selected' : '' ?>
                                >
                                    <?= h($padre['codigo'] . ' - ' . $padre['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Selecciona una cuenta de agrupación existente.
                        </small>
                    </div>

                    <div class="col-md-4">
                        <label for="nivel" class="form-label">Nivel</label>
                        <select class="form-select" id="nivel" name="nivel" required>
                            <?php for ($i = 1; $i <= 9; $i++): ?>
                                <option
                                    value="<?= $i ?>"
                                    <?= ((string) ($_POST['nivel'] ?? '1') === (string) $i) ? 'selected' : '' ?>
                                >
                                    Nivel <?= $i ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check mt-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="acepta_movimientos"
                                name="acepta_movimientos"
                                value="1"
                                <?= isset($_POST['acepta_movimientos']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="acepta_movimientos">
                                Esta cuenta acepta movimientos contables
                            </label>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check mt-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="requiere_tercero"
                                name="requiere_tercero"
                                value="1"
                                <?= isset($_POST['requiere_tercero']) ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="requiere_tercero">
                                Requiere tercero (cliente, proveedor u otro)
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            Guardar cuenta
                        </button>
                        <a href="plan_contable.php" class="btn btn-outline-secondary">
                            Limpiar formulario
                        </a>
                    </div>

                </div>
            </form>
        </div>
    </section>

    <section class="card tarjeta">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h5 mb-0">Cuentas registradas</h2>
                <span class="badge text-bg-primary">
                    Total: <?= count($cuentas) ?>
                </span>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <input
                        type="search"
                        id="buscarCuenta"
                        class="form-control"
                        placeholder="Buscar por código o nombre..."
                    >
                </div>

                <div class="col-md-6">
                    <select id="filtroTipo" class="form-select">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tiposCuenta as $tipo): ?>
                            <option value="<?= h($tipo) ?>"><?= h($tipo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="tabla-contenedor">
                <table class="table table-hover align-middle" id="tablaCuentas">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Cuenta superior</th>
                            <th>Nivel</th>
                            <th>Tipo</th>
                            <th>Movimientos</th>
                            <th>Tercero</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$cuentas): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                Todavía no hay cuentas registradas para esta empresa.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cuentas as $cuenta): ?>
                            <tr
                                data-tipo="<?= h($cuenta['tipo_cuenta']) ?>"
                                data-busqueda="<?= h(mb_strtolower(
                                    $cuenta['codigo'] . ' ' . $cuenta['nombre'],
                                    'UTF-8'
                                )) ?>"
                            >
                                <td class="codigo"><?= h($cuenta['codigo']) ?></td>
                                <td><?= h($cuenta['nombre']) ?></td>
                                <td>
                                    <?php if ($cuenta['codigo_padre'] !== null): ?>
                                        <?= h($cuenta['codigo_padre'] . ' - ' . $cuenta['nombre_padre']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Principal</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $cuenta['nivel'] ?></td>
                                <td><?= h($cuenta['tipo_cuenta']) ?></td>
                                <td>
                                    <?= (int) $cuenta['acepta_movimientos'] === 1 ? 'Sí' : 'No' ?>
                                </td>
                                <td>
                                    <?= (int) $cuenta['requiere_tercero'] === 1 ? 'Sí' : 'No' ?>
                                </td>
                                <td>
                                    <?php if ((int) $cuenta['estado'] === 1): ?>
                                        <span class="badge text-bg-success">Activa</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
    <a
        class="btn btn-sm btn-outline-primary mb-1"
        href="editar_cuenta.php?id=<?= (int) $cuenta['id'] ?>"
    >
        Editar
    </a>

    <form
        method="POST"
        action="cambiar_estado_cuenta.php"
        class="d-inline"
        onsubmit="return confirm('¿Confirmas esta operación?');"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($csrfToken) ?>"
        >
        <input
            type="hidden"
            name="id"
            value="<?= (int) $cuenta['id'] ?>"
        >

        <?php if ((int) $cuenta['estado'] === 1): ?>
            <input type="hidden" name="accion" value="desactivar">
            <button type="submit" class="btn btn-sm btn-outline-danger mb-1">
                Desactivar
            </button>
        <?php else: ?>
            <input type="hidden" name="accion" value="activar">
            <button type="submit" class="btn btn-sm btn-outline-success mb-1">
                Reactivar
            </button>
        <?php endif; ?>
    </form>
</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <footer class="text-center text-muted py-4">
        EMDESI Contable · Gestión por empresa
    </footer>

</div>

<script>
const buscarCuenta = document.getElementById('buscarCuenta');
const filtroTipo = document.getElementById('filtroTipo');

function filtrarCuentas() {
    const texto = buscarCuenta.value.trim().toLocaleLowerCase();
    const tipo = filtroTipo.value;

    document.querySelectorAll('#tablaCuentas tbody tr[data-tipo]')
        .forEach(fila => {
            const coincideTexto = fila.dataset.busqueda.includes(texto);
            const coincideTipo = !tipo || fila.dataset.tipo === tipo;

            fila.hidden = !(coincideTexto && coincideTipo);
        });
}

buscarCuenta.addEventListener('input', filtrarCuentas);
filtroTipo.addEventListener('change', filtrarCuentas);
</script>

</body>
</html>
