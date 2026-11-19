<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requerirRol('administrador', 'cajero');

$tituloPagina = 'Historial de ventas';
$paginaActual = 'caja';

$usuarioActual = obtenerUsuarioActual();

$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);
$rolUsuario = $usuarioActual['rol'] ?? '';

$errores = [];

/**
 * Obtener filtros.
 */
$buscar = is_string($_GET['buscar'] ?? null)
    ? trim($_GET['buscar'])
    : '';

$fechaInicio = is_string($_GET['fecha_inicio'] ?? null)
    ? trim($_GET['fecha_inicio'])
    : '';

$fechaFin = is_string($_GET['fecha_fin'] ?? null)
    ? trim($_GET['fecha_fin'])
    : '';

$metodoPago = is_string($_GET['metodo_pago'] ?? null)
    ? trim($_GET['metodo_pago'])
    : '';

$estado = is_string($_GET['estado'] ?? null)
    ? trim($_GET['estado'])
    : '';

if (strlen($buscar) > 100) {
    $buscar = substr($buscar, 0, 100);
    $errores[] = 'La búsqueda no puede superar los 100 caracteres.';
}

/**
 * Validar fechas.
 */
$fechaValida = static function (string $fecha): bool {

    if ($fecha === '') {
        return true;
    }

    $fechaObjeto = DateTime::createFromFormat('!Y-m-d', $fecha);

    $erroresFecha = DateTime::getLastErrors();

    return $fechaObjeto !== false
        && (
            $erroresFecha === false
            || (
                $erroresFecha['warning_count'] === 0
                && $erroresFecha['error_count'] === 0
            )
        )
        && $fechaObjeto->format('Y-m-d') === $fecha;
};

$fechaInicioValida = $fechaValida($fechaInicio);
$fechaFinValida = $fechaValida($fechaFin);

if (!$fechaInicioValida) {
    $errores[] = 'La fecha inicial no es válida.';
}

if (!$fechaFinValida) {
    $errores[] = 'La fecha final no es válida.';
}

if (
    $fechaInicioValida
    && $fechaFinValida
    && $fechaInicio !== ''
    && $fechaFin !== ''
    && $fechaInicio > $fechaFin
) {
    $errores[] = 'La fecha inicial no puede ser posterior a la fecha final.';
}

/**
 * Validar método de pago y estado.
 */
$metodosPermitidos = [
    '',
    'efectivo',
    'tarjeta',
    'sinpe',
    'otro'
];

$estadosPermitidos = [
    '',
    'completada',
    'anulada'
];

if (!in_array($metodoPago, $metodosPermitidos, true)) {
    $metodoPago = '';
}

if (!in_array($estado, $estadosPermitidos, true)) {
    $estado = '';
}

/**
 * Construir filtros SQL.
 */
$condiciones = [];
$parametros = [];

/**
 * Restricción de acceso para cajeros.
 */
if ($rolUsuario === 'cajero') {

    $condiciones[] = 'v.id_usuario = :id_usuario_actual';

    $parametros[':id_usuario_actual'] = $idUsuario;

}

/**
 * Búsqueda por comprobante, cliente o cajero.
 */
if ($buscar !== '') {

    $terminoComprobante = preg_replace(
        '/^COMP-/i',
        '',
        $buscar
    );

    $terminoComprobante = ltrim($terminoComprobante, '0');

    if ($terminoComprobante !== '' && ctype_digit($terminoComprobante)) {

        $condiciones[] = "(
            v.id_venta = :buscar_id
            OR v.observaciones LIKE :buscar_cliente
            OR u.nombre LIKE :buscar_usuario
        )";

        $parametros[':buscar_id'] = (int) $terminoComprobante;

    } else {

        $condiciones[] = "(
            v.observaciones LIKE :buscar_cliente
            OR u.nombre LIKE :buscar_usuario
        )";

    }

    $parametros[':buscar_cliente'] = '%' . $buscar . '%';
    $parametros[':buscar_usuario'] = '%' . $buscar . '%';

}

/**
 * Filtro por fechas.
 */
if ($fechaInicio !== '' && $fechaInicioValida) {

    $condiciones[] = 'v.fecha_venta >= :fecha_inicio';

    $parametros[':fecha_inicio'] = $fechaInicio . ' 00:00:00';

}

