<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

requerirRol('administrador', 'cajero');

/**
 * Respuesta JSON.
 */
function responderEscaneo(
    bool $exito,
    string $mensaje,
    ?array $producto = null,
    int $estadoHTTP = 200
): void {

    http_response_code($estadoHTTP);

    echo json_encode([
        'success' => $exito,
        'message' => $mensaje,
        'producto' => $producto
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;
}

/**
 * Solo se permiten solicitudes POST.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    responderEscaneo(
        false,
        'Método de solicitud no permitido.',
        null,
        405
    );

}

/**
 * Verificar CSRF.
 */
verificarTokenCSRF();

/**
 * Obtener el código leído.
 */
$codigoEntrada = $_POST['codigo_barras'] ?? '';

if (!is_string($codigoEntrada)) {
    responderEscaneo(false, 'El código recibido no es válido.', null, 422);
}

$codigo = trim($codigoEntrada);

if ($codigo === '' || strlen($codigo) > 50) {

    responderEscaneo(
        false,
        'El código de barras está vacío o no es válido.',
        null,
        422
    );

}

try {

    /**
     * Buscar coincidencia exacta del código.
     */
    $stmt = $pdo->prepare(
        "SELECT
            id_producto,
            codigo_barras,
            nombre,
            precio_venta,
            stock_actual,
            estado
         FROM productos
         WHERE codigo_barras = :codigo
         LIMIT 1"
    );

    $stmt->execute([
        ':codigo' => $codigo
    ]);

    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {

        responderEscaneo(
            false,
            'No se encontró ningún producto con ese código de barras.',
            null,
            404
        );

    }

    if ((int) $producto['estado'] !== 1) {

        responderEscaneo(
            false,
            'Este producto se encuentra inactivo.',
            null,
            409
        );

    }

    if ((int) $producto['stock_actual'] <= 0) {

        responderEscaneo(
            false,
            'Este producto no tiene existencias disponibles.',
            null,
            409
        );

    }

    /**
     * Devolver información actualizada del producto.
     */
    responderEscaneo(
        true,
        'Producto encontrado correctamente.',
        [
            'id' => (int) $producto['id_producto'],
            'codigo' => $producto['codigo_barras'],
            'nombre' => $producto['nombre'],
            'precio' => (float) $producto['precio_venta'],
            'stock' => (int) $producto['stock_actual']
        ]
    );

} catch (Throwable $e) {

    error_log(
        'Error al buscar producto mediante escáner: '
        . $e->getMessage()
    );

    responderEscaneo(
        false,
        'Ocurrió un error al buscar el producto.',
        null,
        500
    );

}
