<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

requerirRol('administrador', 'almacen');

$tituloPagina = 'Movimientos de inventario';
$paginaActual = 'inventario';

$errores = [];

/**
 * Filtros de búsqueda.
 */
$buscar = trim($_GET['buscar'] ?? '');
$tipoMovimiento = trim($_GET['tipo'] ?? '');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '');
$fechaFin = trim($_GET['fecha_fin'] ?? '');

$tiposPermitidos = [
    'entrada',
    'salida',
    'ajuste_positivo',
    'ajuste_negativo'
];

if (!in_array($tipoMovimiento, $tiposPermitidos, true)) {
    $tipoMovimiento = '';
}

if (strlen($buscar) > 100) {
    $buscar = substr($buscar, 0, 100);
    $errores[] = 'El texto de búsqueda no puede superar los 100 caracteres.';
}

/**
 * Validar fechas con formato YYYY-MM-DD.
 */
$fechaValida = static function ($fecha): bool {
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
 * Condiciones de consulta.
 */
$condiciones = [];
$parametros = [];

if ($buscar !== '') {
    $condiciones[] = "(
        p.nombre LIKE :buscar_nombre
        OR p.codigo_barras LIKE :buscar_codigo
    )";

    $parametros[':buscar_nombre'] = '%' . $buscar . '%';
    $parametros[':buscar_codigo'] = '%' . $buscar . '%';
}

if ($tipoMovimiento !== '') {
    $condiciones[] = 'm.tipo_movimiento = :tipo_movimiento';
    $parametros[':tipo_movimiento'] = $tipoMovimiento;
}

if ($fechaInicio !== '' && $fechaInicioValida) {
    $condiciones[] = 'm.fecha_movimiento >= :fecha_inicio';
    $parametros[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
}

if ($fechaFin !== '' && $fechaFinValida) {
    $condiciones[] = 'm.fecha_movimiento < :fecha_fin';
    $parametros[':fecha_fin'] = date(
        'Y-m-d H:i:s',
        strtotime($fechaFin . ' +1 day')
    );
}

if (
    $fechaInicioValida
    && $fechaFinValida
    && $fechaInicio !== ''
    && $fechaFin !== ''
    && $fechaInicio > $fechaFin
) {
    // Evitar aplicar un intervalo de fechas incorrecto.
    $condiciones = array_values(array_filter(
        $condiciones,
        static fn($condicion) =>
            !str_contains($condicion, ':fecha_inicio')
            && !str_contains($condicion, ':fecha_fin')
    ));

    unset($parametros[':fecha_inicio'], $parametros[':fecha_fin']);
}

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
    FROM movimientos_inventario m
    INNER JOIN productos p
        ON p.id_producto = m.id_producto
    INNER JOIN usuarios u
        ON u.id_usuario = m.id_usuario
    $where
";

/**
 * Contar registros.
 */
$sqlConteo = "SELECT COUNT(*) " . $sqlBase;

$stmtConteo = $pdo->prepare($sqlConteo);

foreach ($parametros as $nombreParametro => $valorParametro) {
    $stmtConteo->bindValue($nombreParametro, $valorParametro);
}

$stmtConteo->execute();

$totalMovimientos = (int) $stmtConteo->fetchColumn();

$totalPaginas = max(1, (int) ceil($totalMovimientos / $porPagina));

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
}

$offset = ($pagina - 1) * $porPagina;

/**
 * Consultar movimientos.
 */
$sqlMovimientos = "
    SELECT
        m.id_movimiento,
        m.id_producto,
        m.id_usuario,
        m.id_venta,
        m.tipo_movimiento,
        m.cantidad,
        m.fecha_movimiento,
        m.observaciones,
        p.nombre AS nombre_producto,
        p.codigo_barras,
        u.nombre AS nombre_usuario
    $sqlBase
    ORDER BY m.fecha_movimiento DESC, m.id_movimiento DESC
    LIMIT :limite OFFSET :offset
