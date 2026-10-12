<?php
declare(strict_types=1);

/**
 * EMDESI Contable
 * Conexión a MySQL mediante PDO.
 * Compatible con PHP 8.1 y PHP 8.2.
 */

function obtenerConexion(): PDO
{
    $host = '127.0.0.1';
    $puerto = '3306';
    $baseDatos = 'emdesi_contable';

    // Configuración habitual de XAMPP local.
    // Si MySQL tiene contraseña, coloca aquí la contraseña local.
    $usuario = 'root';
    $clave = '';

    $dsn = "mysql:host={$host};port={$puerto};dbname={$baseDatos};charset=utf8mb4";

    $opciones = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, $usuario, $clave, $opciones);
}
