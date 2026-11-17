<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requerirRol('administrador', 'cajero');

/**
 * Obtener usuario actual.
 */
$usuarioActual = obtenerUsuarioActual();

$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);
$rolUsuario = $usuarioActual['rol'] ?? '';

/**
 * Validar identificador de venta.
 */
$idVenta = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($idVenta === false || $idVenta <= 0) {

    establecerMensaje('warning', 'El comprobante solicitado no es válido.');

    header('Location: ' . urlSistema('caja/index.php'));
    exit;

}

/**
 * Consultar venta.
 *
 * El administrador puede consultar cualquier venta.
 * El cajero solamente puede consultar sus propias ventas.
 */
$sqlVenta = "
    SELECT
        v.id_venta,
        v.id_usuario,
        v.fecha_venta,
        v.subtotal,
        v.descuento,
        v.total,
        v.metodo_pago,
        v.monto_recibido,
        v.cambio,
        v.estado,
        v.observaciones,
        u.nombre AS nombre_cajero
    FROM ventas v
    INNER JOIN usuarios u
        ON u.id_usuario = v.id_usuario
    WHERE v.id_venta = :id_venta
";

$parametrosVenta = [
    ':id_venta' => $idVenta
];

if ($rolUsuario !== 'administrador') {

    $sqlVenta .= " AND v.id_usuario = :id_usuario";

    $parametrosVenta[':id_usuario'] = $idUsuario;

}

$sqlVenta .= " LIMIT 1";

$stmtVenta = $pdo->prepare($sqlVenta);

$stmtVenta->execute($parametrosVenta);

$venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {

    establecerMensaje(
        'warning',
        'No se encontró la venta solicitada o no tienes permiso para consultarla.'
    );

    header('Location: ' . urlSistema('caja/index.php'));
    exit;

}

/**
 * Consultar productos incluidos en la venta.
 */
$stmtDetalle = $pdo->prepare(
    "SELECT
        dv.id_detalle,
        dv.id_producto,
        dv.cantidad,
        dv.precio_unitario,
        dv.subtotal,
        p.nombre AS nombre_producto,
        p.codigo_barras
    FROM detalle_venta dv
    LEFT JOIN productos p
        ON p.id_producto = dv.id_producto
    WHERE dv.id_venta = :id_venta
    ORDER BY dv.id_detalle ASC"
);

$stmtDetalle->execute([
    ':id_venta' => $idVenta
]);

$detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

/**
 * Preparar datos del comprobante.
 */
$numeroComprobante = generarNumeroComprobante($idVenta);

$fechaVenta = formatearFechaHora($venta['fecha_venta']);

$subtotal = (float) $venta['subtotal'];
$descuento = (float) $venta['descuento'];
$total = (float) $venta['total'];
$montoRecibido = (float) ($venta['monto_recibido'] ?? 0);

// Calcular el vuelto únicamente para pagos en efectivo.
$vuelto = 0;

if (($venta['metodo_pago'] ?? '') === 'efectivo') {
    $vuelto = max(
        0,
        round($montoRecibido - $total, 2)
    );
}
$cambio = (float) $venta['cambio'];

$observaciones = trim((string) ($venta['observaciones'] ?? ''));

$clienteNombre = 'Consumidor final';

if (
    preg_match(
        '/(?:^|\R)Cliente:\s*([^\r\n]+)/u',
        $observaciones,
        $coincidencias
    )
) {
    $clienteNombre = trim($coincidencias[1]);
}

$metodosPago = [
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta',
    'sinpe' => 'SINPE Móvil',
    'otro' => 'Otro'
];

$metodoPago = $metodosPago[$venta['metodo_pago']]
    ?? ucfirst($venta['metodo_pago']);

$estadoVenta = $venta['estado'] === 'completada'
    ? 'Completada'
    : 'Anulada';

$tituloPagina = 'Comprobante ' . $numeroComprobante;
$paginaActual = 'caja';

$totalUnidades = 0;

foreach ($detalles as $detalle) {
    $totalUnidades += (int) $detalle['cantidad'];
}

require_once __DIR__ . '/../includes/header.php';

?>



<style>

