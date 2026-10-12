<?php
declare(strict_types=1);

$versionPHP = PHP_VERSION;
$versionCorrecta = version_compare($versionPHP, '8.1.0', '>=')
    && version_compare($versionPHP, '8.2.0', '<');

$extensiones = [
    'PDO' => extension_loaded('PDO'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMDESI Contable - Verificación</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 40px 16px;
            color: #1e293b;
        }
        .contenedor {
            max-width: 650px;
            margin: auto;
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 5px 20px #00000010;
        }
        h1 { color: #14532d; }
        .fila {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .correcto { color: #15803d; font-weight: bold; }
        .error { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
<div class="contenedor">
    <h1>EMDESI Contable</h1>
    <p>Verificación del entorno de desarrollo local.</p>

    <div class="fila">
        <span>Versión instalada de PHP</span>
        <strong><?= htmlspecialchars($versionPHP) ?></strong>
    </div>

    <div class="fila">
        <span>PHP 8.1.x</span>
        <span class="<?= $versionCorrecta ? 'correcto' : 'error' ?>">
            <?= $versionCorrecta ? 'Correcto' : 'Revisar versión' ?>
        </span>
    </div>

    <?php foreach ($extensiones as $nombre => $disponible): ?>
        <div class="fila">
            <span><?= htmlspecialchars($nombre) ?></span>
            <span class="<?= $disponible ? 'correcto' : 'error' ?>">
                <?= $disponible ? 'Disponible' : 'No disponible' ?>
            </span>
        </div>
    <?php endforeach; ?>

    <p>Cuando todo esté correcto, continuaremos con MySQL.</p>
</div>
</body>
</html>