if ($fechaFin !== '' && $fechaFinValida) {

    $condiciones[] = 'v.fecha_venta < :fecha_fin';

    $parametros[':fecha_fin'] = date(
        'Y-m-d H:i:s',
        strtotime($fechaFin . ' +1 day')
    );

}

/**
 * Filtro por método de pago.
 */
if ($metodoPago !== '') {

    $condiciones[] = 'v.metodo_pago = :metodo_pago';

    $parametros[':metodo_pago'] = $metodoPago;

}

/**
 * Filtro por estado.
 */
if ($estado !== '') {

    $condiciones[] = 'v.estado = :estado';

    $parametros[':estado'] = $estado;

}

/**
 * Construir WHERE.
 */
$where = '';

if (!empty($condiciones)) {
    $where = ' WHERE ' . implode(' AND ', $condiciones);
}

/**
 * Paginación.
 */
$porPagina = 15;

$pagina = filter_var(
    $_GET['pagina'] ?? 1,
    FILTER_VALIDATE_INT
);

$pagina = $pagina !== false && $pagina > 0 ? $pagina : 1;

/**
 * Consulta base.
 */
$sqlBase = "
    FROM ventas v
    INNER JOIN usuarios u
        ON u.id_usuario = v.id_usuario
    $where
";

/**
 * Contar ventas.
 */
$stmtConteo = $pdo->prepare(
    "SELECT COUNT(*) " . $sqlBase
);

$stmtConteo->execute($parametros);

$totalVentas = (int) $stmtConteo->fetchColumn();

/**
 * Resumen de las ventas filtradas.
 */
$stmtResumen = $pdo->prepare(
    "SELECT
        COUNT(*) AS cantidad_ventas,
        COALESCE(
            SUM(
                CASE
                    WHEN v.estado = 'completada' THEN v.total
                    ELSE 0
                END
            ),
            0
        ) AS total_completadas,
        COALESCE(
            SUM(
                CASE
                    WHEN v.estado = 'anulada' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS cantidad_anuladas
     " . $sqlBase
);

$stmtResumen->execute($parametros);

$resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC);

$totalPaginas = max(
    1,
    (int) ceil($totalVentas / $porPagina)
);

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
}

$offset = ($pagina - 1) * $porPagina;

/**
 * Consultar ventas.
 */
$sqlVentas = "
    SELECT
        v.id_venta,
        v.id_usuario,
        v.fecha_venta,
        v.subtotal,
        v.descuento,
        v.total,
        v.metodo_pago,
        v.estado,
        v.observaciones,
        u.nombre AS nombre_cajero
    " . $sqlBase . "
    ORDER BY v.fecha_venta DESC, v.id_venta DESC
    LIMIT :limite OFFSET :offset
";

$stmtVentas = $pdo->prepare($sqlVentas);

foreach ($parametros as $nombreParametro => $valor) {
    $stmtVentas->bindValue($nombreParametro, $valor);
}

$stmtVentas->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmtVentas->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmtVentas->execute();

$ventas = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);


/**
 * Consultar solicitudes de anulación almacenadas en JSON.
 * Se conserva la solicitud más reciente de cada venta.
 */
$solicitudesPorVenta = [];

$rutaSolicitudes = __DIR__ . '/../storage/solicitudes_anulacion.json';

if (is_file($rutaSolicitudes)) {

    try {

        $archivoSolicitudes = fopen($rutaSolicitudes, 'rb');

        if ($archivoSolicitudes === false) {
            throw new RuntimeException('No se pudo abrir el archivo de solicitudes.');
        }

        try {

            if (!flock($archivoSolicitudes, LOCK_SH)) {
                throw new RuntimeException('No se pudo bloquear el archivo de solicitudes.');
            }

            $contenidoSolicitudes = stream_get_contents($archivoSolicitudes);

            if ($contenidoSolicitudes === false) {
                throw new RuntimeException('No se pudo leer el archivo de solicitudes.');
            }

            $registrosSolicitudes = trim($contenidoSolicitudes) === ''
                ? []
                : json_decode($contenidoSolicitudes, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($registrosSolicitudes)) {
                throw new RuntimeException('El archivo de solicitudes contiene información inválida.');
            }

            foreach ($registrosSolicitudes as $registro) {

                if (!is_array($registro)) {
                    continue;
                }

                $idVentaSolicitud = (int) ($registro['id_venta'] ?? 0);

                if ($idVentaSolicitud <= 0) {
                    continue;
                }

                $fechaNueva = (string) ($registro['fecha_solicitud'] ?? '');

                $solicitudAnterior = $solicitudesPorVenta[$idVentaSolicitud] ?? null;

                $fechaAnterior = (string) (
                    $solicitudAnterior['fecha_solicitud'] ?? ''
                );

                if (
                    $solicitudAnterior === null
                    || strcmp($fechaNueva, $fechaAnterior) >= 0
                ) {
                    $solicitudesPorVenta[$idVentaSolicitud] = $registro;
                }

            }

        } finally {

            flock($archivoSolicitudes, LOCK_UN);
            fclose($archivoSolicitudes);

        }

    } catch (Throwable $e) {

        error_log(
            'Error al leer solicitudes desde el historial de ventas: '
            . $e->getMessage()
        );

    }

}


