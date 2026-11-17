<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador');

$tituloPagina = 'Resolver solicitud de anulación';
$paginaActual = 'caja';

$rutaSolicitudes = __DIR__ . '/../storage/solicitudes_anulacion.json';

$errores = [];
$solicitud = null;
$venta = null;
$detalles = [];

$respuestaAdministrador = '';

$usuarioActual = obtenerUsuarioActual();

$idAdministrador = (int) ($usuarioActual['id_usuario'] ?? 0);
$nombreAdministrador = $usuarioActual['nombre'] ?? '';

/**
 * Leer y validar contenido JSON.
 */
function leerJSONResolverAnulacion(string $contenido): array
{
    if (trim($contenido) === '') {
        return [];
    }

    $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($datos)) {
        throw new RuntimeException('El archivo de solicitudes no contiene un arreglo válido.');
    }

    return $datos;
}

/**
 * Escribir JSON manteniendo el bloqueo del archivo.
 */
function escribirJSONResolverAnulacion($archivo, array $solicitudes): void
{
    $contenido = json_encode(
        $solicitudes,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    );

    if (!rewind($archivo)) {
        throw new RuntimeException('No se pudo posicionar el archivo de solicitudes.');
    }

    if (!ftruncate($archivo, 0)) {
        throw new RuntimeException('No se pudo limpiar el archivo de solicitudes.');
    }

    $longitud = strlen($contenido);
    $escritos = 0;

    while ($escritos < $longitud) {

        $resultado = fwrite(
            $archivo,
            substr($contenido, $escritos)
        );

        if ($resultado === false || $resultado === 0) {
            throw new RuntimeException('No se pudo guardar la información completa de la solicitud.');
        }

        $escritos += $resultado;
    }

    if (!fflush($archivo)) {
        throw new RuntimeException('No se pudieron guardar los cambios del archivo.');
    }

    if (function_exists('fsync')) {
        fsync($archivo);
    }
}

/**
 * Restaurar el contenido original del JSON
 * si falla la transacción de MySQL.
 */
function restaurarJSONResolverAnulacion($archivo, string $contenidoOriginal): void
{
    if (!rewind($archivo) || !ftruncate($archivo, 0)) {
        throw new RuntimeException('No se pudo restaurar el archivo de solicitudes.');
    }

    $longitud = strlen($contenidoOriginal);
    $escritos = 0;

    while ($escritos < $longitud) {

        $resultado = fwrite(
            $archivo,
            substr($contenidoOriginal, $escritos)
        );

        if ($resultado === false || $resultado === 0) {
            throw new RuntimeException('No se pudo restaurar completamente el archivo de solicitudes.');
        }

        $escritos += $resultado;
    }

    if (!fflush($archivo)) {
        throw new RuntimeException('No se pudieron guardar los datos restaurados.');
    }

    if (function_exists('fsync')) {
        fsync($archivo);
    }
}

/**
 * Recortar texto respetando caracteres UTF-8.
 */
function recortarTextoAnulacion(string $texto, int $longitud): string
{
    if ($longitud <= 0) {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($texto, 0, $longitud, 'UTF-8')
        : substr($texto, 0, $longitud);
}

function longitudTextoAnulacion(string $texto): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);
}

/**
 * Identificar la solicitud.
 */
$idSolicitudEntrada = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['id_solicitud'] ?? '')
    : ($_GET['id'] ?? '');

$idSolicitud = is_string($idSolicitudEntrada)
    ? trim($idSolicitudEntrada)
    : '';

if (!preg_match('/^[a-f0-9]{24}$/i', $idSolicitud)) {

    establecerMensaje('warning', 'La solicitud seleccionada no es válida.');

    header('Location: ' . urlSistema('admin/solicitudes_anulacion.php'));
    exit;
}

