<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/auth.php';

exigirSesion();

if (!function_exists('h')) {
    function h(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

$pdo = obtenerConexion();
$usuarioId = (int) $_SESSION['usuario_id'];
$empresaId = (int) ($_SESSION['empresa_activa_id'] ?? 0);

if ($empresaId <= 0) {
    header('Location: /emdesi_contable/public/dashboard.php');
    exit;
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
    unset(
        $_SESSION['empresa_activa_id'],
        $_SESSION['empresa_activa_nombre'],
        $_SESSION['empresa_activa_ruc']
    );

    header('Location: /emdesi_contable/public/dashboard.php');
    exit;
}

$mensaje = $_SESSION['asientos_mensaje'] ?? '';
$error = $_SESSION['asientos_error'] ?? '';

unset($_SESSION['asientos_mensaje'], $_SESSION['asientos_error']);

$csrfToken = generarTokenCSRF();

function convertirImporteCentimos(string $importe): int
{
    $importe = trim($importe);

    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $importe)) {
        throw new RuntimeException(
            'Ingresa importes válidos con máximo dos decimales.'
        );
    }

    $partes = explode('.', $importe);
    $entero = $partes[0];
    $decimal = str_pad($partes[1] ?? '', 2, '0');

    return ((int) $entero * 100) + (int) $decimal;
}

