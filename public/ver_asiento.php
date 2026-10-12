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
    header('Location: /emdesi_contable/public/dashboard.php');
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(400);
    exit('El identificador del asiento no es válido.');
}

// Verificar acceso a la empresa activa.
$stmt = $pdo->prepare(
    'SELECT e.id, e.razon_social
     FROM empresas e
     INNER JOIN usuario_empresa ue
        ON ue.empresa_id = e.id
     WHERE e.id = ?
       AND ue.usuario_id = ?
       AND e.estado = 1'
);
$stmt->execute([$empresaId, $usuarioId]);
$empresa = $stmt->fetch();

if (!$empresa) {
    http_response_code(403);
    exit('No tienes acceso a esta empresa.');
}

// Obtener cabecera del asiento.
$stmt = $pdo->prepare(
    'SELECT
        a.id,
        a.numero,
        a.fecha,
        a.glosa,
        a.estado,
        a.periodo_id,
        p.anio,
        p.mes,
        p.fecha_inicio,
        p.fecha_fin
     FROM asientos_contables a
     INNER JOIN periodos_contables p
        ON p.id = a.periodo_id
       AND p.empresa_id = a.empresa_id
     WHERE a.id = ?
       AND a.empresa_id = ?'
);
$stmt->execute([$id, $empresaId]);
$asiento = $stmt->fetch();

if (!$asiento) {
    http_response_code(404);
    exit('El asiento no existe en la empresa activa.');
}

// Obtener las líneas contables.
$stmt = $pdo->prepare(
    'SELECT
        d.id,
        d.glosa,
        d.debe,
        d.haber,
        c.codigo,
        c.nombre
     FROM detalle_asientos d
     INNER JOIN asientos_contables a
        ON a.id = d.asiento_id
     INNER JOIN plan_cuentas c
        ON c.id = d.cuenta_id
       AND c.empresa_id = a.empresa_id
     WHERE d.asiento_id = ?
       AND a.empresa_id = ?
     ORDER BY d.id'
);
$stmt->execute([$id, $empresaId]);
$lineas = $stmt->fetchAll();

$totalDebe = 0;
$totalHaber = 0;

foreach ($lineas as $linea) {
    $totalDebe += (int) round((float) $linea['debe'] * 100);
    $totalHaber += (int) round((float) $linea['haber'] * 100);
}

$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo',
    4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre',
    10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

$estadoClase = match ($asiento['estado']) {
    'CONTABILIZADO' => 'success',
    'ANULADO' => 'secondary',
    default => 'warning'
};

function dinero(int $centimos): string
{
    return number_format($centimos / 100, 2, '.', ',');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle del asiento | EMDESI Contable</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">
            EMDESI Contable
        </a>

        <a class="btn btn-outline-light btn-sm"
           href="asientos_contables.php">
            Volver a asientos
        </a>
    </div>
</nav>

<main class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">
                Asiento N.º <?= (int) $asiento['numero'] ?>
            </h1>
            <p class="text-muted mb-0">
                <?= h($empresa['razon_social']) ?>
            </p>
        </div>

        <span class="badge text-bg-<?= $estadoClase ?> fs-6">
            <?= h($asiento['estado']) ?>
        </span>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <strong>Información general</strong>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Número</div>
                    <strong><?= (int) $asiento['numero'] ?></strong>
                </div>

                <div class="col-md-4">
                    <div class="text-muted small">Fecha</div>
                    <strong><?= h($asiento['fecha']) ?></strong>
                </div>

                <div class="col-md-4">
                    <div class="text-muted small">Período</div>
                    <strong>
                        <?= h($nombresMeses[(int) $asiento['mes']]) ?>
                        <?= (int) $asiento['anio'] ?>
                    </strong>
                </div>

                <div class="col-12">
                    <div class="text-muted small">Descripción</div>
                    <div><?= h($asiento['glosa']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Detalle contable</strong>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Código</th>
                        <th>Cuenta contable</th>
                        <th>Descripción de línea</th>
                        <th class="text-end">Debe (S/)</th>
                        <th class="text-end">Haber (S/)</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$lineas): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            Este asiento no contiene líneas de detalle.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lineas as $linea): ?>
                        <tr>
                            <td><?= h($linea['codigo']) ?></td>
                            <td><?= h($linea['nombre']) ?></td>
                            <td><?= h($linea['glosa'] ?? '') ?></td>
                            <td class="text-end">
                                <?= dinero((int) round((float) $linea['debe'] * 100)) ?>
                            </td>
                            <td class="text-end">
                                <?= dinero((int) round((float) $linea['haber'] * 100)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" class="text-end">Totales</th>
                        <th class="text-end">
                            S/ <?= dinero($totalDebe) ?>
                        </th>
                        <th class="text-end">
                            S/ <?= dinero($totalHaber) ?>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <?php if ($totalDebe === $totalHaber && $totalDebe > 0): ?>
            <div class="alert alert-success">
                <strong>Asiento cuadrado.</strong>
                El total del debe coincide con el total del haber.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <strong>Atención:</strong>
                los totales no coinciden o el asiento no contiene importes.
                Revisa el registro antes de utilizarlo en reportes contables.
            </div>
        <?php endif; ?>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <a href="asientos_contables.php"
           class="btn btn-outline-secondary">
            Volver al listado
        </a>

        <?php if ($asiento['estado'] === 'BORRADOR'): ?>
            <span class="text-muted small align-self-center">
                La edición controlada de borradores se implementará en el siguiente paso.
            </span>
        <?php endif; ?>
    </div>

</main>
</body>
</html>