/**
 * Etiquetas de métodos de pago.
 */
$metodosNombre = [
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta',
    'sinpe' => 'SINPE Móvil',
    'otro' => 'Otro'
];

/**
 * Crear URL de paginación conservando filtros.
 */
$parametrosUrl = [
    'buscar' => $buscar,
    'fecha_inicio' => $fechaInicio,
    'fecha_fin' => $fechaFin,
    'metodo_pago' => $metodoPago,
    'estado' => $estado
];

$crearUrlPagina = static function (int $numeroPagina) use ($parametrosUrl): string {

    $parametros = $parametrosUrl;
    $parametros['pagina'] = $numeroPagina;

    return urlSistema(
        'caja/ventas.php?' . http_build_query($parametros)
    );

};

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
                        Historial de ventas
                    </h1>

                    <p class="text-muted mb-0">

                        <?= $rolUsuario === 'administrador'
                            ? 'Consulta y administra la información de las ventas realizadas.'
                            : 'Consulta las ventas que has registrado.' ?>

                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('caja/index.php')) ?>"
                    class="btn btn-success"
                >
                    <i class="bi bi-plus-circle"></i>
                    Nueva venta
                </a>

            </div>

            <!-- RESUMEN -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="card shadow-sm border-0 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Ventas encontradas
                            </div>

                            <div class="h3 mb-0">
                                <?= number_format((int) $resumen['cantidad_ventas']) ?>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card shadow-sm border-0 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Total de ventas completadas
                            </div>

                            <div class="h3 mb-0 text-success">
                                <?= escapar(
                                    formatearMoneda(
                                        (float) $resumen['total_completadas']
                                    )
                                ) ?>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card shadow-sm border-0 h-100">

                        <div class="card-body">

                            <div class="text-muted small">
                                Ventas anuladas
                            </div>

                            <div class="h3 mb-0">
                                <?= number_format((int) $resumen['cantidad_anuladas']) ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- ERRORES DE FILTROS -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-warning" id="erroresFiltrosVentas">

                    <strong>Revisa los filtros:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach ($errores as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <!-- FILTROS -->
            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-funnel"></i>
                        Buscar ventas
                    </h2>

                </div>

                <div class="card-body">

                    <form method="GET" action="" id="formFiltrosVentas">

                        <div class="row g-3">

                            <div class="col-lg-4 col-md-6">

                                <label for="buscar" class="form-label">
                                    Comprobante, cliente
                                    <?= $rolUsuario === 'administrador' ? 'o cajero' : '' ?>
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="buscar"
                                    name="buscar"
                                    maxlength="100"
                                    value="<?= escapar($buscar) ?>"
                                    placeholder="Ejemplo: COMP-000025"
                                >

                            </div>

                            <div class="col-lg-2 col-md-6">

                                <label for="fecha_inicio" class="form-label">
                                    Desde
                                </label>

                                <input
                                    type="date"
                                    class="form-control"
                                    id="fecha_inicio"
                                    name="fecha_inicio"
                                    value="<?= escapar($fechaInicio) ?>"
                                >

                            </div>

                            <div class="col-lg-2 col-md-6">

                                <label for="fecha_fin" class="form-label">
                                    Hasta
                                </label>

                                <input
                                    type="date"
                                    class="form-control"
                                    id="fecha_fin"
                                    name="fecha_fin"
                                    value="<?= escapar($fechaFin) ?>"
                                >

                            </div>

                            <div class="col-lg-2 col-md-6">

                                <label for="metodo_pago" class="form-label">
                                    Método de pago
                                </label>

                                <select
                                    class="form-select"
                                    id="metodo_pago"
                                    name="metodo_pago"
                                >

                                    <option value="">Todos</option>

                                    <?php foreach ($metodosNombre as $clave => $nombreMetodo): ?>

                                        <option
                                            value="<?= escapar($clave) ?>"
                                            <?= $metodoPago === $clave ? 'selected' : '' ?>
                                        >
                                            <?= escapar($nombreMetodo) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="col-lg-2 col-md-6">

                                <label for="estado" class="form-label">
                                    Estado
                                </label>

                                <select
                                    class="form-select"
                                    id="estado"
                                    name="estado"
                                >

                                    <option value="">Todos</option>

                                    <option
                                        value="completada"
                                        <?= $estado === 'completada' ? 'selected' : '' ?>
                                    >
                                        Completadas
                                    </option>

                                    <option
                                        value="anulada"
                                        <?= $estado === 'anulada' ? 'selected' : '' ?>
                                    >
                                        Anuladas
                                    </option>

                                </select>

                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">

                                <a
                                    href="<?= escapar(urlSistema('caja/ventas.php')) ?>"
                                    class="btn btn-outline-secondary"
                                >
                                    <i class="bi bi-x-circle"></i>
                                    Limpiar
                                </a>

                                <button
                                    type="submit"
                                    class="btn btn-success"
                                >
                                    <i class="bi bi-search"></i>
                                    Buscar
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- TABLA DE VENTAS -->
            <div class="card shadow-sm border-0">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-receipt"></i>
                        Registro de ventas
                    </h2>

                </div>

                <div class="card-body p-0">

                    <?php if (empty($ventas)): ?>

                        <div class="text-center py-5">

                            <i class="bi bi-receipt display-4 text-muted"></i>

                            <h5 class="mt-3">
                                No se encontraron ventas
                            </h5>

                            <p class="text-muted mb-0">
                                Prueba con otros filtros o registra una nueva venta.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th>Comprobante</th>

                                        <th>Fecha</th>

                                        <th>Cliente</th>

                                        <?php if ($rolUsuario === 'administrador'): ?>
                                            <th>Cajero</th>
                                        <?php endif; ?>

                                        <th>Método de pago</th>

                                        <th class="text-end">Total</th>

                                        <th class="text-center">Estado</th>

                                        <th>Solicitud / Anulación</th>

                                        <th class="text-end">Acciones</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($ventas as $venta): ?>

                                        <?php

                                        $numeroComprobante = generarNumeroComprobante(
                                            (int) $venta['id_venta']
                                        );

                                        $observacionesVenta = trim(
                                            (string) ($venta['observaciones'] ?? '')
                                        );

                                        $cliente = 'Consumidor final';

                                        if (
                                            preg_match(
                                                '/(?:^|\R)Cliente:\s*([^\r\n]+)/u',
                                                $observacionesVenta,
                                                $coincidencias
                                            )
                                        ) {
                                            $cliente = trim($coincidencias[1]);
                                        }

                                        ?>

                                        <?php

                                        $solicitudVenta = $solicitudesPorVenta[
                                            (int) $venta['id_venta']
                                        ] ?? null;

                                        $estadoSolicitudVenta = $solicitudVenta['estado'] ?? null;

                                        ?>

                                        <tr>

                                            <td>

                                                <strong>
                                                    <?= escapar($numeroComprobante) ?>
                                                </strong>

                                            </td>

                                            <td class="text-nowrap">

                                                <?= escapar(
                                                    formatearFechaHora($venta['fecha_venta'])
                                                ) ?>

                                            </td>

                                            <td>
                                                <?= escapar($cliente) ?>
                                            </td>

                                            <?php if ($rolUsuario === 'administrador'): ?>

                                                <td>
                                                    <?= escapar($venta['nombre_cajero']) ?>
                                                </td>

                                            <?php endif; ?>

                                            <td>

                                                <?= escapar(
                                                    $metodosNombre[$venta['metodo_pago']]
                                                    ?? ucfirst($venta['metodo_pago'])
                                                ) ?>

                                            </td>

                                            <td class="text-end text-nowrap fw-semibold">

                                                <?= escapar(
                                                    formatearMoneda((float) $venta['total'])
                                                ) ?>

                                            </td>

                                            <td class="text-center">

                                                <?php if ($venta['estado'] === 'completada'): ?>

                                                    <span class="badge bg-success">
                                                        Completada
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-secondary">
                                                        Anulada
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?php if ($venta['estado'] === 'anulada'): ?>

                                                    <?php if ($estadoSolicitudVenta === 'aprobada'): ?>

                                                        <span class="badge bg-success">
                                                            Anulada por solicitud
                                                        </span>

                                                        <div class="small text-muted mt-1">
                                                            Autorizó:
                                                            <?= escapar(
                                                                $solicitudVenta['nombre_resolutor']
                                                                ?? 'Administrador'
                                                            ) ?>
                                                        </div>

                                                        <?php if (!empty($solicitudVenta['fecha_resolucion'])): ?>

                                                            <div class="small text-muted">
                                                                <?= escapar(
                                                                    formatearFechaHora(
                                                                        $solicitudVenta['fecha_resolucion']
                                                                    )
                                                                ) ?>
                                                            </div>

                                                        <?php endif; ?>

                                                    <?php else: ?>

                                                        <span class="badge bg-secondary">
                                                            Anulación administrativa
                                                        </span>

                                                        <div class="small text-muted mt-1">
                                                            Sin solicitud de cajero
                                                        </div>

                                                    <?php endif; ?>

                                                <?php elseif ($estadoSolicitudVenta === 'pendiente'): ?>

                                                    <span class="badge bg-warning text-dark">
                                                        Solicitud pendiente
                                                    </span>

                                                    <div class="small text-muted mt-1">
                                                        Solicitó:
                                                        <?= escapar(
                                                            $solicitudVenta['nombre_solicitante']
                                                            ?? 'Cajero'
                                                        ) ?>
                                                    </div>

                                                <?php elseif ($estadoSolicitudVenta === 'rechazada'): ?>

                                                    <span class="badge bg-secondary">
                                                        Solicitud rechazada
                                                    </span>

                                                    <div class="small text-muted mt-1">
                                                        Resolvió:
                                                        <?= escapar(
                                                            $solicitudVenta['nombre_resolutor']
                                                            ?? 'Administrador'
                                                        ) ?>
                                                    </div>

                                                <?php else: ?>

                                                    <span class="text-muted">
                                                        Sin solicitud
                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <td class="text-end text-nowrap">

                                                <a
                                                    href="<?= escapar(
                                                        urlSistema(
                                                            'caja/comprobante.php?id='
                                                            . (int) $venta['id_venta']
                                                        )
                                                    ) ?>"
                                                    class="btn btn-sm btn-outline-success"
                                                    title="Ver comprobante"
                                                >
                                                    <i class="bi bi-receipt"></i>
                                                    Ver
                                                </a>
                                               
                                                <?php if ($venta['estado'] === 'completada'): ?>

                                                    <?php if ($rolUsuario === 'administrador'): ?>

                                                        <?php if ($estadoSolicitudVenta === 'pendiente'): ?>

                                                            <?php
                                                            $idSolicitudAccion = (string) (
                                                                $solicitudVenta['id_solicitud'] ?? ''
                                                            );
                                                            ?>

                                                            <?php if (preg_match('/^[a-f0-9]{24}$/i', $idSolicitudAccion)): ?>

                                                                <a
                                                                    href="<?= escapar(
                                                                        urlSistema(
                                                                            'admin/resolver_solicitud_anulacion.php?id='
                                                                            . rawurlencode($idSolicitudAccion)
                                                                        )
                                                                    ) ?>"
                                                                    class="btn btn-sm btn-outline-warning"
                                                                >
                                                                    <i class="bi bi-eye"></i>
                                                                    Revisar solicitud
                                                                </a>

                                                            <?php endif; ?>

                                                        <?php else: ?>

                                                            <a
                                                                href="<?= escapar(
                                                                    urlSistema(
                                                                        'admin/anular_venta.php?id='
                                                                        . (int) $venta['id_venta']
                                                                    )
                                                                ) ?>"
                                                                class="btn btn-sm btn-outline-danger"
                                                                title="Anular venta directamente"
                                                            >
                                                                <i class="bi bi-x-circle"></i>
                                                                Anular
                                                            </a>

                                                        <?php endif; ?>

                                                    <?php elseif ($rolUsuario === 'cajero'): ?>

                                                        <?php if ($estadoSolicitudVenta === 'pendiente'): ?>

                                                            <span class="badge bg-warning text-dark">
                                                                <i class="bi bi-clock-history"></i>
                                                                En revisión
                                                            </span>

                                                        <?php else: ?>

                                                            <a
                                                                href="<?= escapar(
                                                                    urlSistema(
                                                                        'caja/solicitar_anulacion.php?id='
                                                                        . (int) $venta['id_venta']
                                                                    )
                                                                ) ?>"
                                                                class="btn btn-sm btn-outline-warning"
                                                            >
                                                                <i class="bi bi-send"></i>

                                                                <?= $estadoSolicitudVenta === 'rechazada'
                                                                    ? 'Reenviar solicitud'
                                                                    : 'Solicitar anulación' ?>

                                                            </a>

                                                        <?php endif; ?>

                                                    <?php endif; ?>

                                                <?php endif; ?>


                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

                <!-- PAGINACIÓN -->
                <?php if ($totalPaginas > 1): ?>

                    <div class="card-footer bg-white">

                        <nav aria-label="Paginación de ventas">

                            <ul class="pagination justify-content-center mb-0">

                                <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="<?= escapar(
                                            $crearUrlPagina(max(1, $pagina - 1))
                                        ) ?>"
                                    >
                                        Anterior
                                    </a>

                                </li>

                                <?php

                                $inicioPagina = max(1, $pagina - 2);
                                $finPagina = min($totalPaginas, $pagina + 2);

                                ?>

                                <?php for ($i = $inicioPagina; $i <= $finPagina; $i++): ?>

                                    <li class="page-item <?= $pagina === $i ? 'active' : '' ?>">

                                        <a
                                            class="page-link"
                                            href="<?= escapar($crearUrlPagina($i)) ?>"
                                        >
                                            <?= $i ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="<?= escapar(
                                            $crearUrlPagina(min($totalPaginas, $pagina + 1))
                                        ) ?>"
                                    >
                                        Siguiente
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            </div>

        </main>

    </div>

