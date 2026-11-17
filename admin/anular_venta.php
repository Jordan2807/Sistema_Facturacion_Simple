<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador');

$tituloPagina = 'Anular venta';
$paginaActual = 'caja';

$errores = [];
$motivoAnulacion = '';

/**
 * Obtener identificador de venta.
 */
$idEntrada = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['id_venta'] ?? null)
    : ($_GET['id'] ?? null);

$idVenta = filter_var(
    $idEntrada,
    FILTER_VALIDATE_INT
);

if ($idVenta === false || $idVenta <= 0) {

    establecerMensaje('warning', 'La venta seleccionada no es válida.');

    header('Location: ' . urlSistema('caja/ventas.php'));
    exit;
}

$usuarioActual = obtenerUsuarioActual();
$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);

/**
 * Procesar anulación.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $motivoAnulacion = is_string($_POST['motivo_anulacion'] ?? null)
        ? trim($_POST['motivo_anulacion'])
        : '';

    $longitudMotivo = function_exists('mb_strlen')
        ? mb_strlen($motivoAnulacion)
        : strlen($motivoAnulacion);

    if ($motivoAnulacion === '') {

        $errores[] = 'Debes indicar el motivo de la anulación.';

    } elseif ($longitudMotivo < 5) {

        $errores[] = 'El motivo debe tener al menos 5 caracteres.';

    } elseif ($longitudMotivo > 255) {

        $errores[] = 'El motivo no puede superar los 255 caracteres.';

    }

    if ($idUsuario <= 0) {
        $errores[] = 'No se pudo identificar al administrador.';
    }

    if (empty($errores)) {

        try {

            $pdo->beginTransaction();

            /**
             * Bloquear la venta para impedir
             * anulaciones simultáneas.
             */
            $stmtBloquearVenta = $pdo->prepare(
                "SELECT
                    id_venta,
                    estado,
                    total
                 FROM ventas
                 WHERE id_venta = ?
                 FOR UPDATE"
            );

            $stmtBloquearVenta->execute([$idVenta]);

            $ventaBloqueada = $stmtBloquearVenta->fetch(PDO::FETCH_ASSOC);

            if (!$ventaBloqueada) {

                throw new DomainException(
                    'La venta solicitada no existe.'
                );

            }

            if ($ventaBloqueada['estado'] !== 'completada') {

                throw new DomainException(
                    'Esta venta ya fue anulada. No es posible repetir la operación.'
                );

            }

            /**
             * Agrupar los productos de la venta.
             */
            $stmtDetalles = $pdo->prepare(
                "SELECT
                    id_producto,
                    SUM(cantidad) AS cantidad_total
                 FROM detalle_venta
                 WHERE id_venta = ?
                 GROUP BY id_producto
                 ORDER BY id_producto ASC"
            );

            $stmtDetalles->execute([$idVenta]);

            $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

            if (empty($detalles)) {

                throw new DomainException(
                    'La venta no tiene productos registrados. No se puede realizar la anulación.'
                );

            }

            /**
             * Preparar consultas.
             */
            $stmtProducto = $pdo->prepare(
                "SELECT
                    id_producto,
                    nombre,
                    stock_actual
                 FROM productos
                 WHERE id_producto = ?
                 FOR UPDATE"
            );

            $stmtActualizarStock = $pdo->prepare(
                "UPDATE productos
                 SET stock_actual = stock_actual + ?
                 WHERE id_producto = ?"
            );

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
                    ?, ?, ?, 'entrada', ?, CURRENT_TIMESTAMP, ?
                )"
            );

            $numeroComprobante = generarNumeroComprobante($idVenta);

            /**
             * Devolver productos al inventario.
             */
            foreach ($detalles as $detalle) {

                $idProducto = (int) $detalle['id_producto'];
                $cantidad = (int) $detalle['cantidad_total'];

                if ($idProducto <= 0 || $cantidad <= 0) {

                    throw new RuntimeException(
                        'Se encontró un detalle de venta inválido.'
                    );

                }

                /**
                 * Bloquear producto.
                 * Se consulta incluso si está inactivo,
                 * porque debe recuperarse su inventario.
                 */
                $stmtProducto->execute([$idProducto]);

                $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

                if (!$producto) {

                    throw new RuntimeException(
                        'No se encontró el producto ID ' . $idProducto
                        . ' relacionado con la venta.'
                    );

                }

                $stockActual = (int) $producto['stock_actual'];

                // Evitar desbordamiento del campo INT.
                if ($stockActual > 2147483647 - $cantidad) {

                    throw new DomainException(
                        'No es posible devolver las unidades de '
                        . $producto['nombre']
                        . ' porque se superaría el límite de existencias.'
                    );

                }

                /**
                 * Aumentar existencias.
                 */
                $stmtActualizarStock->execute([
                    $cantidad,
                    $idProducto
                ]);

                if ($stmtActualizarStock->rowCount() !== 1) {

                    throw new RuntimeException(
                        'No se pudieron recuperar las existencias de '
                        . $producto['nombre']
                        . '.'
                    );

                }

                /**
                 * Registrar entrada vinculada
                 * a la venta que se está anulando.
                 */
                $observacionMovimiento =
                    'Reingreso por anulacion '
                    . $numeroComprobante
                    . '. Motivo: '
                    . $motivoAnulacion;

                if (function_exists('mb_substr')) {
                    $observacionMovimiento = mb_substr(
                        $observacionMovimiento,
                        0,
                        255
                    );
                } else {
                    $observacionMovimiento = substr(
                        $observacionMovimiento,
                        0,
                        255
                    );
                }

                $stmtMovimiento->execute([
                    $idProducto,
                    $idUsuario,
                    $idVenta,
                    $cantidad,
                    $observacionMovimiento
                ]);

            }

            /**
             * Marcar venta como anulada.
             */
            $stmtAnularVenta = $pdo->prepare(
                "UPDATE ventas
                SET estado = 'anulada',
                    observaciones = LEFT(
                        CONCAT(
                            COALESCE(observaciones, ''),
                            CASE
                                WHEN COALESCE(observaciones, '') = '' THEN ''
                                ELSE CHAR(10)
                            END,
                            'Anulación autorizada: ',
                            ?
                        ),
                        255
                    )
                WHERE id_venta = ?
                AND estado = 'completada'"
            );

            $stmtAnularVenta->execute([
                $motivoAnulacion,
                $idVenta
            ]);

            if ($stmtAnularVenta->rowCount() !== 1) {

                throw new RuntimeException(
                    'No fue posible actualizar el estado de la venta.'
                );

            }

            /**
             * Confirmar operación completa.
             */
            $pdo->commit();

            establecerMensaje(
                'success',
                'La venta '
                . $numeroComprobante
                . ' fue anulada correctamente y sus productos fueron devueltos al inventario.'
            );

            header('Location: ' . urlSistema('caja/ventas.php'));
            exit;

        } catch (DomainException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errores[] = $e->getMessage();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Error al anular venta '
                . $idVenta
                . ': '
                . $e->getMessage()
            );

            $errores[] = 'Ocurrió un error al anular la venta. No se realizaron cambios.';
        }
    }
}

