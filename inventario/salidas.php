<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador', 'almacen');

$tituloPagina = 'Registrar salida de inventario';
$paginaActual = 'inventario';

$errores = [];

$idProductoSeleccionado = '';
$cantidad = '';
$observaciones = '';

$usuarioActual = obtenerUsuarioActual();
$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);

/**
 * Procesar el formulario.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $idEntrada = $_POST['id_producto'] ?? '';
    $cantidadEntrada = $_POST['cantidad'] ?? '';
    $observacionesEntrada = $_POST['observaciones'] ?? '';

    $idProductoSeleccionado = is_scalar($idEntrada)
        ? trim((string) $idEntrada)
        : '';

    $cantidad = is_scalar($cantidadEntrada)
        ? trim((string) $cantidadEntrada)
        : '';

    $observaciones = is_scalar($observacionesEntrada)
        ? trim((string) $observacionesEntrada)
        : '';

    // Validar usuario.
    if ($idUsuario <= 0) {
        $errores[] = 'No se pudo identificar al usuario que realiza el movimiento.';
    }

    // Validar producto.
    $idProductoValidado = filter_var(
        $idProductoSeleccionado,
        FILTER_VALIDATE_INT
    );

    if ($idProductoValidado === false || $idProductoValidado <= 0) {
        $errores[] = 'Selecciona un producto válido.';
    }

    // Validar cantidad.
    $cantidadValidada = filter_var(
        $cantidad,
        FILTER_VALIDATE_INT
    );

    if (
        $cantidadValidada === false
        || $cantidadValidada <= 0
        || $cantidadValidada > 1000000
    ) {
        $errores[] = 'La cantidad debe ser un número entero entre 1 y 1.000.000.';
    }

    // Validar observaciones.
    $longitudObservaciones = function_exists('mb_strlen')
        ? mb_strlen($observaciones)
        : strlen($observaciones);

    if ($longitudObservaciones > 255) {
        $errores[] = 'Las observaciones no pueden superar los 255 caracteres.';
    }

    /**
     * Registrar salida.
     */
    if (empty($errores)) {

        try {

            $pdo->beginTransaction();

            /**
             * Bloquear el producto durante la operación.
             * Esto ayuda a evitar inconsistencias cuando
             * varios usuarios modifican el inventario.
             */
            $stmtProducto = $pdo->prepare(
                "SELECT
                    id_producto,
                    nombre,
                    stock_actual
                 FROM productos
                 WHERE id_producto = :id_producto
                   AND estado = 1
                 FOR UPDATE"
            );

            $stmtProducto->execute([
                ':id_producto' => $idProductoValidado
            ]);

            $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {

                $pdo->rollBack();

                $errores[] = 'El producto seleccionado no existe o se encuentra inactivo.';

            } else {

                $stockActual = (int) $producto['stock_actual'];

                /**
                 * No permitir retirar más unidades
                 * de las que están disponibles.
                 */
                if ($cantidadValidada > $stockActual) {

                    $pdo->rollBack();

                    $errores[] = 'No hay suficientes existencias. Actualmente hay '
                        . number_format($stockActual)
                        . ' unidades disponibles.';

                } else {

                    /**
                     * Descontar las existencias.
                     */
                    $stmtActualizar = $pdo->prepare(
                        "UPDATE productos
                        SET stock_actual = stock_actual - :cantidad_restar
                        WHERE id_producto = :id_producto
                        AND stock_actual >= :cantidad_disponible"
                    );

                    $stmtActualizar->execute([
                        ':cantidad_restar' => $cantidadValidada,
                        ':id_producto' => $idProductoValidado,
                        ':cantidad_disponible' => $cantidadValidada
                    ]);

                    if ($stmtActualizar->rowCount() !== 1) {
                        throw new RuntimeException(
                            'No fue posible actualizar las existencias del producto.'
                        );
                    }

                    /**
                     * Guardar el movimiento.
                     *
                     * id_venta queda en NULL porque es
                     * una salida manual, no una venta.
                     */
                    $stmtMovimiento = $pdo->prepare(
                        "INSERT INTO movimientos_inventario
                        (
                            id_producto,
                            id_usuario,
                            id_venta,
                            tipo_movimiento,
                            cantidad,
                            fecha_movimiento,
                            observaciones
                        )
                        VALUES
                        (
                            :id_producto,
                            :id_usuario,
                            NULL,
                            'salida',
                            :cantidad,
                            CURRENT_TIMESTAMP,
                            :observaciones
                        )"
                    );

                    $stmtMovimiento->execute([
                        ':id_producto' => $idProductoValidado,
                        ':id_usuario' => $idUsuario,
                        ':cantidad' => $cantidadValidada,
                        ':observaciones' => $observaciones !== ''
                            ? $observaciones
                            : null
                    ]);

                    $pdo->commit();

                    establecerMensaje(
                        'success',
                        'Se registró la salida de '
                        . number_format($cantidadValidada)
                        . ' unidades de '
                        . $producto['nombre']
                        . ' correctamente.'
                    );

                    header('Location: ' . urlSistema('inventario/index.php'));
                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Error al registrar salida de inventario: '
                . $e->getMessage()
            );

            $errores[] = 'Ocurrió un error al registrar la salida. Inténtalo nuevamente.';
        }
    }
}

