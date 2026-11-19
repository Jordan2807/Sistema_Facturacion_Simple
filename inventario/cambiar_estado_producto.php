<?php

/**
 * CAMBIAR ESTADO DE PRODUCTO
 * Sistema de Facturación Sencilla y Control de Inventario.
 *
 * Permite activar o desactivar productos sin eliminarlos
 * de la base de datos ni modificar su historial.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

// Solamente el administrador puede cambiar estados.
requerirRol('administrador');


// ==========================================
// 2. PREPARAR FILTROS DE RETORNO
// ==========================================

$buscarEntrada = $_POST['buscar'] ?? '';
$categoriaEntrada = $_POST['categoria'] ?? '';
$estadoEntrada = $_POST['estado'] ?? '1';
$stockEntrada = $_POST['stock'] ?? '';
$paginaEntrada = $_POST['pagina'] ?? '1';

$buscar = is_string($buscarEntrada)
    ? substr(trim($buscarEntrada), 0, 150)
    : '';

$categoria = is_string($categoriaEntrada)
    ? substr(trim($categoriaEntrada), 0, 100)
    : '';

$estadoFiltro = is_string($estadoEntrada)
    ? $estadoEntrada
    : '1';

if (!in_array($estadoFiltro, ['', '0', '1'], true)) {
    $estadoFiltro = '1';
}

$stock = is_string($stockEntrada)
    ? $stockEntrada
    : '';

if (!in_array($stock, ['', 'bajo', 'agotado', 'disponible'], true)) {
    $stock = '';
}

$pagina = filter_var(
    $paginaEntrada,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$pagina = $pagina !== false ? $pagina : 1;


// ==========================================
// 3. FUNCION PARA REGRESAR AL INVENTARIO
// ==========================================

$crearUrlRetorno = static function (string $estadoDestino) use (
    $buscar,
    $categoria,
    $stock,
    $pagina
): string {

    return urlSistema(
        'inventario/index.php?' .
        http_build_query([
            'buscar' => $buscar,
            'categoria' => $categoria,
            'estado' => $estadoDestino,
            'stock' => $stock,
            'pagina' => $pagina
        ])
    );

};


// ==========================================
// 4. VALIDAR METODO DE SOLICITUD
// ==========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    establecerMensaje(
        'error',
        'La solicitud no es válida.'
    );

    header('Location: ' . $crearUrlRetorno($estadoFiltro));
    exit;

}


// ==========================================
// 5. VALIDAR TOKEN CSRF
// ==========================================

verificarTokenCSRF();


// ==========================================
// 6. VALIDAR IDENTIFICADOR
// ==========================================

$idEntrada = $_POST['id_producto'] ?? '';

$idProducto = filter_var(
    $idEntrada,
    FILTER_VALIDATE_INT
);

if ($idProducto === false || $idProducto <= 0) {

    establecerMensaje(
        'error',
        'El producto seleccionado no es válido.'
    );

    header('Location: ' . $crearUrlRetorno($estadoFiltro));
    exit;

}


// ==========================================
// 7. VALIDAR NUEVO ESTADO
// ==========================================

$nuevoEstadoEntrada = $_POST['nuevo_estado'] ?? '';

if (
    !is_string($nuevoEstadoEntrada)
    || !in_array($nuevoEstadoEntrada, ['0', '1'], true)
) {

    establecerMensaje(
        'error',
        'El estado solicitado no es válido.'
    );

    header('Location: ' . $crearUrlRetorno($estadoFiltro));
    exit;

}

$nuevoEstado = (int) $nuevoEstadoEntrada;


// ==========================================
// 8. CONSULTAR Y ACTUALIZAR PRODUCTO
// ==========================================

try {

    $pdo->beginTransaction();

    // Bloquear el producto durante la actualización.
    $stmtProducto = $pdo->prepare(
        "SELECT id_producto, nombre, estado
         FROM productos
         WHERE id_producto = :id_producto
         FOR UPDATE"
    );

    $stmtProducto->execute([
        ':id_producto' => $idProducto
    ]);

    $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {

        $pdo->rollBack();

        establecerMensaje(
            'error',
            'El producto seleccionado no existe.'
        );

        header('Location: ' . $crearUrlRetorno($estadoFiltro));
        exit;

    }

    $estadoActual = (int) $producto['estado'];

    // Comprobar si realmente se necesita cambiar el estado.
    if ($estadoActual === $nuevoEstado) {

        $pdo->rollBack();

        establecerMensaje(
            'info',
            'El producto ya se encontraba en ese estado.'
        );

        header('Location: ' . $crearUrlRetorno((string) $nuevoEstado));
        exit;

    }

    // Actualizar únicamente el estado.
    $stmtActualizar = $pdo->prepare(
        "UPDATE productos
         SET estado = :estado
         WHERE id_producto = :id_producto"
    );

    $stmtActualizar->execute([
        ':estado' => $nuevoEstado,
        ':id_producto' => $idProducto
    ]);

    if ($stmtActualizar->rowCount() !== 1) {

        throw new RuntimeException(
            'No fue posible actualizar el estado del producto.'
        );

    }

    $pdo->commit();


    // ==========================================
    // 9. MENSAJE DE CONFIRMACION
    // ==========================================

    if ($nuevoEstado === 1) {

        establecerMensaje(
            'success',
            'El producto "' . $producto['nombre']
            . '" fue reactivado correctamente.'
        );

    } else {

        establecerMensaje(
            'success',
            'El producto "' . $producto['nombre']
            . '" fue desactivado correctamente.'
        );

    }

    // Mostrar el producto en el filtro de su nuevo estado.
    header('Location: ' . $crearUrlRetorno((string) $nuevoEstado));
    exit;


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Error al cambiar estado del producto: '
        . $e->getMessage()
    );

    establecerMensaje(
        'error',
        'No fue posible cambiar el estado del producto. Inténtalo nuevamente.'
    );

    header('Location: ' . $crearUrlRetorno($estadoFiltro));
    exit;

}