/**
 * Consultar información actualizada de la venta.
 */
$stmtVenta = $pdo->prepare(
    "SELECT
        v.id_venta,
        v.fecha_venta,
        v.subtotal,
        v.descuento,
        v.total,
        v.metodo_pago,
        v.estado,
        v.observaciones,
        u.nombre AS nombre_cajero
     FROM ventas v
     INNER JOIN usuarios u
        ON u.id_usuario = v.id_usuario
     WHERE v.id_venta = ?
     LIMIT 1"
);

$stmtVenta->execute([$idVenta]);

$venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {

    establecerMensaje('warning', 'No se encontró la venta solicitada.');

    header('Location: ' . urlSistema('caja/ventas.php'));
    exit;
}

/**
 * Consultar productos de la venta.
 */
$stmtDetallesVista = $pdo->prepare(
    "SELECT
        dv.id_producto,
        dv.cantidad,
        dv.precio_unitario,
        dv.subtotal,
        p.nombre AS nombre_producto
     FROM detalle_venta dv
     LEFT JOIN productos p
        ON p.id_producto = dv.id_producto
     WHERE dv.id_venta = ?
     ORDER BY dv.id_detalle ASC"
);

$stmtDetallesVista->execute([$idVenta]);

$detallesVista = $stmtDetallesVista->fetchAll(PDO::FETCH_ASSOC);