/**
 * Obtener productos activos.
 */
$stmtProductos = $pdo->query(
    "SELECT
        id_producto,
        codigo_barras,
        nombre,
        stock_actual
     FROM productos
     WHERE estado = 1
     ORDER BY nombre ASC"
);

$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';

?>

<div class="container-fluid py-4">

    <div class="row">

        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

            <!-- Encabezado -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                <div>
                    <h1 class="h3 mb-1">
                        Registrar salida
                    </h1>

                    <p class="text-muted mb-0">
                        Registra productos que deben retirarse del inventario.
                    </p>
                </div>

                <a
                    href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver al inventario
                </a>

            </div>

            <!-- Errores -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger">

                    <strong>No se pudo registrar la salida:</strong>

                    <ul class="mb-0 mt-2">
                        <?php foreach ($errores as $error): ?>
                            <li><?= escapar($error) ?></li>
                        <?php endforeach; ?>
                    </ul>

                </div>

            <?php endif; ?>

            <?php if (empty($productos)): ?>

                <div class="card shadow-sm border-0">

                    <div class="card-body text-center py-5">

                        <i class="bi bi-box-seam display-4 text-muted"></i>

                        <h5 class="mt-3">
                            No hay productos disponibles
                        </h5>

                        <p class="text-muted">
                            No hay productos activos para registrar una salida.
                        </p>

                        <a
                            href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                            class="btn btn-success"
                        >
                            Volver al inventario
                        </a>

                    </div>

                </div>

            <?php else: ?>

                <!-- Formulario -->
                <div class="row justify-content-center">

                    <div class="col-xl-8 col-lg-10">

                        <div class="card shadow-sm border-0">

                            <div class="card-header bg-white py-3">

                                <h2 class="h5 mb-0">
                                    <i class="bi bi-box-arrow-up text-danger"></i>
                                    Información de la salida
                                </h2>

                            </div>

                            <div class="card-body">

                                <form method="POST" action="">

                                    <?= campoTokenCSRF() ?>

                                    <!-- Producto -->
                                    <div class="mb-4">

                                        <label for="id_producto" class="form-label">
                                            Producto
                                            <span class="text-danger">*</span>
                                        </label>

                                        <select
                                            name="id_producto"
                                            id="id_producto"
                                            class="form-select"
                                            required
                                        >

                                            <option value="">
                                                Selecciona un producto
                                            </option>

                                            <?php foreach ($productos as $producto): ?>

                                                <option
                                                    value="<?= (int) $producto['id_producto'] ?>"
                                                    <?= (string) $producto['id_producto'] === $idProductoSeleccionado ? 'selected' : '' ?>
                                                >
                                                    <?= escapar($producto['nombre']) ?>
                                                    — Código:
                                                    <?= escapar($producto['codigo_barras']) ?>
                                                    — Existencias:
                                                    <?= number_format((int) $producto['stock_actual']) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <div class="form-text">
                                            Las existencias disponibles aparecen junto al nombre del producto.
                                        </div>

                                    </div>

                                    <!-- Cantidad -->
                                    <div class="mb-4">

                                        <label for="cantidad" class="form-label">
                                            Cantidad que sale
                                            <span class="text-danger">*</span>
                                        </label>

                                        <input
                                            type="number"
                                            name="cantidad"
                                            id="cantidad"
                                            class="form-control"
                                            min="1"
                                            max="1000000"
                                            step="1"
                                            value="<?= escapar($cantidad) ?>"
                                            placeholder="Ejemplo: 5"
                                            required
                                        >

                                        <div class="form-text">
                                            No podrás retirar más unidades de las disponibles.
                                        </div>

                                    </div>

                                    <!-- Observaciones -->
                                    <div class="mb-4">

                                        <label for="observaciones" class="form-label">
                                            Motivo de la salida
                                        </label>

                                        <textarea
                                            name="observaciones"
                                            id="observaciones"
                                            class="form-control"
                                            rows="3"
                                            maxlength="255"
                                            placeholder="Ejemplo: Productos dañados, vencidos o retirados."
                                        ><?= escapar($observaciones) ?></textarea>

                                        <div class="form-text">
                                            Campo opcional. Puedes indicar por qué se retiran
                                            las unidades del inventario.
                                        </div>

                                    </div>

                                    <hr>

                                    <div class="d-flex justify-content-end gap-2">

                                        <a
                                            href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                                            class="btn btn-outline-secondary"
                                        >
                                            Cancelar
                                        </a>

                                        <button
                                            type="submit"
                                            class="btn btn-danger"
                                        >
                                            <i class="bi bi-check-circle"></i>
                                            Registrar salida
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                        <!-- Información -->
                        <div class="alert alert-warning mt-4">

                            <i class="bi bi-exclamation-triangle"></i>

                            <strong>Importante:</strong>

                            al confirmar, las existencias disminuirán automáticamente.
                            La operación quedará registrada en el historial de movimientos.

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </main>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