/**
 * Procesar la decisión del administrador.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $accion = is_string($_POST['accion'] ?? null)
        ? $_POST['accion']
        : '';

    $respuestaAdministrador = is_string($_POST['respuesta_administrador'] ?? null)
        ? trim($_POST['respuesta_administrador'])
        : '';

    $longitudRespuesta = longitudTextoAnulacion($respuestaAdministrador);

    if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
        $errores[] = 'La acción seleccionada no es válida.';
    }

    if ($longitudRespuesta > 255) {
        $errores[] = 'La respuesta no puede superar los 255 caracteres.';
    }

    if (
        $accion === 'rechazar'
        && $longitudRespuesta < 5
    ) {
        $errores[] = 'Para rechazar la solicitud debes explicar el motivo, utilizando al menos 5 caracteres.';
    }

    if ($idAdministrador <= 0) {
        $errores[] = 'No se pudo identificar al administrador.';
    }

    if (empty($errores)) {

        $archivo = null;
        $bloqueoAdquirido = false;

        $contenidoOriginal = '';
        $jsonActualizado = false;
        $operacionConfirmada = false;

        try {

            /**
             * Abrir archivo en modo lectura/escritura.
             */
            $archivo = fopen($rutaSolicitudes, 'c+');

            if ($archivo === false) {
                throw new RuntimeException('No se pudo abrir el archivo de solicitudes.');
            }

            /**
             * Bloqueo exclusivo para impedir
             * que dos administradores resuelvan
             * simultáneamente la misma solicitud.
             */
            if (!flock($archivo, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el archivo de solicitudes.');
            }

            $bloqueoAdquirido = true;

            rewind($archivo);

            $contenidoOriginal = stream_get_contents($archivo);

            if ($contenidoOriginal === false) {
                throw new RuntimeException('No se pudo leer el archivo de solicitudes.');
            }

            $solicitudes = leerJSONResolverAnulacion(
                $contenidoOriginal
            );

            /**
             * Encontrar solicitud.
             */
            $indiceSolicitud = null;

            foreach ($solicitudes as $indice => $registro) {

                if (
                    is_array($registro)
                    && isset($registro['id_solicitud'])
                    && hash_equals(
                        (string) $registro['id_solicitud'],
                        $idSolicitud
                    )
                ) {

                    $indiceSolicitud = $indice;
                    break;

                }

            }

            if ($indiceSolicitud === null) {
                throw new DomainException('No se encontró la solicitud seleccionada.');
            }

            $solicitudActual = $solicitudes[$indiceSolicitud];

            if (($solicitudActual['estado'] ?? '') !== 'pendiente') {

                throw new DomainException(
                    'Esta solicitud ya fue resuelta anteriormente.'
                );

            }

            $idVenta = (int) ($solicitudActual['id_venta'] ?? 0);

            if ($idVenta <= 0) {
                throw new RuntimeException('La solicitud no contiene un identificador de venta válido.');
            }

            /**
             * Si se rechaza, solamente se actualiza
             * el archivo de solicitudes.
             */
            if ($accion === 'rechazar') {

                $solicitudes[$indiceSolicitud]['estado'] = 'rechazada';

                $solicitudes[$indiceSolicitud]['fecha_resolucion'] =
                    date('Y-m-d H:i:s');

                $solicitudes[$indiceSolicitud]['id_usuario_resolutor'] =
                    $idAdministrador;

                $solicitudes[$indiceSolicitud]['nombre_resolutor'] =
                    $nombreAdministrador;

                $solicitudes[$indiceSolicitud]['respuesta_administrador'] =
                    $respuestaAdministrador;

                $jsonActualizado = true;

                escribirJSONResolverAnulacion(
                    $archivo,
                    $solicitudes
                );

                $operacionConfirmada = true;

            } else {

                /**
                 * ACEPTAR SOLICITUD.
                 *
                 * La devolución de inventario,
                 * la anulación de venta y el cambio
                 * de estado de la solicitud se coordinan.
                 */
                $pdo->beginTransaction();

                /**
                 * Bloquear venta original.
                 */
                $stmtVenta = $pdo->prepare(
                    "SELECT
                        id_venta,
                        estado,
                        observaciones
                     FROM ventas
                     WHERE id_venta = ?
                     FOR UPDATE"
                );

                $stmtVenta->execute([$idVenta]);

                $ventaOriginal = $stmtVenta->fetch(PDO::FETCH_ASSOC);

                if (!$ventaOriginal) {
                    throw new DomainException('La venta relacionada con esta solicitud ya no existe.');
                }

                if ($ventaOriginal['estado'] !== 'completada') {
                    throw new DomainException('La venta ya fue anulada o no se encuentra completada.');
                }

                /**
                 * Obtener cantidades agrupadas por producto.
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

                $detallesVenta = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

                if (empty($detallesVenta)) {
                    throw new DomainException('La venta no contiene productos que puedan devolverse.');
                }

                /**
                 * Preparar consultas de inventario.
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
                 * Devolver las unidades de cada producto.
                 */
                foreach ($detallesVenta as $detalle) {

                    $idProducto = (int) $detalle['id_producto'];

                    $cantidad = (int) $detalle['cantidad_total'];

                    if ($idProducto <= 0 || $cantidad <= 0) {
                        throw new RuntimeException('Se encontró un detalle de venta inválido.');
                    }

                    /**
                     * Bloquear producto incluso si está inactivo.
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

                    if ($stockActual > 2147483647 - $cantidad) {
                        throw new DomainException(
                            'No se pueden devolver las unidades de '
                            . $producto['nombre']
                            . ' porque se superaría el límite de existencias.'
                        );
                    }

                    /**
                     * Recuperar existencias.
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
                     * Registrar movimiento de entrada.
                     */
                    $observacionMovimiento =
                        'Devolución por anulación '
                        . $numeroComprobante
                        . '. Motivo: '
                        . $solicitudActual['motivo'];

                    $observacionMovimiento = recortarTextoAnulacion(
                        $observacionMovimiento,
                        255
                    );

                    $stmtMovimiento->execute([
                        $idProducto,
                        $idAdministrador,
                        $idVenta,
                        $cantidad,
                        $observacionMovimiento
                    ]);

                }

                /**
                 * Actualizar observaciones de venta.
                 *
                 * La tabla no tiene una columna motivo_anulacion.
                 * Por eso conservamos las observaciones existentes
                 * y agregamos la información disponible en el campo.
                 */
                $observacionesOriginales = trim(
                    (string) ($ventaOriginal['observaciones'] ?? '')
                );

                $prefijoMotivo = 'Anulación autorizada: ';

                $separador = $observacionesOriginales !== ''
                    ? "\n"
                    : '';

                $espacioDisponible = max(
                    0,
                    255
                    - longitudTextoAnulacion($observacionesOriginales)
                    - longitudTextoAnulacion($separador)
                    - longitudTextoAnulacion($prefijoMotivo)
                );

                $motivoParaVenta = recortarTextoAnulacion(
                    (string) $solicitudActual['motivo'],
                    $espacioDisponible
                );

                $observacionesNuevas =
                    $observacionesOriginales
                    . $separador
                    . $prefijoMotivo
                    . $motivoParaVenta;

                $observacionesNuevas = recortarTextoAnulacion(
                    $observacionesNuevas,
                    255
                );

                /**
                 * Marcar venta como anulada.
                 */
                $stmtAnularVenta = $pdo->prepare(
                    "UPDATE ventas
                     SET estado = 'anulada',
                         observaciones = ?
                     WHERE id_venta = ?
                       AND estado = 'completada'"
                );

                $stmtAnularVenta->execute([
                    $observacionesNuevas,
                    $idVenta
                ]);

                if ($stmtAnularVenta->rowCount() !== 1) {
                    throw new RuntimeException('No se pudo actualizar el estado de la venta.');
                }

                /**
                 * Actualizar estado de solicitud.
                 */
                $solicitudes[$indiceSolicitud]['estado'] = 'aprobada';

                $solicitudes[$indiceSolicitud]['fecha_resolucion'] =
                    date('Y-m-d H:i:s');

                $solicitudes[$indiceSolicitud]['id_usuario_resolutor'] =
                    $idAdministrador;

                $solicitudes[$indiceSolicitud]['nombre_resolutor'] =
                    $nombreAdministrador;

                $solicitudes[$indiceSolicitud]['respuesta_administrador'] =
                    $respuestaAdministrador !== ''
                        ? $respuestaAdministrador
                        : 'Solicitud aprobada. Venta anulada y existencias devueltas al inventario.';

                /**
                 * Guardar archivo antes de confirmar MySQL.
                 *
                 * Si ocurre un error al confirmar la transacción,
                 * se restaurará el contenido original del JSON.
                 */
                $jsonActualizado = true;

                escribirJSONResolverAnulacion(
                    $archivo,
                    $solicitudes
                );

                /**
                 * Confirmar transacción.
                 */
                $pdo->commit();

                $operacionConfirmada = true;

            }

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
                'Error al resolver solicitud de anulación '
                . $idSolicitud
                . ': '
                . $e->getMessage()
            );

            $errores[] = 'Ocurrió un error al resolver la solicitud. No se completaron los cambios.';

        } finally {

            /**
             * Si el archivo se modificó pero MySQL no confirmó
             * la operación, restaurar el JSON original.
             */
            if (
                $jsonActualizado
                && !$operacionConfirmada
                && is_resource($archivo)
            ) {

                try {

                    restaurarJSONResolverAnulacion(
                        $archivo,
                        $contenidoOriginal
                    );

                } catch (Throwable $errorRestauracion) {

                    error_log(
                        'Error crítico al restaurar solicitudes de anulación: '
                        . $errorRestauracion->getMessage()
                    );

                }

            }

            if (is_resource($archivo)) {

                if ($bloqueoAdquirido) {
                    flock($archivo, LOCK_UN);
                }

                fclose($archivo);

            }

        }

        /**
         * Redireccionar después de resolver.
         */
        if ($operacionConfirmada) {

            if ($accion === 'aprobar') {

                establecerMensaje(
                    'success',
                    'Solicitud aprobada. La venta fue anulada y las existencias fueron devueltas al inventario.'
                );

            } else {

                establecerMensaje(
                    'success',
                    'La solicitud fue rechazada. La venta permanece completada.'
                );

            }

            header(
                'Location: ' . urlSistema(
                    'admin/solicitudes_anulacion.php?estado='
                    . ($accion === 'aprobar' ? 'aprobada' : 'rechazada')
                )
            );

            exit;
        }

    }

}

