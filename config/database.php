<?php

declare(strict_types=1);

/**
 * Conexion principal a la base de datos.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar las credenciales locales.
$configPath = __DIR__ . '/database.local.php';

if (!file_exists($configPath)) {
    throw new RuntimeException(
        'No se encontro el archivo de configuracion de la base de datos.'
    );
}

$config = require $configPath;

// Construir la cadena de conexion.
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['database'],
    $config['charset']
);

try {

    // Crear la conexion PDO.
    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    // Registrar el error detallado en el registro del servidor.
    error_log('Error de conexion MySQL: ' . $e->getMessage());

    // Mostrar un mensaje generico sin revelar credenciales.
    throw new RuntimeException(
        'No fue posible conectar con la base de datos.'
    );

}

// Devolver la conexion para utilizarla desde otros archivos.
return $pdo;
