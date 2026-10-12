<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/auth.php';

exigirSesion();

if (!function_exists('h')) {
    function h(mixed $valor): string
    {
        return htmlspecialchars(
            (string) $valor,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


$pdo = obtenerConexion();
$usuarioId = (int) $_SESSION['usuario_id'];
$empresaId = (int) ($_SESSION['empresa_activa_id'] ?? 0);

if ($empresaId <= 0) {
    header('Location: /contable/public/dashboard.php');
    exit;
}



// Verificar que el usuario tenga acceso a la empresa activa.
$stmt = $pdo->prepare(
    'SELECT id, razon_social
     FROM empresas
     WHERE id = ?
       AND estado = 1
       AND EXISTS (
           SELECT 1
           FROM usuario_empresa ue
           WHERE ue.empresa_id = empresas.id
             AND ue.usuario_id = ?
       )'
);
$stmt->execute([$empresaId, $usuarioId]);
$empresa = $stmt->fetch();

if (!$empresa) {
    unset($_SESSION['empresa_id']);
    header('Location: /emdesi_contable/public/seleccionar_empresa.php');
    exit;
}

$mensaje = '';
$error = '';

$mensaje = $_SESSION['periodos_mensaje'] ?? '';
$error = $_SESSION['periodos_error'] ?? '';

unset(
    $_SESSION['periodos_mensaje'],
    $_SESSION['periodos_error']
);

$csrfToken = generarTokenCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario ha vencido. Recarga la página.';
    } else {
        $accion = $_POST['accion'] ?? '';

        try {
            if ($accion === 'crear') {
                $anio = filter_var(
                    $_POST['anio'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $mes = filter_var(
                    $_POST['mes'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    $anio === false ||
                    $mes === false ||
                    $anio < 2000 ||
                    $anio > 2100 ||
                    $mes < 1 ||
                    $mes > 12
                ) {
                    throw new RuntimeException(
                        'Ingresa un año entre 2000 y 2100 y un mes válido.'
                    );
                }

                $fechaInicio = sprintf(
                    '%04d-%02d-01',
                    $anio,
                    $mes
                );

                $fechaFin = date(
                    'Y-m-t',
                    strtotime($fechaInicio)
                );

                $stmt = $pdo->prepare(
                    'INSERT INTO periodos_contables
                    (
                        empresa_id,
                        anio,
                        mes,
                        fecha_inicio,
                        fecha_fin,
                        estado
                    )
                    VALUES (?, ?, ?, ?, ?, "ABIERTO")'
                );

                $stmt->execute([
                    $empresaId,
                    $anio,
                    $mes,
                    $fechaInicio,
                    $fechaFin
                ]);

                $_SESSION['periodos_mensaje'] =
                    'Período contable creado correctamente.';

                header('Location: periodos_contables.php');
                exit;
            }

            if ($accion === 'cambiar_estado') {
                $periodoId = filter_var(
                    $_POST['periodo_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                $nuevoEstado = $_POST['nuevo_estado'] ?? '';

                if (
                    !$periodoId ||
                    !in_array(
                        $nuevoEstado,
                        ['ABIERTO', 'CERRADO'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Los datos enviados no son válidos.'
                    );
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'SELECT id, estado
                     FROM periodos_contables
                     WHERE id = ?
                       AND empresa_id = ?
                     FOR UPDATE'
                );

                $stmt->execute([$periodoId, $empresaId]);
                $periodo = $stmt->fetch();

                if (!$periodo) {
                    throw new RuntimeException(
                        'El período no existe en la empresa activa.'
                    );
                }

                if (
                    $periodo['estado'] === $nuevoEstado
                ) {
                    throw new RuntimeException(
                        'El período ya tiene ese estado.'
                    );
                }

                if ($nuevoEstado === 'CERRADO') {
                    $stmt = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM asientos_contables
                         WHERE periodo_id = ?
                           AND empresa_id = ?
                           AND estado = "BORRADOR"'
                    );

                    $stmt->execute([
                        $periodoId,
                        $empresaId
                    ]);

                    if ((int) $stmt->fetchColumn() > 0) {
                        throw new RuntimeException(
                            'No puedes cerrar el período mientras tenga asientos en borrador.'
                        );
                    }
                }

                $stmt = $pdo->prepare(
                    'UPDATE periodos_contables
                     SET estado = ?
                     WHERE id = ?
                       AND empresa_id = ?'
                );

                $stmt->execute([
                    $nuevoEstado,
                    $periodoId,
                    $empresaId
                ]);

                $pdo->commit();

                $_SESSION['periodos_mensaje'] =
                    $nuevoEstado === 'CERRADO'
                    ? 'Período cerrado correctamente.'
                    : 'Período abierto correctamente.';

                header('Location: periodos_contables.php');
                exit;
            }

            throw new RuntimeException(
                'La operación solicitada no es válida.'
            );

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $error = 'Este período ya existe para la empresa seleccionada.';
            } else {
                $error = 'No se pudo completar la operación. Revisa el registro de errores.';
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT id, anio, mes, fecha_inicio, fecha_fin, estado
     FROM periodos_contables
     WHERE empresa_id = ?
     ORDER BY anio DESC, mes DESC'
);

$stmt->execute([$empresaId]);
$periodos = $stmt->fetchAll();

$nombresMeses = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Períodos contables | EMDESI Contable</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <a
            class="navbar-brand"
            href="dashboard.php"
        >
            EMDESI Contable
        </a>

        <a
            class="btn btn-outline-light btn-sm"
            href="dashboard.php"
        >
            Volver al panel
        </a>
    </div>
</nav>

<main class="container py-4">

    <div class="mb-4">
        <h1 class="h3">Períodos contables</h1>
        <p class="text-muted mb-1">
            Empresa:
            <strong><?= h($empresa['razon_social']) ?></strong>
        </p>
        <p class="text-muted">
            Administra los períodos mensuales de esta empresa.
        </p>
    </div>

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

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <strong>Crear período mensual</strong>
        </div>

        <div class="card-body">
            <form method="POST" class="row g-3">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="crear"
                >

                <div class="col-md-4">
                    <label for="anio" class="form-label">
                        Año
                    </label>

                    <input
                        type="number"
                        class="form-control"
                        id="anio"
                        name="anio"
                        min="2000"
                        max="2100"
                        value="<?= date('Y') ?>"
                        required
                    >
                </div>

                <div class="col-md-4">
                    <label for="mes" class="form-label">
                        Mes
                    </label>

                    <select
                        class="form-select"
                        id="mes"
                        name="mes"
                        required
                    >
                        <?php foreach ($nombresMeses as $numero => $nombre): ?>
                            <option
                                value="<?= $numero ?>"
                                <?= $numero === (int) date('n') ? 'selected' : '' ?>
                            >
                                <?= h($nombre) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                    >
                        Crear período
                    </button>
                </div>
            </form>

            <div class="form-text mt-3">
                Las fechas de inicio y fin se calculan automáticamente.
                No se permiten períodos duplicados para una misma empresa.
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Períodos registrados</strong>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Año</th>
                        <th>Mes</th>
                        <th>Fecha inicial</th>
                        <th>Fecha final</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$periodos): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            Todavía no hay períodos contables.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($periodos as $periodo): ?>
                        <tr>
                            <td><?= (int) $periodo['anio'] ?></td>

                            <td>
                                <?= h(
                                    $nombresMeses[(int) $periodo['mes']]
                                ) ?>
                            </td>

                            <td><?= h($periodo['fecha_inicio']) ?></td>
                            <td><?= h($periodo['fecha_fin']) ?></td>

                            <td>
                                <?php if ($periodo['estado'] === 'ABIERTO'): ?>
                                    <span class="badge text-bg-success">
                                        Abierto
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        Cerrado
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    class="d-inline"
                                    onsubmit="return confirm('¿Confirmas el cambio de estado del período?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="accion"
                                        value="cambiar_estado"
                                    >

                                    <input
                                        type="hidden"
                                        name="periodo_id"
                                        value="<?= (int) $periodo['id'] ?>"
                                    >

                                    <?php if ($periodo['estado'] === 'ABIERTO'): ?>
                                        <input
                                            type="hidden"
                                            name="nuevo_estado"
                                            value="CERRADO"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                        >
                                            Cerrar
                                        </button>
                                    <?php else: ?>
                                        <input
                                            type="hidden"
                                            name="nuevo_estado"
                                            value="ABIERTO"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-success"
                                        >
                                            Abrir
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

</main>
</body>
</html>
