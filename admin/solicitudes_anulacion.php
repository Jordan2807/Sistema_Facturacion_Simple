<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requerirRol('administrador');

$tituloPagina = 'Solicitudes de anulación';
$paginaActual = 'caja';

$rutaSolicitudes = __DIR__ . '/../storage/solicitudes_anulacion.json';

$errores = [];
$solicitudes = [];

/**
 * Leer el archivo JSON de forma segura.
 */
try {

    if (!is_file($rutaSolicitudes)) {
        throw new RuntimeException(
            'No se encontró el archivo solicitudes_anulacion.json.'
        );
    }

    $archivo = fopen($rutaSolicitudes, 'rb');

    if ($archivo === false) {
        throw new RuntimeException(
            'No se pudo abrir el archivo de solicitudes.'
        );
    }

    try {

        if (!flock($archivo, LOCK_SH)) {
            throw new RuntimeException(
                'No se pudo bloquear el archivo de solicitudes.'
            );
        }

        $contenido = stream_get_contents($archivo);

        $solicitudes = $contenido === false || trim($contenido) === ''
            ? []
            : json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($solicitudes)) {
            throw new RuntimeException(
                'El contenido del archivo de solicitudes no es válido.'
            );
        }

    } finally {

        flock($archivo, LOCK_UN);
        fclose($archivo);

    }

} catch (Throwable $e) {

    error_log(
        'Error al consultar solicitudes de anulación: '
        . $e->getMessage()
    );

    $errores[] = 'No fue posible cargar las solicitudes de anulación. Revisa el archivo de almacenamiento.';

    $solicitudes = [];
}

/**
 * Filtros.
 */
$buscar = is_string($_GET['buscar'] ?? null)
    ? trim($_GET['buscar'])
    : '';

$estadoFiltro = is_string($_GET['estado'] ?? null)
    ? trim($_GET['estado'])
    : 'pendiente';

$estadosPermitidos = [
    'todos',
    'pendiente',
    'aprobada',
    'rechazada'
];

if (!in_array($estadoFiltro, $estadosPermitidos, true)) {
    $estadoFiltro = 'pendiente';
}

if (strlen($buscar) > 100) {
    $buscar = substr($buscar, 0, 100);
}

/**
 * Función para buscar texto sin distinguir mayúsculas.
 */
$normalizarTexto = static function (string $texto): string {

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($texto, 'UTF-8');
    }

    return strtolower($texto);

};

/**
 * Contabilizar solicitudes.
 */
$totalPendientes = 0;
$totalAprobadas = 0;
$totalRechazadas = 0;

foreach ($solicitudes as $solicitud) {

    $estadoSolicitud = $solicitud['estado'] ?? '';

    if ($estadoSolicitud === 'pendiente') {
        $totalPendientes++;
    } elseif ($estadoSolicitud === 'aprobada') {
        $totalAprobadas++;
    } elseif ($estadoSolicitud === 'rechazada') {
        $totalRechazadas++;
    }

}

/**
 * Ordenar por fecha, comenzando por la más reciente.
 */
usort(
    $solicitudes,
    static function ($a, $b) {

        return strcmp(
            (string) ($b['fecha_solicitud'] ?? ''),
            (string) ($a['fecha_solicitud'] ?? '')
        );

    }
);

/**
 * Aplicar filtros.
 */
$solicitudesFiltradas = [];