/* ======================================
   CONFIGURACIÓN DEL RECIBO
   80 mm: rollo térmico de 80 mm.
   52 mm: área imprimible aproximada
   para rollo de 58 mm.
====================================== */

:root {
    --ancho-recibo: 72mm;
    color-scheme: light;
}

/* ======================================
   CONTENEDOR
====================================== */

.comprobante-contenedor {
    max-width: 850px;
    margin: 0 auto;
}

.comprobante {
    box-sizing: border-box;
    width: var(--ancho-recibo);
    max-width: 100%;
    margin: 0 auto;
    padding: 3mm 2.5mm;

    background: #fff !important;
    color: #000 !important;

    border: 1px solid #000;
    border-radius: 0;

    font-family: Arial, sans-serif;
    font-size: 10px;
}

/* ======================================
   BLANCO Y NEGRO EN TODA LA VISTA PREVIA
====================================== */

.comprobante,
.comprobante * {
    color: #000 !important;
    background-color: #fff !important;
    background-image: none !important;
    box-shadow: none !important;
    text-shadow: none !important;
    filter: none !important;
}

/* Evitar estilos de colores de Bootstrap */

.comprobante .text-success,
.comprobante .text-danger,
.comprobante .text-primary,
.comprobante .text-secondary,
.comprobante .text-muted,
.comprobante .text-warning,
.comprobante .text-info {
    color: #000 !important;
}

.comprobante .badge,
.comprobante .badge.bg-success,
.comprobante .badge.bg-danger,
.comprobante .badge.bg-warning,
.comprobante .badge.bg-secondary {
    color: #000 !important;
    background: #fff !important;
    border: 1px solid #000 !important;
    border-radius: 0;
    font-weight: bold;
}

/* ======================================
   ENCABEZADO
====================================== */

.comprobante-encabezado {
    text-align: center;
    border-bottom: 1px dashed #000;
    padding-bottom: 8px;
    margin-bottom: 8px;
}

.comprobante-encabezado h2 {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 3px;
}