function importeDecimal(int $centimos): string
{
    return sprintf(
        '%d.%02d',
        intdiv($centimos, 100),
        $centimos % 100
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException(
                'Token de seguridad inválido. Recarga la página.'
            );
        }

        $accion = $_POST['accion'] ?? '';

        if (!in_array($accion, ['borrador', 'contabilizar'], true)) {
            throw new RuntimeException('Operación no válida.');
        }

        $periodoId = filter_var(
            $_POST['periodo_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $fecha = trim((string) ($_POST['fecha'] ?? ''));
        $glosa = trim((string) ($_POST['glosa'] ?? ''));

        if (!$periodoId || $glosa === '' || strlen($glosa) > 500) {
            throw new RuntimeException(
                'Selecciona un período e ingresa una descripción válida.'
            );
        }

        $fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

        if (
            !$fechaObjeto ||
            $fechaObjeto->format('Y-m-d') !== $fecha
        ) {
            throw new RuntimeException('La fecha del asiento no es válida.');
        }

        $cuentasPost = $_POST['cuenta_id'] ?? [];
        $debesPost = $_POST['debe'] ?? [];
        $haberesPost = $_POST['haber'] ?? [];
        $glosasPost = $_POST['glosa_detalle'] ?? [];

        if (
            !is_array($cuentasPost) ||
            !is_array($debesPost) ||
            !is_array($haberesPost) ||
            !is_array($glosasPost) ||
            count($cuentasPost) < 2 ||
            count($cuentasPost) > 100 ||
            count($cuentasPost) !== count($debesPost) ||
            count($cuentasPost) !== count($haberesPost) ||
            count($cuentasPost) !== count($glosasPost)
        ) {
            throw new RuntimeException(
                'El asiento debe contener entre 2 y 100 líneas válidas.'
            );
        }

        $lineas = [];
        $cuentasUsadas = [];
        $totalDebe = 0;
        $totalHaber = 0;

        foreach ($cuentasPost as $i => $cuentaValor) {
            $cuentaId = filter_var(
                $cuentaValor,
                FILTER_VALIDATE_INT
            );

            if (!$cuentaId || $cuentaId <= 0) {
                throw new RuntimeException(
                    'Selecciona una cuenta válida en cada línea.'
                );
            }

            $debe = convertirImporteCentimos(
                (string) $debesPost[$i]
            );

            $haber = convertirImporteCentimos(
                (string) $haberesPost[$i]
            );

            if (
                ($debe === 0 && $haber === 0) ||
                ($debe > 0 && $haber > 0)
            ) {
                throw new RuntimeException(
                    'Cada línea debe tener un importe positivo en debe o en haber, pero no en ambos.'
                );
            }

            $glosaDetalle = trim((string) $glosasPost[$i]);

            if (strlen($glosaDetalle) > 300) {
                throw new RuntimeException(
                    'La descripción de una línea supera los 300 caracteres.'
                );
            }

            $lineas[] = [
                'cuenta_id' => $cuentaId,
                'debe' => $debe,
                'haber' => $haber,
                'glosa' => $glosaDetalle !== '' ? $glosaDetalle : null
            ];

            $cuentasUsadas[$cuentaId] = true;
            $totalDebe += $debe;
            $totalHaber += $haber;
        }

        if (
            $accion === 'contabilizar' &&
            $totalDebe !== $totalHaber
        ) {
            throw new RuntimeException(
                'No se puede contabilizar: el debe y el haber deben ser iguales. Diferencia: S/ ' .
                number_format(abs($totalDebe - $totalHaber) / 100, 2, '.', ',')
            );
        }

        $pdo->beginTransaction();

        // Bloquear la empresa para serializar la numeración.
        $stmt = $pdo->prepare(
            'SELECT id
             FROM empresas
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$empresaId]);

        if (!$stmt->fetch()) {
            throw new RuntimeException('La empresa ya no está disponible.');
        }

        // Comprobar que el período pertenece a la empresa,
        // está abierto y contiene la fecha del asiento.
        $stmt = $pdo->prepare(
            'SELECT id, fecha_inicio, fecha_fin, estado
             FROM periodos_contables
             WHERE id = ?
               AND empresa_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$periodoId, $empresaId]);
        $periodo = $stmt->fetch();

        if (!$periodo || $periodo['estado'] !== 'ABIERTO') {
            throw new RuntimeException(
                'El período no existe o está cerrado.'
            );
        }

        if (
            $fecha < $periodo['fecha_inicio'] ||
            $fecha > $periodo['fecha_fin']
        ) {
            throw new RuntimeException(
                'La fecha del asiento debe estar dentro del período seleccionado.'
            );
        }

        // Validar todas las cuentas dentro de la empresa activa.
        $idsCuentas = array_keys($cuentasUsadas);
        $marcadores = implode(',', array_fill(0, count($idsCuentas), '?'));

        $stmt = $pdo->prepare(
            "SELECT id
             FROM plan_cuentas
             WHERE empresa_id = ?
               AND estado = 1
               AND acepta_movimientos = 1
               AND id IN ($marcadores)"
        );

        $stmt->execute(array_merge([$empresaId], $idsCuentas));
        $cuentasValidas = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        if (count($cuentasValidas) !== count($cuentasUsadas)) {
            throw new RuntimeException(
                'Una o más cuentas están inactivas, no aceptan movimientos o no pertenecen a esta empresa.'
            );
        }

        // Obtener numeración correlativa de la empresa.
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(numero), 0) + 1
             FROM asientos_contables
             WHERE empresa_id = ?'
        );
        $stmt->execute([$empresaId]);
        $numero = (int) $stmt->fetchColumn();

        $estado = $accion === 'contabilizar'
            ? 'CONTABILIZADO'
            : 'BORRADOR';

        $stmt = $pdo->prepare(
            'INSERT INTO asientos_contables
            (
                empresa_id,
                periodo_id,
                numero,
                fecha,
                glosa,
                estado,
                creado_por
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $empresaId,
            $periodoId,
            $numero,
            $fecha,
            $glosa,
            $estado,
            $usuarioId
        ]);

        $asientoId = (int) $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare(
            'INSERT INTO detalle_asientos
            (
                asiento_id,
                cuenta_id,
                glosa,
                debe,
                haber
            )
            VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $linea) {
            $stmtDetalle->execute([
                $asientoId,
                $linea['cuenta_id'],
                $linea['glosa'],
                importeDecimal($linea['debe']),
                importeDecimal($linea['haber'])
            ]);
        }

        $pdo->commit();

        $_SESSION['asientos_mensaje'] =
            'Asiento N.º ' . $numero . ' guardado como ' .
            ($estado === 'CONTABILIZADO' ? 'contabilizado' : 'borrador') .
            '.';

        header('Location: asientos_contables.php');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException) {
            error_log($e->getMessage());
            $error = 'No se pudo guardar el asiento. Revisa los datos y el registro de errores.';
        } else {
            $error = $e->getMessage();
        }
    }
}