";

$stmtMovimientos = $pdo->prepare($sqlMovimientos);

foreach ($parametros as $nombreParametro => $valorParametro) {
    $stmtMovimientos->bindValue($nombreParametro, $valorParametro);
}

$stmtMovimientos->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmtMovimientos->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmtMovimientos->execute();

$movimientos = $stmtMovimientos->fetchAll(PDO::FETCH_ASSOC);

/**
 * Etiquetas y estilos para los tipos de movimiento.
 */
$tiposMovimientoInfo = [
    'entrada' => [
        'nombre' => 'Entrada',
        'clase' => 'success',
        'signo' => '+'
    ],
    'salida' => [
        'nombre' => 'Salida',
        'clase' => 'danger',
        'signo' => '-'
    ],
    'ajuste_positivo' => [
        'nombre' => 'Ajuste positivo',
        'clase' => 'primary',
        'signo' => '+'
    ],
    'ajuste_negativo' => [
        'nombre' => 'Ajuste negativo',
        'clase' => 'warning',
        'signo' => '-'
    ]
];

/**
 * Construir enlaces de paginación conservando los filtros.
 */
$parametrosUrl = [
    'buscar' => $buscar,
    'tipo' => $tipoMovimiento,
    'fecha_inicio' => $fechaInicio,
    'fecha_fin' => $fechaFin
];