/**
 * Volver a cargar la solicitud para mostrar
 * los datos actualizados después de un POST.
 */
try {

    $archivoLectura = fopen($rutaSolicitudes, 'rb');

    if ($archivoLectura === false) {
        throw new RuntimeException('No se pudo abrir el archivo de solicitudes.');
    }

    try {

        if (!flock($archivoLectura, LOCK_SH)) {
            throw new RuntimeException('No se pudo bloquear el archivo de solicitudes.');
        }

        $contenido = stream_get_contents($archivoLectura);

        $solicitudesLectura = leerJSONResolverAnulacion(
            $contenido === false ? '' : $contenido
        );

        foreach ($solicitudesLectura as $registro) {

            if (
                is_array($registro)
                && isset($registro['id_solicitud'])
                && hash_equals(
                    (string) $registro['id_solicitud'],
                    $idSolicitud
                )
            ) {

                $solicitud = $registro;
                break;

            }

        }

    } finally {

        flock($archivoLectura, LOCK_UN);
        fclose($archivoLectura);

    }

} catch (Throwable $e) {

    error_log(
        'Error al recargar solicitud de anulación: '
        . $e->getMessage()
    );

    $errores[] = 'No fue posible consultar nuevamente la solicitud.';

}

if (!$solicitud) {

    http_response_code(404);

    establecerMensaje('warning', 'No se encontró la solicitud seleccionada.');

    header('Location: ' . urlSistema('admin/solicitudes_anulacion.php'));
    exit;

}

