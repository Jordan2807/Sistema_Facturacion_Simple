<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requerirRol('cajero');

$tituloPagina = 'Mis solicitudes de anulación';
$paginaActual = 'mis_solicitudes';

$usuarioActual = obtenerUsuarioActual();
$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);

$rutaSolicitudes = __DIR__ . '/../storage/solicitudes_anulacion.json';

$solicitudesUsuario = [];
$errorLectura = false;

/*
|--------------------------------------------------------------------------
| Leer solicitudes almacenadas
|--------------------------------------------------------------------------
*/

if (is_file($rutaSolicitudes)) {

    $archivo = fopen($rutaSolicitudes, 'rb');

    if ($archivo !== false) {

        if (flock($archivo, LOCK_SH)) {

            $contenido = stream_get_contents($archivo);

            flock($archivo, LOCK_UN);

            try {

                $datos = json_decode(
                    $contenido ?: '[]',
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (is_array($datos)) {
                    $solicitudesUsuario = array_values(
                        array_filter(
                            $datos,
                            function ($solicitud) use ($idUsuario) {

                                return is_array($solicitud)
                                    && (int) ($solicitud['id_usuario_solicitante'] ?? 0) === $idUsuario;
                            }
                        )
                    );
                }

            } catch (JsonException $e) {

                error_log('Error leyendo solicitudes de anulación: ' . $e->getMessage());
                $errorLectura = true;

            }

        } else {

            $errorLectura = true;

        }

        fclose($archivo);

    } else {

        $errorLectura = true;

    }

}

/*
|--------------------------------------------------------------------------
| Filtros
|--------------------------------------------------------------------------
*/

$buscar = trim((string) ($_GET['buscar'] ?? ''));
$estadoFiltro = (string) ($_GET['estado'] ?? 'todos');

$estadosPermitidos = ['todos', 'pendiente', 'aprobada', 'rechazada'];

if (!in_array($estadoFiltro, $estadosPermitidos, true)) {
    $estadoFiltro = 'todos';
}

$normalizarTexto = function ($texto) {

    $texto = (string) $texto;

    return function_exists('mb_strtolower')
        ? mb_strtolower($texto, 'UTF-8')
        : strtolower($texto);

};

$buscarNormalizado = $normalizarTexto($buscar);

$solicitudesFiltradas = array_filter(
    $solicitudesUsuario,
    function ($solicitud) use (
        $buscarNormalizado,
        $estadoFiltro,
        $normalizarTexto
    ) {

        $estado = (string) ($solicitud['estado'] ?? '');

        if ($estadoFiltro !== 'todos' && $estado !== $estadoFiltro) {
            return false;
        }

        if ($buscarNormalizado !== '') {

            $texto = implode(' ', [
                $solicitud['numero_comprobante'] ?? '',
                $solicitud['motivo'] ?? '',
                $solicitud['id_venta'] ?? ''
            ]);

            if (
                strpos(
                    $normalizarTexto($texto),
                    $buscarNormalizado
                ) === false
            ) {
                return false;
            }

        }

        return true;

    }
);

/*
|--------------------------------------------------------------------------
| Ordenar de más reciente a más antigua
|--------------------------------------------------------------------------
*/

usort(
    $solicitudesFiltradas,
    function ($a, $b) {

        return strtotime((string) ($b['fecha_solicitud'] ?? ''))
            <=> strtotime((string) ($a['fecha_solicitud'] ?? ''));

    }
);

$totalSolicitudes = count($solicitudesUsuario);

$totalPendientes = count(array_filter(
    $solicitudesUsuario,
    fn($s) => ($s['estado'] ?? '') === 'pendiente'
));

$totalAprobadas = count(array_filter(
    $solicitudesUsuario,
    fn($s) => ($s['estado'] ?? '') === 'aprobada'
));

$totalRechazadas = count(array_filter(
    $solicitudesUsuario,
    fn($s) => ($s['estado'] ?? '') === 'rechazada'
));

require_once __DIR__ . '/../includes/header.php';

?>

<div class="container-fluid py-4">

    <div class="row">

        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

            <!-- ENCABEZADO -->

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

                <div>

                    <h4 class="h3 mb-1">
                        Mis solicitudes de anulación
                    </h4>

                    <p class="text-muted mb-0">
                        Consulta el estado y las respuestas de tus solicitudes.
                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('caja/ventas.php')) ?>"
                    class="btn btn-outline-success"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Volver al historial de ventas
                </a>

            </div>

            <!-- RESUMEN -->

            <div class="row g-3 mb-4">

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Total de solicitudes
                            </div>

                            <h3 class="h3 mb-0">
                                <?= number_format($totalSolicitudes) ?>
                            </h3>

                        </div>

                    </div>

                </div>

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Pendientes
                            </div>

                            <h3 class="h3 text-warning mb-0">
                                <?= number_format($totalPendientes) ?>
                            </h3>

                        </div>

                    </div>

                </div>

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Aprobadas
                            </div>

                            <h3 class="h3 text-success mb-0">
                                <?= number_format($totalAprobadas) ?>
                            </h3>

                        </div>

                    </div>

                </div>

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Rechazadas
                            </div>

                            <h3 class="h3 text-danger mb-0">
                                <?= number_format($totalRechazadas) ?>
                            </h3>

                        </div>

                    </div>

                </div>

            </div>

            <!-- FILTROS -->

            <div class="card border-0 shadow-sm rounded-3 mb-4">

                <div class="card-body p-3">

                    <form method="GET" class="row g-3 align-items-end">

                        <div class="col-12 col-md-7">

                            <label class="form-label fw-semibold">
                                Buscar solicitud
                            </label>

                            <input
                                type="text"
                                name="buscar"
                                class="form-control"
                                placeholder="Número de comprobante o motivo..."
                                value="<?= escapar($buscar) ?>"
                            >

                        </div>

                        <div class="col-12 col-md-3">

                            <label class="form-label fw-semibold">
                                Estado
                            </label>

                            <select name="estado" class="form-select">

                                <option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>
                                    Todos
                                </option>

                                <option value="pendiente" <?= $estadoFiltro === 'pendiente' ? 'selected' : '' ?>>
                                    Pendientes
                                </option>

                                <option value="aprobada" <?= $estadoFiltro === 'aprobada' ? 'selected' : '' ?>>
                                    Aprobadas
                                </option>

                                <option value="rechazada" <?= $estadoFiltro === 'rechazada' ? 'selected' : '' ?>>
                                    Rechazadas
                                </option>

                            </select>

                        </div>

                        <div class="col-12 col-md-2 d-flex gap-2">

                            <button type="submit" class="btn btn-success flex-grow-1">
                                <i class="bi bi-search"></i>
                                Buscar
                            </button>

                            <a
                                href="<?= escapar(urlSistema('caja/mis_solicitudes.php')) ?>"
                                class="btn btn-outline-secondary"
                                title="Limpiar filtros"
                            >
                                <i class="bi bi-x-lg"></i>
                            </a>

                        </div>

                    </form>

                </div>

            </div>

            <!-- LISTADO -->

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white border-0 p-4">

                    <h5 class="fw-bold mb-1">
                        Historial de solicitudes
                    </h5>

                    <p class="text-muted small mb-0">
                        Aquí puedes consultar las solicitudes que has enviado al administrador.
                    </p>

                </div>

                <div class="card-body p-0">

                    <?php if ($errorLectura): ?>

                        <div class="alert alert-danger m-3">
                            No fue posible consultar las solicitudes. Intenta nuevamente.
                        </div>

                    <?php elseif (empty($solicitudesFiltradas)): ?>

                        <div class="text-center py-5 px-3">

                            <i class="bi bi-inbox text-muted" style="font-size: 45px;"></i>

                            <h6 class="fw-bold mt-3">
                                No se encontraron solicitudes
                            </h6>

                            <p class="text-muted mb-0">
                                No hay solicitudes que coincidan con los filtros seleccionados.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th class="ps-4">Comprobante</th>

                                        <th>Fecha de solicitud</th>

                                        <th>Motivo</th>

                                        <th>Estado</th>

                                        <th>Respuesta administrativa</th>

                                        <th class="text-center">Acciones</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($solicitudesFiltradas as $solicitud): ?>

                                        <?php

                                        $estado = $solicitud['estado'] ?? 'pendiente';

                                        $numeroComprobante = $solicitud['numero_comprobante']
                                            ?? 'COMP-' . str_pad(
                                                (string) ($solicitud['id_venta'] ?? 0),
                                                6,
                                                '0',
                                                STR_PAD_LEFT
                                            );

                                        $fechaSolicitud = !empty($solicitud['fecha_solicitud'])
                                            ? date(
                                                'd/m/Y H:i',
                                                strtotime($solicitud['fecha_solicitud'])
                                            )
                                            : 'No disponible';

                                        $fechaResolucion = !empty($solicitud['fecha_resolucion'])
                                            ? date(
                                                'd/m/Y H:i',
                                                strtotime($solicitud['fecha_resolucion'])
                                            )
                                            : null;

                                        $nombreAdministrador = $solicitud['nombre_resolutor']
                                            ?? '';

                                        $respuesta = trim(
                                            (string) ($solicitud['respuesta_administrador'] ?? '')
                                        );

                                        $motivo = $solicitud['motivo'] ?? 'Sin motivo registrado';

                                        switch ($estado) {

                                            case 'aprobada':
                                                $claseEstado = 'success';
                                                $textoEstado = 'Aprobada';
                                                break;

                                            case 'rechazada':
                                                $claseEstado = 'danger';
                                                $textoEstado = 'Rechazada';
                                                break;

                                            default:
                                                $claseEstado = 'warning text-dark';
                                                $textoEstado = 'Pendiente';
                                                break;

                                        }

                                        ?>

                                        <tr>

                                            <td class="ps-4">

                                                <span class="fw-semibold">
                                                    <?= escapar($numeroComprobante) ?>
                                                </span>

                                            </td>

                                            <td>
                                                <?= escapar($fechaSolicitud) ?>
                                            </td>

                                            <td style="min-width: 180px; max-width: 260px;">

                                                <?= escapar($motivo) ?>

                                            </td>

                                            <td>

                                                <span class="badge bg-<?= escapar($claseEstado) ?>">
                                                    <?= escapar($textoEstado) ?>
                                                </span>

                                            </td>

                                            <td style="min-width: 220px; max-width: 320px;">

                                                <?php if ($estado === 'pendiente'): ?>

                                                    <span class="text-muted small">
                                                        Pendiente de revisión por el administrador.
                                                    </span>

                                                <?php else: ?>

                                                    <?php if ($nombreAdministrador !== ''): ?>

                                                        <div class="fw-semibold small mb-1">
                                                            <?= escapar($nombreAdministrador) ?>
                                                        </div>

                                                    <?php endif; ?>

                                                    <?php if ($respuesta !== ''): ?>

                                                        <span class="small">
                                                            <?= escapar($respuesta) ?>
                                                        </span>

                                                    <?php elseif ($estado === 'aprobada'): ?>

                                                        <span class="text-success small">
                                                            La solicitud fue aprobada.
                                                        </span>

                                                    <?php else: ?>

                                                        <span class="text-danger small">
                                                            La solicitud fue rechazada.
                                                        </span>

                                                    <?php endif; ?>

                                                    <?php if ($fechaResolucion): ?>

                                                        <div class="text-muted small mt-1">
                                                            <?= escapar($fechaResolucion) ?>
                                                        </div>

                                                    <?php endif; ?>

                                                <?php endif; ?>

                                            </td>

                                            <td class="text-center">

                                                <a
                                                    href="<?= escapar(
                                                        urlSistema(
                                                            'caja/comprobante.php?id=' .
                                                            (int) ($solicitud['id_venta'] ?? 0)
                                                        )
                                                    ) ?>"
                                                    class="btn btn-sm btn-outline-success"
                                                    target="_blank"
                                                >

                                                    <i class="bi bi-receipt me-1"></i>
                                                    Comprobante

                                                </a>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

                <div class="card-footer bg-white border-0 px-4 py-3">

                    <small class="text-muted">
                        Mostrando <?= number_format(count($solicitudesFiltradas)) ?>
                        de <?= number_format($totalSolicitudes) ?>
                        solicitudes registradas.
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