$crearUrlPagina = static function (int $numeroPagina) use ($parametrosUrl): string {
    $parametros = $parametrosUrl;
    $parametros['pagina'] = $numeroPagina;

    return urlSistema(
        'inventario/movimientos.php?' . http_build_query($parametros)
    );
};

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
                        Movimientos de inventario
                    </h1>

                    <p class="text-muted mb-0">
                        Consulta las entradas, salidas y ajustes de productos.
                    </p>
                </div>

                <div class="d-flex align-items-center gap-2">

                    <span class="badge bg-success fs-6">
                        <?= number_format($totalMovimientos) ?> movimientos
                    </span>

                </div>

            </div>

            <!-- Errores de filtros -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-warning">
                    <strong>Revisa los filtros:</strong>

                    <ul class="mb-0 mt-2">
                        <?php foreach ($errores as $error): ?>
                            <li><?= escapar($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            <?php endif; ?>

            <!-- Filtros -->
            <div class="card shadow-sm border-0 mb-4">

                <div class="card-header bg-white py-3">
                    <h2 class="h5 mb-0">
                        <i class="bi bi-funnel"></i>
                        Buscar movimientos
                    </h2>
                </div>

                <div class="card-body">

                    <form method="GET" action="">

                        <div class="row g-3">

                            <div class="col-lg-4 col-md-6">

                                <label for="buscar" class="form-label">
                                    Producto
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="buscar"
                                    name="buscar"
                                    maxlength="100"
                                    value="<?= escapar($buscar) ?>"
                                    placeholder="Nombre o código de barras"
                                >

                            </div>

                            <div class="col-lg-2 col-md-6">

                                <label for="tipo" class="form-label">
                                    Tipo de movimiento
                                </label>

                                <select
                                    class="form-select"
                                    id="tipo"
                                    name="tipo"
                                >
                                    <option value="">Todos</option>

                                    <option value="entrada"
                                        <?= $tipoMovimiento === 'entrada' ? 'selected' : '' ?>>
                                        Entrada
                                    </option>

                                    <option value="salida"
                                        <?= $tipoMovimiento === 'salida' ? 'selected' : '' ?>>
                                        Salida
                                    </option>

                                    <option value="ajuste_positivo"
                                        <?= $tipoMovimiento === 'ajuste_positivo' ? 'selected' : '' ?>>
                                        Ajuste positivo
                                    </option>

                                    <option value="ajuste_negativo"
                                        <?= $tipoMovimiento === 'ajuste_negativo' ? 'selected' : '' ?>>
                                        Ajuste negativo
                                    </option>

                                </select>

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

                            <div class="col-lg-2 d-flex align-items-end gap-2">

                                <button type="submit" class="btn btn-success flex-grow-1">
                                    <i class="bi bi-search"></i>
                                    Buscar
                                </button>

                                <a
                                    href="<?= escapar(urlSistema('inventario/movimientos.php')) ?>"
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

            <!-- Tabla de movimientos -->
            <div class="card shadow-sm border-0">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-clock-history"></i>
                        Historial de movimientos
                    </h2>

                </div>

                <div class="card-body p-0">

                    <?php if (empty($movimientos)): ?>

                        <div class="text-center py-5">

                            <i class="bi bi-inbox display-4 text-muted"></i>

                            <h5 class="mt-3">
                                No se encontraron movimientos
                            </h5>

                            <p class="text-muted mb-0">
                                Cuando se registren entradas, salidas o ajustes,
                                aparecerán en esta sección.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>
                                        <th>Fecha</th>
                                        <th>Producto</th>
                                        <th>Tipo</th>
                                        <th class="text-center">Cantidad</th>
                                        <th>Usuario</th>
                                        <th>Venta relacionada</th>
                                        <th>Observaciones</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($movimientos as $movimiento): ?>

                                        <?php
                                        $tipoInfo = $tiposMovimientoInfo[
                                            $movimiento['tipo_movimiento']
                                        ] ?? [
                                            'nombre' => 'Desconocido',
                                            'clase' => 'secondary',
                                            'signo' => ''
                                        ];
                                        ?>

                                        <tr>

                                            <td class="text-nowrap">
                                                <?= escapar(
                                                    formatearFechaHora($movimiento['fecha_movimiento'])
                                                ) ?>
                                            </td>

                                            <td>

                                                <strong>
                                                    <?= escapar($movimiento['nombre_producto']) ?>
                                                </strong>

                                                <div class="small text-muted">
                                                    Código:
                                                    <?= escapar($movimiento['codigo_barras']) ?>
                                                </div>

                                            </td>

                                            <td>

                                                <span class="badge text-bg-<?= escapar($tipoInfo['clase']) ?>">
                                                    <?= escapar($tipoInfo['nombre']) ?>
                                                </span>

                                            </td>

                                            <td class="text-center fw-bold">

                                                <span class="<?= $tipoInfo['signo'] === '+' ? 'text-success' : 'text-danger' ?>">
                                                    <?= escapar($tipoInfo['signo']) ?>
                                                    <?= number_format((int) $movimiento['cantidad']) ?>
                                                </span>

                                            </td>

                                            <td>
                                                <?= escapar($movimiento['nombre_usuario']) ?>
                                            </td>

                                            <td>

                                                <?php if ($movimiento['id_venta'] !== null): ?>

                                                    <span class="badge text-bg-light border">
                                                        <?= escapar(
                                                            generarNumeroComprobante(
                                                                (int) $movimiento['id_venta']
                                                            )
                                                        ) ?>
                                                    </span>

                                                <?php else: ?>

                                                    <span class="text-muted">—</span>

                                                <?php endif; ?>

                                            </td>

                                            <td style="min-width: 180px;">
                                                <?= escapar(
                                                    $movimiento['observaciones'] ?: 'Sin observaciones'
                                                ) ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

                <!-- Paginación -->
                <?php if ($totalPaginas > 1): ?>

                    <div class="card-footer bg-white">

                        <nav aria-label="Paginación de movimientos">

                            <ul class="pagination justify-content-center mb-0">

                                <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">

                                    <a
                                        class="page-link"
                                        href="<?= escapar($crearUrlPagina(max(1, $pagina - 1))) ?>"
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
                                        href="<?= escapar($crearUrlPagina(min($totalPaginas, $pagina + 1))) ?>"
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