/**
 * Consultar venta y detalles para mostrarlos.
 */
$idVenta = (int) ($solicitud['id_venta'] ?? 0);

$stmtVentaVista = $pdo->prepare(
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

$stmtVentaVista->execute([$idVenta]);

$venta = $stmtVentaVista->fetch(PDO::FETCH_ASSOC);

if (!$venta) {

    $errores[] = 'La venta relacionada con esta solicitud no existe.';

} else {

    $stmtDetallesVista = $pdo->prepare(
        "SELECT
            dv.id_detalle,
            dv.cantidad,
            dv.precio_unitario,
            dv.subtotal,
            p.nombre AS nombre_producto,
            p.codigo_barras
         FROM detalle_venta dv
         LEFT JOIN productos p
            ON p.id_producto = dv.id_producto
         WHERE dv.id_venta = ?
         ORDER BY dv.id_detalle ASC"
    );

    $stmtDetallesVista->execute([$idVenta]);

    $detalles = $stmtDetallesVista->fetchAll(PDO::FETCH_ASSOC);

}

/**
 * Datos de presentación.
 */
$numeroComprobante = generarNumeroComprobante($idVenta);

$estadosPresentacion = [
    'pendiente' => 'Pendiente',
    'aprobada' => 'Aprobada',
    'rechazada' => 'Rechazada'
];

$estadoSolicitud = $solicitud['estado'] ?? 'pendiente';

$metodosPago = [
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta',
    'sinpe' => 'SINPE Móvil',
    'otro' => 'Otro'
];

$metodoPago = $venta
    ? (
        $metodosPago[$venta['metodo_pago']]
        ?? ucfirst($venta['metodo_pago'])
    )
    : 'No disponible';

$totalUnidades = 0;

foreach ($detalles as $detalle) {
    $totalUnidades += (int) $detalle['cantidad'];
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
                        Revisar solicitud de anulación
                    </h1>

                    <p class="text-muted mb-0">
                        Analiza la venta y decide si autorizas o rechazas la solicitud.
                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('admin/solicitudes_anulacion.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver a solicitudes
                </a>

            </div>

            <!-- ERRORES -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger">

                    <strong>No se pudo completar la operación:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach ($errores as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <!-- DATOS DE SOLICITUD -->
            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <h2 class="h5 mb-0">
                            Solicitud <?= escapar($numeroComprobante) ?>
                        </h2>

                        <?php if ($estadoSolicitud === 'pendiente'): ?>

                            <span class="badge bg-warning text-dark">
                                Pendiente
                            </span>

                        <?php elseif ($estadoSolicitud === 'aprobada'): ?>

                            <span class="badge bg-success">
                                Aprobada
                            </span>

                        <?php else: ?>

                            <span class="badge bg-secondary">
                                Rechazada
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="card-body">

                    <div class="row g-3">

                        <div class="col-md-4">

                            <div class="text-muted small">
                                Cajero solicitante
                            </div>

                            <strong>
                                <?= escapar(
                                    $solicitud['nombre_solicitante'] ?? 'Desconocido'
                                ) ?>
                            </strong>

                        </div>

                        <div class="col-md-4">

                            <div class="text-muted small">
                                Fecha de solicitud
                            </div>

                            <strong>
                                <?= escapar(
                                    formatearFechaHora(
                                        $solicitud['fecha_solicitud'] ?? ''
                                    )
                                ) ?>
                            </strong>

                        </div>

                        <div class="col-md-4">

                            <div class="text-muted small">
                                Número de comprobante
                            </div>

                            <strong>
                                <?= escapar($numeroComprobante) ?>
                            </strong>

                        </div>

                        <div class="col-12">

                            <div class="text-muted small">
                                Motivo indicado por el cajero
                            </div>

                            <div class="border rounded p-3 bg-light">

                                <?= nl2br(
                                    escapar($solicitud['motivo'] ?? '')
                                ) ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- INFORMACIÓN DE VENTA -->
            <?php if ($venta): ?>

                <div class="card shadow-sm border-0 mb-4">

                    <div class="card-header bg-white py-3">

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                            <h2 class="h5 mb-0">
                                <i class="bi bi-receipt"></i>
                                Detalle de la venta
                            </h2>

                            <a
                                href="<?= escapar(
                                    urlSistema('caja/comprobante.php?id=' . $idVenta)
                                ) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-outline-success btn-sm"
                            >
                                <i class="bi bi-printer"></i>
                                Ver comprobante completo
                            </a>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="row g-3 mb-4">

                            <div class="col-md-3">

                                <div class="text-muted small">
                                    Fecha de venta
                                </div>

                                <strong>
                                    <?= escapar(formatearFechaHora($venta['fecha_venta'])) ?>
                                </strong>

                            </div>

                            <div class="col-md-3">

                                <div class="text-muted small">
                                    Cajero
                                </div>

                                <strong>
                                    <?= escapar($venta['nombre_cajero']) ?>
                                </strong>

                            </div>

                            <div class="col-md-3">

                                <div class="text-muted small">
                                    Método de pago
                                </div>

                                <strong>
                                    <?= escapar($metodoPago) ?>
                                </strong>

                            </div>

                            <div class="col-md-3">

                                <div class="text-muted small">
                                    Estado de la venta
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

                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead class="table-light">

                                    <tr>
                                        <th>Producto</th>
                                        <th class="text-center">Cantidad</th>
                                        <th class="text-end">Precio unitario</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>

                                </thead>

                                <tbody>

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
                                                    formatearMoneda(
                                                        (float) $detalle['precio_unitario']
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-end text-nowrap">
                                                <?= escapar(
                                                    formatearMoneda(
                                                        (float) $detalle['subtotal']
                                                    )
                                                ) ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <hr>

                        <div class="row justify-content-end">

                            <div class="col-md-5">

                                <div class="d-flex justify-content-between mb-2">

                                    <span>Unidades:</span>

                                    <strong>
                                        <?= number_format($totalUnidades) ?>
                                    </strong>

                                </div>

                                <div class="d-flex justify-content-between mb-2">

                                    <span>Subtotal:</span>

                                    <strong>
                                        <?= escapar(
                                            formatearMoneda((float) $venta['subtotal'])
                                        ) ?>
                                    </strong>

                                </div>

                                <div class="d-flex justify-content-between mb-2">

                                    <span>Descuento:</span>

                                    <strong>
                                        <?= escapar(
                                            formatearMoneda((float) $venta['descuento'])
                                        ) ?>
                                    </strong>

                                </div>

                                <div class="d-flex justify-content-between border-top pt-3">

                                    <strong>TOTAL</strong>

                                    <strong class="h4 mb-0 text-success">
                                        <?= escapar(
                                            formatearMoneda((float) $venta['total'])
                                        ) ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

            <!-- RESOLUCIÓN -->
            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-clipboard-check"></i>
                        Resolución administrativa
                    </h2>

                </div>

                <div class="card-body">

                    <?php if ($estadoSolicitud === 'pendiente'): ?>

                        <?php if ($venta && $venta['estado'] === 'completada'): ?>

                            <form method="POST" action="">

                                <?= campoTokenCSRF() ?>

                                <input
                                    type="hidden"
                                    name="id_solicitud"
                                    value="<?= escapar($idSolicitud) ?>"
                                >

                                <div class="mb-4">

                                    <label
                                        for="respuesta_administrador"
                                        class="form-label"
                                    >
                                        Respuesta del administrador
                                        <span class="text-muted">(Opcional al aprobar)</span>
                                    </label>

                                    <textarea
                                        name="respuesta_administrador"
                                        id="respuesta_administrador"
                                        class="form-control"
                                        rows="3"
                                        maxlength="255"
                                        placeholder="Escribe una observación sobre la decisión..."
                                    ><?= escapar($respuestaAdministrador) ?></textarea>

                                    <div class="form-text">
                                        Si rechazas la solicitud, debes explicar el motivo.
                                    </div>

                                </div>

                                <div class="alert alert-warning">

                                    <i class="bi bi-exclamation-triangle"></i>

                                    <strong>Antes de aprobar:</strong>

                                    al aceptar, la venta quedará anulada y las unidades
                                    correspondientes volverán a las existencias del inventario.
                                    Esta operación no realiza una devolución automática de dinero.

                                </div>

                                <div class="d-flex justify-content-end flex-wrap gap-2">

                                    <button
                                        type="submit"
                                        name="accion"
                                        value="rechazar"
                                        class="btn btn-outline-danger"
                                        onclick="return confirm('¿Estás seguro de que deseas rechazar esta solicitud?');"
                                    >
                                        <i class="bi bi-x-circle"></i>
                                        Rechazar solicitud
                                    </button>

                                    <button
                                        type="submit"
                                        name="accion"
                                        value="aprobar"
                                        class="btn btn-success"
                                        onclick="return confirm('¿Confirmas la anulación de esta venta y la devolución de sus productos al inventario?');"
                                    >
                                        <i class="bi bi-check-circle"></i>
                                        Aprobar y anular venta
                                    </button>

                                </div>

                            </form>

                        <?php else: ?>

                            <div class="alert alert-danger mb-0">

                                La venta ya no se encuentra en estado completado.
                                No es posible aprobar esta solicitud.

                            </div>

                        <?php endif; ?>

                    <?php else: ?>

                        <div class="alert alert-secondary">

                            <strong>
                                Esta solicitud ya fue resuelta.
                            </strong>

                            <p class="mb-0 mt-2">

                                Estado:
                                <?= escapar(
                                    $estadosPresentacion[$estadoSolicitud]
                                    ?? 'Desconocido'
                                ) ?>

                            </p>

                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">

                                <div class="text-muted small">
                                    Resuelta por
                                </div>

                                <strong>
                                    <?= escapar(
                                        $solicitud['nombre_resolutor']
                                        ?? 'Administrador'
                                    ) ?>
                                </strong>

                            </div>

                            <div class="col-md-6">

                                <div class="text-muted small">
                                    Fecha de resolución
                                </div>

                                <strong>
                                    <?= escapar(
                                        $solicitud['fecha_resolucion']
                                        ?? 'No disponible'
                                    ) ?>
                                </strong>

                            </div>

                            <div class="col-12">

                                <div class="text-muted small">
                                    Respuesta del administrador
                                </div>

                                <p class="mb-0">
                                    <?= escapar(
                                        $solicitud['respuesta_administrador']
                                        ?? 'Sin respuesta adicional.'
                                    ) ?>
                                </p>

                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </main>

    </div>

</div>

<?php

require_once __DIR__ . '/../includes/alerts.php';

require_once __DIR__ . '/../includes/footer.php';

?>
