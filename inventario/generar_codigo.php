<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

requerirRol('administrador', 'almacen');

/**
 * Responder en formato JSON.
 */
function responderJSON(
    bool $exito,
    string $mensaje,
    ?string $codigo = null,
    int $estadoHTTP = 200
): void {

    http_response_code($estadoHTTP);

    echo json_encode([
        'success' => $exito,
        'message' => $mensaje,
        'codigo' => $codigo
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;
}

/**
 * Solamente se permiten solicitudes POST.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJSON(false, 'Método de solicitud no permitido.', null, 405);
}

/**
 * Verificar protección CSRF.
 */
verificarTokenCSRF();

try {

    /**
     * Generar un código de 13 dígitos.
     *
     * Se permiten hasta 20 intentos para encontrar
     * un código que todavía no esté registrado.
     */
    $stmtVerificar = $pdo->prepare(
        "SELECT 1
         FROM productos
         WHERE codigo_barras = :codigo
         LIMIT 1"
    );

    $codigoDisponible = null;

    for ($intento = 0; $intento < 20; $intento++) {

        $codigoGenerado = (string) random_int(
            1000000000000,
            9999999999999
        );

        $stmtVerificar->execute([
            ':codigo' => $codigoGenerado
        ]);

        $existe = $stmtVerificar->fetchColumn();

        if ($existe === false) {
            $codigoDisponible = $codigoGenerado;
            break;
        }
    }

    if ($codigoDisponible === null) {

        responderJSON(
            false,
            'No fue posible encontrar un código disponible. Inténtalo nuevamente.',
            null,
            503
        );

    }

    responderJSON(
        true,
        'Código generado correctamente.',
        $codigoDisponible
    );

} catch (Throwable $e) {

    error_log(
        'Error al generar código de barras: ' . $e->getMessage()
    );

    responderJSON(
        false,
        'Ocurrió un error al generar el código de barras.',
        null,
        500
    );
}
