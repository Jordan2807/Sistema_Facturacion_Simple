<?php

/**
 * Prueba de conexion entre PHP y MySQL.
 */

try {

    // Obtener la conexion a la base de datos.
    $pdo = require __DIR__ . '/config/database.php';

    // Consultar informacion de la conexion.
    $consulta = $pdo->query(
        "SELECT DATABASE() AS base_datos, VERSION() AS version_mysql"
    );

    $resultado = $consulta->fetch();

    ?>

    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Prueba de conexion</title>
    </head>

    <body>

        <h1>Conexion exitosa</h1>

        <p>PHP se conecto correctamente con MySQL.</p>

        <p>
            <strong>Base de datos:</strong>
            <?= htmlspecialchars($resultado['base_datos']) ?>
        </p>

        <p>
            <strong>Version de MySQL:</strong>
            <?= htmlspecialchars($resultado['version_mysql']) ?>
        </p>

    </body>

    </html>

    <?php

} catch (Throwable $e) {

    error_log('Prueba de conexion: ' . $e->getMessage());

    http_response_code(500);

    echo '<h1>Error de conexion</h1>';
    echo '<p>No fue posible conectar con la base de datos.</p>';
    echo '<p>Revisa la configuracion de MySQL y PHP.</p>';

}