// Cargar períodos abiertos de la empresa.
$stmt = $pdo->prepare(
    'SELECT id, anio, mes, fecha_inicio, fecha_fin
     FROM periodos_contables
     WHERE empresa_id = ?
       AND estado = "ABIERTO"
     ORDER BY anio DESC, mes DESC'
);
$stmt->execute([$empresaId]);
$periodos = $stmt->fetchAll();

// Cargar cuentas de movimiento activas de la empresa.
$stmt = $pdo->prepare(
    'SELECT id, codigo, nombre
     FROM plan_cuentas
     WHERE empresa_id = ?
       AND estado = 1
       AND acepta_movimientos = 1
     ORDER BY codigo'
);
$stmt->execute([$empresaId]);
$cuentas = $stmt->fetchAll();

// Listado de asientos de la empresa activa.
$stmt = $pdo->prepare(
    'SELECT
        a.id,
        a.numero,
        a.fecha,
        a.glosa,
        a.estado,
        COALESCE(SUM(d.debe), 0) AS total_debe,
        COALESCE(SUM(d.haber), 0) AS total_haber
     FROM asientos_contables a
     LEFT JOIN detalle_asientos d
       ON d.asiento_id = a.id
     WHERE a.empresa_id = ?
     GROUP BY
        a.id, a.numero, a.fecha, a.glosa, a.estado
     ORDER BY a.numero DESC'
);
$stmt->execute([$empresaId]);
$asientos = $stmt->fetchAll();

$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo',
    4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre',
    10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

$periodoPredeterminado = $periodos[0]['id'] ?? '';
$fechaPredeterminada = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asientos contables | EMDESI</title>
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
        <a class="btn btn-outline-light btn-sm" href="dashboard.php">
            Volver al panel
        </a>
    </div>
</nav>