foreach ($solicitudes as $solicitud) {

    if (!is_array($solicitud)) {
        continue;
    }

    $estadoSolicitud = $solicitud['estado'] ?? 'pendiente';

    if (
        $estadoFiltro !== 'todos'
        && $estadoSolicitud !== $estadoFiltro
    ) {
        continue;
    }

    if ($buscar !== '') {

        $textoBusqueda = $normalizarTexto($buscar);

        $datosBusqueda = [
            (string) ($solicitud['numero_comprobante'] ?? ''),
            (string) ($solicitud['nombre_solicitante'] ?? ''),
            (string) ($solicitud['motivo'] ?? ''),
            (string) ($solicitud['id_venta'] ?? ''),
            (string) ($solicitud['nombre_resolutor'] ?? '')
        ];

        $coincidencia = false;

        foreach ($datosBusqueda as $dato) {

            if (
                strpos(
                    $normalizarTexto($dato),
                    $textoBusqueda
                ) !== false
            ) {
                $coincidencia = true;
                break;
            }

        }

        if (!$coincidencia) {
            continue;
        }

    }

    $solicitudesFiltradas[] = $solicitud;

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
                        Solicitudes de anulación
                    </h1>

                    <p class="text-muted mb-0">
                        Revisa las solicitudes enviadas por los cajeros.
                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('caja/ventas.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left"></i>
                    Historial de ventas
                </a>

            </div>

            <!-- ERRORES -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger">

                    <strong>Problemas al cargar las solicitudes:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach ($errores as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <!-- RESUMEN -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="card shadow-sm border-0 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Pendientes de revisión
                            </div>

                            <div class="h3 mb-0">
                                <?= number_format($totalPendientes) ?>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card shadow-sm border-0 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Aprobadas
                            </div>

                            <div class="h3 mb-0">
                                <?= number_format($totalAprobadas) ?>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card shadow-sm border-0 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Rechazadas
                            </div>

                            <div class="h3 mb-0">
                                <?= number_format($totalRechazadas) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- BUSCADOR -->
            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-search"></i>
                        Buscar solicitudes
                    </h2>

                </div>

                <div class="card-body">

                    <form method="GET" action="">

                        <div class="row g-3">

                            <div class="col-md-8">

                                <label for="buscar" class="form-label">
                                    Comprobante, cajero o motivo
                                </label>

                                <input
                                    type="text"
                                    id="buscar"
                                    name="buscar"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= escapar($buscar) ?>"
                                    placeholder="Ejemplo: COMP-000025"
                                >

                            </div>

                            <div class="col-md-2">

                                <label for="estado" class="form-label">
                                    Estado
                                </label>

                                <select
                                    name="estado"
                                    id="estado"
                                    class="form-select"
                                >

                                    <option
                                        value="pendiente"
                                        <?= $estadoFiltro === 'pendiente' ? 'selected' : '' ?>
                                    >
                                        Pendientes
                                    </option>

                                    <option
                                        value="aprobada"
                                        <?= $estadoFiltro === 'aprobada' ? 'selected' : '' ?>
                                    >
                                        Aprobadas
                                    </option>

                                    <option
                                        value="rechazada"
                                        <?= $estadoFiltro === 'rechazada' ? 'selected' : '' ?>
                                    >
                                        Rechazadas
                                    </option>

                                    <option
                                        value="todos"
                                        <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>
                                    >
                                        Todas
                                    </option>

                                </select>

                            </div>

                            <div class="col-md-2 d-flex align-items-end gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-success flex-grow-1"
                                >
                                    <i class="bi bi-search"></i>
                                    Buscar
                                </button>

                                <a
                                    href="<?= escapar(urlSistema('admin/solicitudes_anulacion.php')) ?>"
                                    class="btn btn-outline-secondary"
                                    title="Limpiar filtros"
                                >
                                    <i class="bi bi-x-circle"></i>
                                </a>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- LISTADO -->
            <div class="card shadow-sm border-0">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-clipboard-check"></i>
                        Solicitudes recibidas
                    </h2>

                </div>

                <div class="card-body p-0">

                    <?php if (empty($solicitudesFiltradas)): ?>

                        <div class="text-center py-5">

                            <i class="bi bi-inbox display-4 text-muted"></i>

                            <h5 class="mt-3">
                                No hay solicitudes para mostrar
                            </h5>

                            <p class="text-muted mb-0">
                                Las solicitudes que envíen los cajeros aparecerán en esta sección.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th>Comprobante</th>

                                        <th>Solicitante</th>

                                        <th>Fecha de solicitud</th>

                                        <th>Motivo</th>

                                        <th class="text-center">Estado</th>

                                        <th class="text-end">Acciones</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($solicitudesFiltradas as $solicitud): ?>

                                        <?php

                                        $idSolicitud = (string) (
                                            $solicitud['id_solicitud'] ?? ''
                                        );

                                        $idVenta = (int) (
                                            $solicitud['id_venta'] ?? 0
                                        );

                                        $numeroComprobante = $solicitud['numero_comprobante']
                                            ?? (
                                                $idVenta > 0
                                                ? generarNumeroComprobante($idVenta)
                                                : 'Sin comprobante'
                                            );

                                        $estadoSolicitud = $solicitud['estado']
                                            ?? 'pendiente';

                                        $fechaSolicitud = $solicitud['fecha_solicitud']
                                            ?? '';

                                        ?>

                                        <tr>

                                            <td>

                                                <strong>
                                                    <?= escapar((string) $numeroComprobante) ?>
                                                </strong>

                                                <div class="small text-muted">
                                                    Venta #<?= $idVenta ?>
                                                </div>

                                            </td>

                                            <td>

                                                <strong>
                                                    <?= escapar(
                                                        $solicitud['nombre_solicitante']
                                                        ?? 'Usuario desconocido'
                                                    ) ?>
                                                </strong>

                                                <div class="small text-muted">
                                                    Usuario ID:
                                                    <?= (int) (
                                                        $solicitud['id_usuario_solicitante']
                                                        ?? 0
                                                    ) ?>
                                                </div>

                                            </td>

                                            <td class="text-nowrap">

                                                <?= $fechaSolicitud !== ''
                                                    ? escapar(formatearFechaHora($fechaSolicitud))
                                                    : '—' ?>

                                            </td>

                                            <td style="min-width: 200px; max-width: 300px;">

                                                <?= escapar(
                                                    $solicitud['motivo'] ?? ''
                                                ) ?>

                                                <?php if (
                                                    !empty($solicitud['respuesta_administrador'])
                                                ): ?>

                                                    <div class="small text-muted mt-2">

                                                        <strong>Respuesta:</strong>

                                                        <?= escapar(
                                                            $solicitud['respuesta_administrador']
                                                        ) ?>

                                                    </div>

                                                <?php endif; ?>

                                            </td>

                                            <td class="text-center">

                                                <?php if ($estadoSolicitud === 'pendiente'): ?>

                                                    <span class="badge bg-warning text-dark">
                                                        Pendiente
                                                    </span>

                                                <?php elseif ($estadoSolicitud === 'aprobada'): ?>

                                                    <span class="badge bg-success">
                                                        Aprobada
                                                    </span>

                                                <?php elseif ($estadoSolicitud === 'rechazada'): ?>

                                                    <span class="badge bg-secondary">
                                                        Rechazada
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-secondary">
                                                        Desconocida
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td class="text-end text-nowrap">

                                                <?php if ($idVenta > 0): ?>

                                                    <a
                                                        href="<?= escapar(
                                                            urlSistema(
                                                                'caja/comprobante.php?id=' . $idVenta
                                                            )
                                                        ) ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="btn btn-sm btn-outline-success"
                                                        title="Ver comprobante original"
                                                    >
                                                        <i class="bi bi-receipt"></i>
                                                        Factura
                                                    </a>

                                                <?php endif; ?>

                                                <?php if (
                                                    $estadoSolicitud === 'pendiente'
                                                    && preg_match('/^[a-f0-9]{24}$/i', $idSolicitud)
                                                ): ?>

                                                    <a
                                                        href="<?= escapar(
                                                            urlSistema(
                                                                'admin/resolver_solicitud_anulacion.php?id='
                                                                . rawurlencode($idSolicitud)
                                                            )
                                                        ) ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                        title="Revisar solicitud"
                                                    >
                                                        <i class="bi bi-eye"></i>
                                                        Revisar
                                                    </a>

                                                <?php else: ?>

                                                    <span class="text-muted small">
                                                        Resuelta
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

                <div class="card-footer bg-white">

                    <small class="text-muted">
                        Mostrando <?= count($solicitudesFiltradas) ?>
                        de <?= count($solicitudes) ?> solicitudes registradas.
                    </small>

                </div>

            </div>

        </main>

    </div>

</div>

<?php

require_once __DIR__ . '/../includes/alerts.php';

require_once __DIR__ . '/../includes/footer.php';

?>