</div>

<?php

require_once __DIR__ . '/../includes/alerts.php';
?>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const formulario = document.getElementById('formFiltrosVentas');

    const erroresFiltros = document.getElementById('erroresFiltrosVentas');

    const campoFechaInicio = document.getElementById('fecha_inicio');

    const campoFechaFin = document.getElementById('fecha_fin');

    /*
    |--------------------------------------------------------------------------
    | Mostrar errores enviados por PHP
    |--------------------------------------------------------------------------
    */

    if (erroresFiltros && typeof Swal !== 'undefined') {

        const errores = Array.from(
            erroresFiltros.querySelectorAll('li')
        ).map(function (elemento) {

            return elemento.textContent.trim();

        }).filter(Boolean);

        if (errores.length > 0) {

            const escaparHTML = function (texto) {

                const elemento = document.createElement('div');

                elemento.textContent = texto;

                return elemento.innerHTML;

            };

            const listaHTML = `
                <ul class="text-start mb-0">
                    ${errores.map(function (error) {
                        return '<li>' + escaparHTML(error) + '</li>';
                    }).join('')}
                </ul>
            `;

            erroresFiltros.classList.add('d-none');

            Swal.fire({

                icon: 'warning',

                title: 'Revisa los filtros',

                html: listaHTML,

                confirmButtonText: 'Entendido',

                confirmButtonColor: '#198754'

            });

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Validar el rango de fechas antes de buscar
    |--------------------------------------------------------------------------
    */

    if (formulario) {

        formulario.addEventListener('submit', function (evento) {

            const fechaInicio = campoFechaInicio.value;

            const fechaFin = campoFechaFin.value;

            if (
                fechaInicio !== ''
                && fechaFin !== ''
                && fechaInicio > fechaFin
            ) {

                evento.preventDefault();

                if (typeof Swal !== 'undefined') {

                    Swal.fire({

                        icon: 'warning',

                        title: 'Rango de fechas incorrecto',

                        text: 'La fecha inicial no puede ser posterior a la fecha final.',

                        confirmButtonText: 'Corregir fechas',

                        confirmButtonColor: '#198754'

                    }).then(function () {

                        campoFechaInicio.focus();

                    });

                } else {

                    alert(
                        'La fecha inicial no puede ser posterior a la fecha final.'
                    );

                }

            }

        });

    }

});

</script>


<?php
require_once __DIR__ . '/../includes/footer.php';
?>