/**
 * Preparar datos.
 */
$numeroComprobante = generarNumeroComprobante($idVenta);

$metodosPago = [
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta',
    'sinpe' => 'SINPE Móvil',
    'otro' => 'Otro'
];

$metodoPago = $metodosPago[$venta['metodo_pago']]
    ?? ucfirst($venta['metodo_pago']);

$totalUnidades = 0;

foreach ($detallesVista as $detalle) {
    $totalUnidades += (int) $detalle['cantidad'];
}

$motivoGuardado = '';

$observacionesVenta = (string) ($venta['observaciones'] ?? '');

if (
    preg_match(
        '/(?:^|\R)Anulación autorizada:\s*([^\r\n]+)/u',
        $observacionesVenta,
        $coincidencias
    )
) {
    $motivoGuardado = trim($coincidencias[1]);
}

require_once __DIR__ . '/../includes/header.php';

?>

<div class="container-fluid py-4">

    <div class="row">

        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

            <!-- ENCABEZADO -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                <div>

                    <h1 class="h3 mb-1">
                        Anular venta
                    </h1>

                    <p class="text-muted mb-0">
                        Revisa la operación antes de realizar la anulación.
                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('caja/ventas.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver al historial
                </a>

            </div>

            <!-- ERRORES -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger">

                    <strong>No se pudo anular la venta:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach ($errores as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <div class="row g-4">

                <!-- INFORMACIÓN -->
                <div class="col-lg-7">

                    <div class="card shadow-sm border-0">

                        <div class="card-header bg-white py-3">

                            <h2 class="h5 mb-0">
                                <i class="bi bi-receipt"></i>
                                <?= escapar($numeroComprobante) ?>
                            </h2>

                        </div>

                        <div class="card-body">

                            <div class="row g-3 mb-4">

                                <div class="col-sm-6">

                                    <div class="text-muted small">
                                        Fecha de venta
                                    </div>

                                    <strong>
                                        <?= escapar(formatearFechaHora($venta['fecha_venta'])) ?>
                                    </strong>

                                </div>

                                <div class="col-sm-6">

                                    <div class="text-muted small">
                                        Cajero
                                    </div>

                                    <strong>
                                        <?= escapar($venta['nombre_cajero']) ?>
                                    </strong>

                                </div>

                                <div class="col-sm-6">

                                    <div class="text-muted small">
                                        Método de pago
                                    </div>

                                    <strong>
                                        <?= escapar($metodoPago) ?>
                                    </strong>

                                </div>

                                <div class="col-sm-6">

                                    <div class="text-muted small">
                                        Estado actual
                                    </div>

                                    <?php if ($venta['estado'] === 'completada'): ?>

                                        <span class="badge bg-success">
                                            Completada
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            Anulada
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <h3 class="h6 mb-3">
                                Productos de la venta
                            </h3>

                            <div class="table-responsive">

                                <table class="table table-sm align-middle">

                                    <thead class="table-light">

                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-center">Cantidad</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach ($detallesVista as $detalle): ?>

                                            <tr>

                                                <td>
                                                    <?= escapar(
                                                        $detalle['nombre_producto']
                                                        ?: 'Producto no disponible'
                                                    ) ?>
                                                </td>

                                                <td class="text-center">
                                                    <?= number_format((int) $detalle['cantidad']) ?>
                                                </td>

                                                <td class="text-end text-nowrap">
                                                    <?= escapar(
                                                        formatearMoneda((float) $detalle['subtotal'])
                                                    ) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                            <hr>

                            <div class="d-flex justify-content-between mb-2">

                                <span>Unidades:</span>

                                <strong>
                                    <?= number_format($totalUnidades) ?>
                                </strong>

                            </div>

                            <div class="d-flex justify-content-between mb-2">

                                <span>Subtotal:</span>

                                <strong>
                                    <?= escapar(formatearMoneda((float) $venta['subtotal'])) ?>
                                </strong>

                            </div>

                            <div class="d-flex justify-content-between mb-2">

                                <span>Descuento:</span>

                                <strong>
                                    <?= escapar(formatearMoneda((float) $venta['descuento'])) ?>
                                </strong>

                            </div>

                            <div class="d-flex justify-content-between align-items-center border-top pt-3">

                                <strong>TOTAL DE LA VENTA</strong>

                                <strong class="h4 mb-0">
                                    <?= escapar(formatearMoneda((float) $venta['total'])) ?>
                                </strong>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- FORMULARIO -->
                <div class="col-lg-5">

                    <div class="card shadow-sm border-0">

                        <div class="card-header bg-white py-3">

                            <h2 class="h5 mb-0">
                                <i class="bi bi-x-circle text-danger"></i>
                                Anulación
                            </h2>

                        </div>

                        <div class="card-body">

                            <?php if ($venta['estado'] === 'completada'): ?>

                                <div class="alert alert-warning">

                                    <strong>
                                        Atención: esta operación no se puede deshacer automáticamente.
                                    </strong>

                                    <p class="mb-0 mt-2 small">
                                        Las unidades de la venta se devolverán al inventario
                                        y quedará registrado el movimiento correspondiente.
                                    </p>

                                </div>

                                <form
                                    method="POST"
                                    action="<?= escapar(
                                        urlSistema('admin/anular_venta.php?id=' . $idVenta)
                                    ) ?>"
                                    id="formAnularVenta"
                                >

                                    <?= campoTokenCSRF() ?>

                                    <input
                                        type="hidden"
                                        name="id_venta"
                                        value="<?= (int) $idVenta ?>"
                                    >

                                    <div class="mb-4">

                                        <label for="motivo_anulacion" class="form-label">
                                            Motivo de anulación
                                            <span class="text-danger">*</span>
                                        </label>

                                        <textarea
                                            name="motivo_anulacion"
                                            id="motivo_anulacion"
                                            class="form-control"
                                            rows="4"
                                            maxlength="255"
                                            minlength="5"
                                            placeholder="Explica por qué se está anulando esta venta..."
                                            required
                                        ><?= escapar($motivoAnulacion) ?></textarea>

                                        <div class="form-text">
                                            El motivo quedará guardado en el registro de la venta.
                                        </div>

                                    </div>

                                    <button
                                        type="submit"
                                        class="btn btn-danger w-100"
                                    >
                                        <i class="bi bi-x-circle"></i>
                                        Confirmar anulación
                                    </button>

                                </form>

                            <?php else: ?>

                                <div class="alert alert-secondary">

                                    <i class="bi bi-info-circle"></i>

                                    Esta venta ya fue anulada. No se permite repetir la operación.

                                </div>

                                <div class="mb-2">
                                    <strong>Motivo registrado:</strong>
                                </div>

                                <p class="text-muted">
                                    <?= escapar($motivoGuardado ?: 'No se registró un motivo.') ?>
                                </p>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>

<?php

require_once __DIR__ . '/../includes/alerts.php';

require_once __DIR__ . '/../includes/footer.php';

?>
