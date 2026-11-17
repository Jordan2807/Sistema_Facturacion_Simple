<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('cajero');

$tituloPagina = 'Solicitar anulación';
$paginaActual = 'caja';

$errores = [];
$motivoAnulacion = '';

$usuarioActual = obtenerUsuarioActual();

$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);
$nombreUsuario = $usuarioActual['nombre'] ?? '';

/**
 * Ubicación del archivo privado de solicitudes.
 */
$rutaSolicitudes = __DIR__ . '/../storage/solicitudes_anulacion.json';

/**
 * Leer solicitudes existentes.
 */
function leerSolicitudesAnulacionCajero(string $ruta): array
{
    $archivo = fopen($ruta, 'c+');

    if ($archivo === false) {
        throw new RuntimeException('No se pudo abrir el archivo de solicitudes.');
    }

    try {

        if (!flock($archivo, LOCK_SH)) {
            throw new RuntimeException('No se pudo bloquear el archivo de solicitudes.');
        }

        rewind($archivo);

        $contenido = stream_get_contents($archivo);

        $solicitudes = $contenido === false || trim($contenido) === ''
            ? []
            : json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($solicitudes)) {
            throw new RuntimeException('El archivo de solicitudes contiene información inválida.');
        }

        return $solicitudes;

    } finally {

        flock($archivo, LOCK_UN);
        fclose($archivo);

    }
}

/**
 * Guardar una solicitud con bloqueo exclusivo.
 */
function guardarSolicitudAnulacionCajero(
    string $ruta,
    array $nuevaSolicitud
): void {

    $archivo = fopen($ruta, 'c+');

    if ($archivo === false) {
        throw new RuntimeException('No se pudo abrir el archivo de solicitudes.');
    }

    try {

        if (!flock($archivo, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear el archivo de solicitudes.');
        }

        rewind($archivo);

        $contenido = stream_get_contents($archivo);

        $solicitudes = $contenido === false || trim($contenido) === ''
            ? []
            : json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($solicitudes)) {
            throw new RuntimeException('El archivo de solicitudes contiene información inválida.');
        }

        /**
         * Evitar solicitudes pendientes duplicadas.
         */
        foreach ($solicitudes as $solicitud) {

            if (
                (int) ($solicitud['id_venta'] ?? 0)
                    === (int) $nuevaSolicitud['id_venta']
                && ($solicitud['estado'] ?? '') === 'pendiente'
            ) {
                throw new DomainException(
                    'Ya existe una solicitud pendiente para esta venta.'
                );
            }

        }

        $solicitudes[] = $nuevaSolicitud;

        $nuevoContenido = json_encode(
            $solicitudes,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );

        rewind($archivo);

        if (!ftruncate($archivo, 0)) {
            throw new RuntimeException('No se pudo actualizar el archivo de solicitudes.');
        }

        $escritura = fwrite($archivo, $nuevoContenido);

        if ($escritura === false || $escritura !== strlen($nuevoContenido)) {
            throw new RuntimeException('No se pudo guardar la solicitud completa.');
        }

        if (!fflush($archivo)) {
            throw new RuntimeException('No se pudieron guardar los cambios del archivo.');
        }

    } finally {

        flock($archivo, LOCK_UN);
        fclose($archivo);

    }
}

/**
 * Validar identificador.
 */
$idVenta = filter_var(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['id_venta'] ?? null)
        : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT
);

if ($idVenta === false || $idVenta <= 0) {

    establecerMensaje('warning', 'La venta seleccionada no es válida.');

    header('Location: ' . urlSistema('caja/ventas.php'));
    exit;
}

/**
 * Consultar venta.
 *
 * El cajero solamente puede solicitar la anulación
 * de sus propias ventas.
 */
$stmtVenta = $pdo->prepare(
    "SELECT
        v.id_venta,
        v.id_usuario,
        v.fecha_venta,
        v.subtotal,
        v.descuento,
        v.total,
        v.metodo_pago,
        v.estado,
        v.observaciones
     FROM ventas v
     WHERE v.id_venta = ?
       AND v.id_usuario = ?
     LIMIT 1"
);

$stmtVenta->execute([
    $idVenta,
    $idUsuario
]);

$venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {

    establecerMensaje(
        'warning',
        'La venta no existe o no tienes permiso para solicitar su anulación.'
    );

    header('Location: ' . urlSistema('caja/ventas.php'));
    exit;
}

/**
 * Consultar los productos de la venta.
 */
$stmtDetalles = $pdo->prepare(
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

$stmtDetalles->execute([$idVenta]);

$detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

/**
 * Leer el estado de las solicitudes.
 */
$solicitudPendiente = false;
$solicitudExistente = null;

try {

    $solicitudes = leerSolicitudesAnulacionCajero($rutaSolicitudes);

    foreach ($solicitudes as $solicitud) {

        if (
            (int) ($solicitud['id_venta'] ?? 0) === $idVenta
            && ($solicitud['estado'] ?? '') === 'pendiente'
        ) {

            $solicitudPendiente = true;
            $solicitudExistente = $solicitud;

            break;
        }

    }

} catch (Throwable $e) {

    error_log(
        'Error al consultar solicitudes de anulación: '
        . $e->getMessage()
    );

    $errores[] = 'No fue posible consultar las solicitudes de anulación.';
}

/**
 * Procesar solicitud del cajero.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errores)) {

    verificarTokenCSRF();

    $motivoAnulacion = is_string($_POST['motivo_anulacion'] ?? null)
        ? trim($_POST['motivo_anulacion'])
        : '';

    $longitudMotivo = function_exists('mb_strlen')
        ? mb_strlen($motivoAnulacion)
        : strlen($motivoAnulacion);

    if ($venta['estado'] !== 'completada') {

        $errores[] = 'Esta venta no está completada y no puede solicitarse su anulación.';

    }

    if ($solicitudPendiente) {

        $errores[] = 'Ya existe una solicitud pendiente para esta venta.';

    }

    if ($longitudMotivo < 5 || $longitudMotivo > 255) {

        $errores[] = 'El motivo debe tener entre 5 y 255 caracteres.';

    }

    if (empty($errores)) {

        try {

            $nuevaSolicitud = [
                'id_solicitud' => bin2hex(random_bytes(12)),
                'id_venta' => $idVenta,
                'numero_comprobante' => generarNumeroComprobante($idVenta),
                'id_usuario_solicitante' => $idUsuario,
                'nombre_solicitante' => $nombreUsuario,
                'motivo' => $motivoAnulacion,
                'fecha_solicitud' => date('Y-m-d H:i:s'),
                'estado' => 'pendiente',
                'fecha_resolucion' => null,
                'id_usuario_resolutor' => null,
                'nombre_resolutor' => null,
                'respuesta_administrador' => null
            ];

            guardarSolicitudAnulacionCajero(
                $rutaSolicitudes,
                $nuevaSolicitud
            );

            establecerMensaje(
                'success',
                'Tu solicitud de anulación fue enviada al administrador correctamente.'
            );

            header('Location: ' . urlSistema('caja/ventas.php'));
            exit;

        } catch (DomainException $e) {

            $errores[] = $e->getMessage();

        } catch (Throwable $e) {

            error_log(
                'Error al guardar solicitud de anulación: '
                . $e->getMessage()
            );

            $errores[] = 'No fue posible enviar la solicitud. Inténtalo nuevamente.';

        }

    }

}

/**
 * Datos de presentación.
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

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                <div>

                    <h1 class="h3 mb-1">
                        Solicitar anulación
                    </h1>

                    <p class="text-muted mb-0">
                        Envía al administrador una solicitud para revisar esta venta.
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

            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger">

                    <strong>No se pudo procesar la solicitud:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach ($errores as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <div class="row g-4">

                <!-- DATOS DE LA VENTA -->
                <div class="col-lg-7">

                    <div class="card shadow-sm border-0">

                        <div class="card-header bg-white py-3">

                            <h2 class="h5 mb-0">
                                Comprobante <?= escapar($numeroComprobante) ?>
                            </h2>

                        </div>

                        <div class="card-body">

                            <div class="row g-3 mb-3">

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
                                        Cliente
                                    </div>

                                    <strong>
                                        <?= escapar($clienteNombre) ?>
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
                                        Estado
                                    </div>

                                    <span class="badge bg-success">
                                        Completada
                                    </span>

                                </div>

                            </div>

                            <h3 class="h6 mt-4 mb-3">
                                Productos de la venta
                            </h3>

                            <div class="table-responsive">

                                <table class="table table-sm align-middle">

                                    <thead class="table-light">

                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-center">Cant.</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach ($detalles as $detalle): ?>

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
                                <strong><?= number_format($totalUnidades) ?></strong>
                            </div>

                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal:</span>
                                <strong><?= escapar(formatearMoneda((float) $venta['subtotal'])) ?></strong>
                            </div>

                            <div class="d-flex justify-content-between mb-2">
                                <span>Descuento:</span>
                                <strong><?= escapar(formatearMoneda((float) $venta['descuento'])) ?></strong>
                            </div>

                            <div class="d-flex justify-content-between border-top pt-3">

                                <strong>TOTAL DE LA VENTA</strong>

                                <strong class="h4 mb-0">
                                    <?= escapar(formatearMoneda((float) $venta['total'])) ?>
                                </strong>

                            </div>

                            <div class="mt-3">

                                <a
                                    href="<?= escapar(
                                        urlSistema('caja/comprobante.php?id=' . $idVenta)
                                    ) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn btn-outline-success btn-sm"
                                >
                                    <i class="bi bi-receipt"></i>
                                    Ver comprobante completo
                                </a>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- SOLICITUD -->
                <div class="col-lg-5">

                    <div class="card shadow-sm border-0">

                        <div class="card-header bg-white py-3">

                            <h2 class="h5 mb-0">
                                <i class="bi bi-send"></i>
                                Solicitud al administrador
                            </h2>

                        </div>

                        <div class="card-body">

                            <?php if ($venta['estado'] !== 'completada'): ?>

                                <div class="alert alert-secondary">
                                    Esta venta ya no está completada. No se puede solicitar su anulación.
                                </div>

                            <?php elseif ($solicitudPendiente): ?>

                                <div class="alert alert-info">

                                    <strong>
                                        <i class="bi bi-clock-history"></i>
                                        Solicitud enviada
                                    </strong>

                                    <p class="mb-2 mt-2">
                                        Ya existe una solicitud pendiente para esta venta.
                                    </p>

                                    <small class="text-muted">
                                        Fecha:
                                        <?= escapar($solicitudExistente['fecha_solicitud'] ?? '') ?>
                                    </small>

                                </div>

                                <p class="mb-1">
                                    <strong>Motivo enviado:</strong>
                                </p>

                                <p class="text-muted">
                                    <?= escapar($solicitudExistente['motivo'] ?? '') ?>
                                </p>

                                <p class="small text-muted mb-0">
                                    El administrador debe revisar y resolver la solicitud.
                                </p>

                            <?php else: ?>

                                <div class="alert alert-warning">

                                    <strong>Antes de continuar</strong>

                                    <p class="small mb-0 mt-2">
                                        La venta no se anulará inmediatamente.
                                        Tu solicitud será enviada al administrador,
                                        quien decidirá si la acepta o la rechaza.
                                    </p>

                                </div>

                                <form method="POST" action="">

                                    <?= campoTokenCSRF() ?>

                                    <input
                                        type="hidden"
                                        name="id_venta"
                                        value="<?= $idVenta ?>"
                                    >

                                    <div class="mb-4">

                                        <label for="motivo_anulacion" class="form-label">
                                            Motivo de la solicitud
                                            <span class="text-danger">*</span>
                                        </label>

                                        <textarea
                                            name="motivo_anulacion"
                                            id="motivo_anulacion"
                                            class="form-control"
                                            rows="5"
                                            maxlength="255"
                                            minlength="5"
                                            placeholder="Explica por qué necesitas anular esta venta..."
                                            required
                                        ><?= escapar($motivoAnulacion) ?></textarea>

                                        <div class="form-text">
                                            El administrador podrá leer este motivo antes de tomar una decisión.
                                        </div>

                                    </div>

                                    <button
                                        type="submit"
                                        class="btn btn-warning w-100"
                                    >
                                        <i class="bi bi-send"></i>
                                        Enviar solicitud
                                    </button>

                                </form>

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