.comprobante-numero {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.comprobante-encabezado .text-muted {
    font-size: 9px;
}

/* ======================================
   DATOS DE LA VENTA
====================================== */

.comprobante-datos {
    font-size: 9px;
    margin-bottom: 10px;
}

.comprobante-datos .row {
    --bs-gutter-x: 4px;
    --bs-gutter-y: 5px;
}

/* ======================================
   TABLA DE PRODUCTOS
====================================== */

.comprobante-tabla {
    width: 100%;
    table-layout: fixed;
    font-size: 9px;
    margin-bottom: 8px;

    --bs-table-color: #000;
    --bs-table-bg: #fff;
    --bs-table-border-color: #000;
    --bs-table-striped-bg: #fff;
    --bs-table-hover-bg: #fff;
    --bs-table-active-bg: #fff;
}

.comprobante-tabla thead,
.comprobante-tabla tbody,
.comprobante-tabla tr,
.comprobante-tabla th,
.comprobante-tabla td {
    color: #000 !important;
    background: #fff !important;
}

.comprobante-tabla th {
    font-size: 8px;
    border-bottom: 1px solid #000;
    padding: 4px 2px;
}

.comprobante-tabla td {
    border-bottom: 1px dotted #000;
    padding: 4px 2px;
    overflow-wrap: anywhere;
    vertical-align: top;
}

.comprobante-tabla th:nth-child(1),
.comprobante-tabla td:nth-child(1) {
    width: 43%;
}

.comprobante-tabla th:nth-child(2),
.comprobante-tabla td:nth-child(2) {
    width: 12%;
}

.comprobante-tabla th:nth-child(3),
.comprobante-tabla td:nth-child(3) {
    width: 22%;
}

.comprobante-tabla th:nth-child(4),
.comprobante-tabla td:nth-child(4) {
    width: 23%;
}

.comprobante-tabla .small {
    font-size: 8px;
}

/* ======================================
   TOTALES
====================================== */

.comprobante-totales {
    width: 100%;
    max-width: none;
    margin-left: auto;
    padding-top: 5px;
    font-size: 9px;
}

.comprobante-total-final {
    color: #000 !important;
    background: #fff !important;

    border-top: 1px solid #000;
    padding-top: 7px;
    margin-top: 5px;

    font-size: 15px;
    font-weight: 700;
}

/* ======================================
   MÉTODO DE PAGO
====================================== */

.comprobante .border-top {
    border-top: 1px dashed #000 !important;
    padding-top: 7px;
    font-size: 9px;
}

/* ======================================
   PIE DEL RECIBO
====================================== */

.comprobante-pie {
    color: #000 !important;
    background: #fff !important;

    border-top: 1px dashed #000;
    margin-top: 12px;
    padding-top: 9px;

    text-align: center;
    font-size: 8px;
}

/* ======================================
   IMPRESIÓN TÉRMICA
====================================== */

@media print {

    @page {
        margin: 0;
        size: 80mm auto;
    }

    html,
    body {
        width: var(--ancho-recibo) !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #000 !important;
    }

    body * {
        visibility: hidden !important;
    }

    #area-comprobante,
    #area-comprobante * {
        visibility: visible !important;
    }

    #area-comprobante {
        position: absolute;
        top: 0;
        left: 0;

        width: var(--ancho-recibo) !important;
        max-width: var(--ancho-recibo) !important;

        margin: 0 !important;
        padding: 0 !important;
    }

    .comprobante-contenedor {
        width: var(--ancho-recibo) !important;
        max-width: var(--ancho-recibo) !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .comprobante {
        width: var(--ancho-recibo) !important;
        max-width: var(--ancho-recibo) !important;

        padding: 2mm !important;
        margin: 0 !important;

        border: none !important;
        border-radius: 0 !important;

        box-shadow: none !important;
        font-size: 9px !important;
    }

    .comprobante-no-imprimir {
        display: none !important;
    }

    .comprobante-tabla {
        font-size: 8px !important;
    }

    .comprobante-tabla th,
    .comprobante-tabla td {
        padding: 3px 1px !important;
    }

    .comprobante-total-final {
        font-size: 14px !important;
    }

    .comprobante-pie {
        font-size: 8px !important;
    }

    /* Forzar blanco y negro también al imprimir */

    .comprobante,
    .comprobante * {
        color: #000 !important;
        background: #fff !important;
        background-image: none !important;
        box-shadow: none !important;
        text-shadow: none !important;
        filter: none !important;

        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .comprobante-tabla,
    .comprobante-tabla tr,
    .comprobante-tabla th,
    .comprobante-tabla td {
        color: #000 !important;
        background: #fff !important;
    }

    .comprobante .badge {
        color: #000 !important;
        background: #fff !important;
        border: 1px solid #000 !important;
    }

}

</style>


<div class="container-fluid py-4">

    <div class="comprobante-contenedor">

        <!-- ACCIONES -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 comprobante-no-imprimir">

            <div>

                <h1 class="h4 mb-1">
                    Comprobante de venta
                </h1>

                <p class="text-muted mb-0">
                    Consulta e impresión de la venta registrada.
                </p>

            </div>

            <div class="d-flex gap-2">

                <a
                    href="<?= escapar(urlSistema('caja/index.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left"></i>
                    Nueva venta
                </a>

                <button
                    type="button"
                    class="btn btn-success"
                    onclick="window.print()"
                    <?= $venta['estado'] !== 'completada' ? 'disabled' : '' ?>
                >
                    <i class="bi bi-printer"></i>
                    Imprimir
                </button>

            </div>

        </div>

        <!-- COMPROBANTE -->
        <div id="area-comprobante">

            <div class="comprobante">

                <!-- ENCABEZADO -->
                <div class="comprobante-encabezado">

                    <h2>
                        COMPROBANTE DE VENTA
                    </h2>

                    <div class="text-muted">
                        Sistema de facturación sencilla
                    </div>

                    <div class="comprobante-numero mt-3">
                        <?= escapar($numeroComprobante) ?>
                    </div>

                    <div class="mt-1">

                        <?php if ($venta['estado'] === 'completada'): ?>

                            <span class="badge bg-success">
                                <?= escapar($estadoVenta) ?>
                            </span>

                        <?php else: ?>

                            <span class="badge bg-danger">
                                <?= escapar($estadoVenta) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <!-- DATOS GENERALES -->
                <div class="comprobante-datos">

                    <div class="row g-2">

                        <div class="col-6">

                            <strong>Fecha:</strong>

                            <div>
                                <?= escapar($fechaVenta) ?>
                            </div>

                        </div>

                        <div class="col-6">

                            <strong>Atendido por:</strong>

                            <div>
                                <?= escapar($venta['nombre_cajero']) ?>
                            </div>

                        </div>

                        <div class="col-12 mt-3">

                            <strong>Cliente:</strong>

                            <div>
                                <?= escapar($clienteNombre) ?>
                            </div>

                        </div>

                    </div>

                </div>

                <!-- DETALLE DE PRODUCTOS -->
                <div class="table-responsive">

                    <table class="table table-sm comprobante-tabla">

                        <thead>

                            <tr>

                                <th>Producto</th>

                                <th class="text-center">
                                    Cant.
                                </th>

                                <th class="text-end">
                                    Precio
                                </th>

                                <th class="text-end">
                                    Total
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if (empty($detalles)): ?>

                                <tr>
                                    <td colspan="4" class="text-center">
                                        No hay detalles registrados.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($detalles as $detalle): ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= escapar(
                                                    $detalle['nombre_producto']
                                                    ?: 'Producto no disponible'
                                                ) ?>
                                            </strong>

                                            <?php if (!empty($detalle['codigo_barras'])): ?>

                                                <div class="small text-muted">
                                                    <?= escapar($detalle['codigo_barras']) ?>
                                                </div>

                                            <?php endif; ?>

                                        </td>

                                        <td class="text-center">
                                            <?= number_format((int) $detalle['cantidad']) ?>
                                        </td>

                                        <td class="text-end text-nowrap">
                                            <?= escapar(
                                                formatearMoneda((float) $detalle['precio_unitario'])
                                            ) ?>
                                        </td>

                                        <td class="text-end text-nowrap">
                                            <?= escapar(
                                                formatearMoneda((float) $detalle['subtotal'])
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <!-- TOTALES -->
                <div class="comprobante-totales">

                    <div class="d-flex justify-content-between mb-2">

                        <span>
                            Unidades:
                        </span>

                        <strong>
                            <?= number_format($totalUnidades) ?>
                        </strong>

                    </div>

                    <div class="d-flex justify-content-between mb-2">

                        <span>
                            Subtotal:
                        </span>

                        <strong>
                            <?= escapar(formatearMoneda($subtotal)) ?>
                        </strong>

                    </div>

                    <div class="d-flex justify-content-between mb-2">

                        <span>
                            Descuento:
                        </span>

                        <strong>
                            <?= escapar(formatearMoneda($descuento)) ?>
                        </strong>

                    </div>

                    <div class="d-flex justify-content-between comprobante-total-final">

                        <span>
                            TOTAL:
                        </span>

                        <span class="text-success">
                            <?= escapar(formatearMoneda($total)) ?>
                        </span>

                    </div>

                </div>

                <!-- METODO DE PAGO -->

                <div class="mt-4 pt-3 border-top">

                    <div class="d-flex justify-content-between">

                        <strong>Método de pago:</strong>

                        <span>
                            <?= escapar($metodoPago) ?>
                        </span>

                    </div>

                    <?php if (($venta['metodo_pago'] ?? '') === 'efectivo'): ?>

                        <div class="d-flex justify-content-between mt-2">

                            <span>
                                Dinero recibido:
                            </span>

                            <strong>
                                <?= escapar(formatearMoneda($montoRecibido)) ?>
                            </strong>

                        </div>

                        <div class="d-flex justify-content-between mt-2">

                            <strong>
                                Vuelto:
                            </strong>

                            <strong>
                                <?= escapar(formatearMoneda($vuelto)) ?>
                            </strong>

                        </div>

                    <?php endif; ?>

                </div>

                <!-- PIE -->
                <div class="comprobante-pie">

                    <p class="mb-2">
                        ¡Gracias por su compra!
                    </p>

                    <p class="mb-0">
                        Comprobante interno de venta.
                        No constituye factura electrónica.
                    </p>

                    <p class="mt-2 mb-0">
                        <?= escapar($numeroComprobante) ?>
                    </p>

                </div>

            </div>

        </div>

    </div>

</div>

<?php

require_once __DIR__ . '/../includes/alerts.php';

require_once __DIR__ . '/../includes/footer.php';

?>
