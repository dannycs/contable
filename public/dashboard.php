<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';

exigirSesion();

require_once dirname(__DIR__) . '/config/database.php';

$pdo = obtenerConexion();

$consultaEmpresas = $pdo->prepare(
    'SELECT e.id, e.ruc, e.razon_social
     FROM empresas e
     INNER JOIN usuario_empresa ue ON ue.empresa_id = e.id
     WHERE ue.usuario_id = :usuario_id
       AND e.estado = 1
     ORDER BY ue.es_principal DESC, e.razon_social'
);

$consultaEmpresas->execute([
    'usuario_id' => (int) $_SESSION['usuario_id']
]);

$empresasDisponibles = $consultaEmpresas->fetchAll();
$empresaActivaId = (int) ($_SESSION['empresa_activa_id'] ?? 0);



$nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
$rol = $_SESSION['usuario_rol'] ?? 'Sin rol';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel principal | EMDESI Contable</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }
        header {
            background: #14532d;
            color: white;
            padding: 20px;
        }
        main {
            max-width: 1000px;
            margin: 28px auto;
            padding: 0 16px;
        }
        .tarjeta {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 18px;
        }
        .modulos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .modulo {
            background: white;
            border-radius: 10px;
            padding: 20px;
        }
        button {
            background: #b91c1c;
            color: white;
            border: 0;
            padding: 10px 16px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<header>
    <h1>EMDESI Contable</h1>
    <p>Sistema Contable Integral</p>
</header>

<main>
    <section class="tarjeta">
        <h2>Bienvenido, <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></h2>
        <p>Perfil: <strong><?= htmlspecialchars($rol, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p>Sesión iniciada correctamente.</p>

        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(generarTokenCSRF(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </section>
    
<section class="tarjeta">
    <h2>Empresa de trabajo</h2>

    <?php if (isset($_SESSION['empresa_error'])): ?>
        <p>
            <?= htmlspecialchars(
                $_SESSION['empresa_error'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>
        <?php unset($_SESSION['empresa_error']); ?>
    <?php endif; ?>

    <?php if (empty($empresasDisponibles)): ?>
        <p>No tienes empresas activas asignadas.</p>
        <a href="empresas.php">Administrar empresas</a>
    <?php else: ?>
        <form method="post" action="seleccionar_empresa.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    generarTokenCSRF(),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <label for="empresa_id">Selecciona una empresa:</label>

            <select id="empresa_id" name="empresa_id" required>
                <?php foreach ($empresasDisponibles as $empresa): ?>
                    <option
                        value="<?= (int) $empresa['id'] ?>"
                        <?= $empresaActivaId === (int) $empresa['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= htmlspecialchars(
                            $empresa['razon_social'] . ' — ' . $empresa['ruc'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Seleccionar empresa</button>

        </form>
 
        <p>
            Empresa actual:
            <strong>
                <?= htmlspecialchars(
                    $_SESSION['empresa_activa_nombre']
                        ?? 'Selecciona una empresa',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </strong>
        </p>
    <?php endif; ?>
</section>


    <section class="modulos">
        <article class="modulo">
            <h3>🏢 Empresas</h3>
            <p>Registra y consulta las empresas asociadas.</p>
            <a href="empresas.php">Administrar empresas</a>
        </article>

        <article class="modulo">
            <h3>Plan contable</h3>
            <p>Registra y consulta las cuentas del plan contable.</p>
            <a href="plan_contable.php" class="btn btn-primary">
                Ingresar al plan contable
            </a>
        </article>
        <article class="modulo">
            <h3>Asientos contables</h3>
            <p>Registra y consulta los asientos contables.</p>
            <a href="asientos_contables.php" class="btn btn-outline-primary">
    Asientos contables
</a>
        </article>
        <article class="modulo">
            <h3>Libros y reportes</h3>
            <p>Próximamente: libros contables y estados financieros.</p>
        </article>
         <article class="modulo">
            <h3>Periodos contables</h3>
            <p>Registra y consulta los períodos contables.</p>
            <a href="periodos_contables.php" class="btn btn-primary">
                Ingresar a periodos contables
            </a>
        </article>
       
    </section>
</main>
</body>
</html>