<main class="container py-4">

    <h1 class="h3">Asientos contables</h1>
    <p class="text-muted">
        Empresa: <strong><?= h($empresa['razon_social']) ?></strong>
    </p>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success"><?= h($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$periodos): ?>
        <div class="alert alert-warning">
            No hay períodos abiertos. Abre o crea un período antes de registrar asientos.
            <a href="periodos_contables.php">Ir a períodos contables</a>
        </div>
    <?php elseif (!$cuentas): ?>
        <div class="alert alert-warning">
            No hay cuentas activas que acepten movimientos.
            Revisa el plan contable antes de continuar.
            <a href="plan_contable.php">Ir al plan contable</a>
        </div>
    <?php else: ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <strong>Registrar nuevo asiento</strong>
        </div>

        <div class="card-body">
            <form method="POST" id="formAsiento">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <label class="form-label" for="periodo_id">
                            Período contable
                        </label>
                        <select
                            class="form-select"
                            id="periodo_id"
                            name="periodo_id"
                            required
                        >
                            <?php foreach ($periodos as $periodo): ?>
                                <option
                                    value="<?= (int) $periodo['id'] ?>"
                                    data-inicio="<?= h($periodo['fecha_inicio']) ?>"
                                    data-fin="<?= h($periodo['fecha_fin']) ?>"
                                    <?= (string) $periodo['id'] === (string) $periodoPredeterminado ? 'selected' : '' ?>
                                >
                                    <?= h($nombresMeses[(int) $periodo['mes']]) ?>
                                    <?= (int) $periodo['anio'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="fecha">Fecha</label>
                        <input
                            type="date"
                            class="form-control"
                            id="fecha"
                            name="fecha"
                            value="<?= h($fechaPredeterminada) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="glosa">
                            Descripción del asiento
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="glosa"
                            name="glosa"
                            maxlength="500"
                            required
                            placeholder="Ej. Aporte inicial de capital"
                        >
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">Detalle del asiento</h2>
                    <button
                        type="button"
                        class="btn btn-outline-primary btn-sm"
                        id="agregarLinea"
                    >
                        + Agregar línea
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 240px">Cuenta contable</th>
                                <th style="min-width: 180px">Descripción</th>
                                <th style="min-width: 125px">Debe (S/)</th>
                                <th style="min-width: 125px">Haber (S/)</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="detalleLineas">
                            <?php for ($i = 0; $i < 2; $i++): ?>
                                <tr>
                                    <td>
                                        <select
                                            class="form-select cuenta"
                                            name="cuenta_id[]"
                                            required
                                        >
                                            <option value="">Seleccionar cuenta</option>
                                            <?php foreach ($cuentas as $cuenta): ?>
                                                <option value="<?= (int) $cuenta['id'] ?>">
                                                    <?= h($cuenta['codigo']) ?>
                                                    - <?= h($cuenta['nombre']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            type="text"
                                            class="form-control"
                                            name="glosa_detalle[]"
                                            maxlength="300"
                                            placeholder="Opcional"
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            class="form-control importe debe"
                                            name="debe[]"
                                            min="0"
                                            max="9999999999999.99"
                                            step="0.01"
                                            value="0.00"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            class="form-control importe haber"
                                            name="haber[]"
                                            min="0"
                                            max="9999999999999.99"
                                            step="0.01"
                                            value="0.00"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-outline-danger btn-sm quitarLinea"
                                        >
                                            Quitar
                                        </button>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Totales</th>
                                <th id="totalDebe">S/ 0.00</th>
                                <th id="totalHaber">S/ 0.00</th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="2" class="text-end">Diferencia</th>
                                <th colspan="3" id="diferencia">S/ 0.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div id="estadoCuadre" class="alert alert-info">
                    Completa las líneas del asiento. El servidor validará los importes antes de guardarlo.
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button
                        type="submit"
                        name="accion"
                        value="borrador"
                        class="btn btn-outline-primary"
                    >
                        Guardar borrador
                    </button>

                    <button
                        type="submit"
                        name="accion"
                        value="contabilizar"
                        class="btn btn-success"
                        id="btnContabilizar"
                    >
                        Contabilizar asiento
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Asientos registrados</strong>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>N.º</th>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Debe</th>
                        <th>Haber</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$asientos): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            Todavía no hay asientos registrados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($asientos as $asiento): ?>
                        <tr>
                            <td><?= (int) $asiento['numero'] ?></td>
                            <td><?= h($asiento['fecha']) ?></td>
                            <td><?= h($asiento['glosa']) ?></td>
                            <td>
                                S/ <?= number_format((float) $asiento['total_debe'], 2) ?>
                            </td>
                            <td>
                                S/ <?= number_format((float) $asiento['total_haber'], 2) ?>
                            </td>
                            <td>
                                <?php
                                $clase = match ($asiento['estado']) {
                                    'CONTABILIZADO' => 'success',
                                    'ANULADO' => 'secondary',
                                    default => 'warning'
                                };
                                ?>
                                <span class="badge text-bg-<?= $clase ?>">
                                    <?= h($asiento['estado']) ?>
                                </span>
                            </td>
                            <td>
    <a
        class="btn btn-sm btn-outline-primary"
        href="ver_asiento.php?id=<?= (int) $asiento['id'] ?>"
    >
        Ver detalle
    </a>
</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<script>
(() => {
    const cuerpo = document.getElementById('detalleLineas');
    if (!cuerpo) return;

    const form = document.getElementById('formAsiento');
    const selectorPeriodo = document.getElementById('periodo_id');
    const fecha = document.getElementById('fecha');
    const estadoCuadre = document.getElementById('estadoCuadre');
    const btnContabilizar = document.getElementById('btnContabilizar');

    const plantillaCuenta = <?= json_encode(
        array_map(
            static fn($c) => [
                'id' => (int) $c['id'],
                'texto' => $c['codigo'] . ' - ' . $c['nombre']
            ],
            $cuentas
        ),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    function actualizarFechaPeriodo() {
        const opcion = selectorPeriodo.selectedOptions[0];
        if (!opcion) return;

        fecha.min = opcion.dataset.inicio;
        fecha.max = opcion.dataset.fin;

        if (fecha.value < fecha.min || fecha.value > fecha.max) {
            fecha.value = fecha.min;
        }
    }

    function crearLinea() {
        if (cuerpo.rows.length >= 100) {
            alert('El asiento admite un máximo de 100 líneas.');
            return;
        }

        const fila = document.createElement('tr');

        const tdCuenta = document.createElement('td');
        const cuenta = document.createElement('select');
        cuenta.className = 'form-select cuenta';
        cuenta.name = 'cuenta_id[]';
        cuenta.required = true;

        const inicial = document.createElement('option');
        inicial.value = '';
        inicial.textContent = 'Seleccionar cuenta';
        cuenta.appendChild(inicial);

        plantillaCuenta.forEach(item => {
            const opcion = document.createElement('option');
            opcion.value = String(item.id);
            opcion.textContent = item.texto;
            cuenta.appendChild(opcion);
        });

        tdCuenta.appendChild(cuenta);

        const tdGlosa = document.createElement('td');
        const glosa = document.createElement('input');
        glosa.type = 'text';
        glosa.name = 'glosa_detalle[]';
        glosa.maxLength = 300;
        glosa.className = 'form-control';
        glosa.placeholder = 'Opcional';
        tdGlosa.appendChild(glosa);

        function crearImporte(tipo, nombre) {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'number';
            input.name = nombre + '[]';
            input.className = 'form-control importe ' + tipo;
            input.min = '0';
            input.max = '9999999999999.99';
            input.step = '0.01';
            input.value = '0.00';
            input.required = true;
            td.appendChild(input);
            return { td, input };
        }

        const debe = crearImporte('debe', 'debe');
        const haber = crearImporte('haber', 'haber');

        const tdAccion = document.createElement('td');
        const quitar = document.createElement('button');
        quitar.type = 'button';
        quitar.className = 'btn btn-outline-danger btn-sm quitarLinea';
        quitar.textContent = 'Quitar';
        tdAccion.appendChild(quitar);

        fila.append(
            tdCuenta,
            tdGlosa,
            debe.td,
            haber.td,
            tdAccion
        );

        cuerpo.appendChild(fila);
        calcularTotales();
    }

    function centimos(input) {
        const valor = input.value.trim();

        if (!/^\d+(?:\.\d{1,2})?$/.test(valor)) {
            return 0;
        }

        const partes = valor.split('.');
        return Number(partes[0]) * 100 +
            Number((partes[1] || '').padEnd(2, '0'));
    }

    function soles(centimosValor) {
        return 'S/ ' + (centimosValor / 100).toLocaleString('es-PE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function calcularTotales() {
        let debe = 0;
        let haber = 0;

        cuerpo.querySelectorAll('tr').forEach(fila => {
            debe += centimos(fila.querySelector('.debe'));
            haber += centimos(fila.querySelector('.haber'));
        });

        const diferencia = debe - haber;

        document.getElementById('totalDebe').textContent = soles(debe);
        document.getElementById('totalHaber').textContent = soles(haber);
        document.getElementById('diferencia').textContent = soles(Math.abs(diferencia));

        if (diferencia === 0 && debe > 0) {
            estadoCuadre.className = 'alert alert-success';
            estadoCuadre.textContent = 'El asiento está cuadrado. El servidor realizará la validación final.';
        } else {
            estadoCuadre.className = 'alert alert-warning';
            estadoCuadre.textContent = 'El asiento todavía no está cuadrado.';
        }
    }

    cuerpo.addEventListener('input', evento => {
        if (evento.target.matches('.importe')) {
            calcularTotales();
        }
    });

    cuerpo.addEventListener('click', evento => {
        const boton = evento.target.closest('.quitarLinea');
        if (!boton) return;

        if (cuerpo.rows.length <= 2) {
            alert('El asiento debe tener al menos dos líneas.');
            return;
        }

        boton.closest('tr').remove();
        calcularTotales();
    });

    document.getElementById('agregarLinea').addEventListener('click', crearLinea);

    selectorPeriodo.addEventListener('change', actualizarFechaPeriodo);

    form.addEventListener('submit', evento => {
        const boton = evento.submitter;

        if (boton && boton.value === 'contabilizar') {
            let debe = 0;
            let haber = 0;

            cuerpo.querySelectorAll('tr').forEach(fila => {
                debe += centimos(fila.querySelector('.debe'));
                haber += centimos(fila.querySelector('.haber'));
            });

            if (debe <= 0 || debe !== haber) {
                evento.preventDefault();
                alert('Para contabilizar, el debe y el haber deben ser iguales y mayores que cero.');
                return;
            }

            if (!confirm('¿Confirmas que deseas contabilizar este asiento?')) {
                evento.preventDefault();
            }
        }
    });

    actualizarFechaPeriodo();
    calcularTotales();
})();
</script>
</body>
</html>
