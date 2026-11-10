<?php

/**
 * PANEL PRINCIPAL DEL ADMINISTRADOR
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Muestra informacion general del negocio mediante
 * consultas reales a la base de datos.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

/** @var PDO $pdo */

// Verificar que el usuario sea administrador.
requerirRol('administrador');
$usuarioActual = obtenerUsuarioActual();

$tituloPagina = 'Panel administrativo';

$errorCarga = false;
$errorSolicitudes = false;


// ==========================================
// 2. DEFINIR FECHAS DEL DIA
// ==========================================

$inicioDia = date('Y-m-d 00:00:00');

$finDia = date(
    'Y-m-d 00:00:00',
    strtotime('+1 day')
);


// ==========================================
// 3. VARIABLES INICIALES
// ==========================================

$resumen = [
    'total_productos' => 0,
    'productos_stock_bajo' => 0,
    'productos_sin_stock' => 0,
    'usuarios_activos' => 0
];

$ventasHoy = [
    'cantidad_ventas' => 0,
    'total_ventas' => 0
];

$ventasRecientes = [];

$productosCriticos = [];

$totalSolicitudesPendientes = 0;


// ==========================================
// 4. CONSULTAR RESUMEN GENERAL
// ==========================================

try {

    $consultaResumen = $pdo->query(
        "SELECT

            (
                SELECT COUNT(*)
                FROM productos
                WHERE estado = 1
            ) AS total_productos,

            (
                SELECT COUNT(*)
                FROM productos
                WHERE estado = 1
                AND stock_actual > 0
                AND stock_actual <= stock_minimo
            ) AS productos_stock_bajo,

            (
                SELECT COUNT(*)
                FROM productos
                WHERE estado = 1
                AND stock_actual = 0
            ) AS productos_sin_stock,

            (
                SELECT COUNT(*)
                FROM usuarios
                WHERE estado = 1
            ) AS usuarios_activos"
    );

    $resumen = $consultaResumen->fetch();


// ==========================================
// 5. CONSULTAR VENTAS DEL DIA
// ==========================================

    $consultaVentasHoy = $pdo->prepare(
        "SELECT

            COUNT(*) AS cantidad_ventas,

            COALESCE(SUM(total), 0) AS total_ventas

         FROM ventas

         WHERE estado = 'completada'

         AND fecha_venta >= :inicio

         AND fecha_venta < :fin"
    );

    $consultaVentasHoy->execute([

        'inicio' => $inicioDia,

        'fin' => $finDia

    ]);

    $ventasHoy = $consultaVentasHoy->fetch();


// ==========================================
// 6. CONSULTAR ULTIMAS VENTAS
// ==========================================

    $consultaRecientes = $pdo->query(
        "SELECT

            v.id_venta,

            v.fecha_venta,

            v.total,

            v.metodo_pago,

            v.estado,

            u.nombre AS nombre_cajero

         FROM ventas v

         INNER JOIN usuarios u
            ON v.id_usuario = u.id_usuario

         ORDER BY v.fecha_venta DESC

         LIMIT 8"
    );

    $ventasRecientes = $consultaRecientes->fetchAll();


// ==========================================
// 7. CONSULTAR PRODUCTOS CON STOCK CRITICO
// ==========================================

    $consultaCriticos = $pdo->query(
        "SELECT

            id_producto,

            nombre,

            codigo_barras,

            stock_actual,

            stock_minimo

         FROM productos

         WHERE estado = 1

         AND stock_actual <= stock_minimo

         ORDER BY stock_actual ASC, nombre ASC

         LIMIT 8"
    );

    $productosCriticos = $consultaCriticos->fetchAll();


} catch (Throwable $e) {

    error_log(
        'Error al cargar panel administrativo: ' .
        $e->getMessage()
    );

    $errorCarga = true;

}


// ==========================================
// 8. CONSULTAR SOLICITUDES DE RECUPERACION
// ==========================================

$rutaSolicitudes = __DIR__ .
    '/../storage/solicitudes_recuperacion.json';

if (is_file($rutaSolicitudes)) {

    $archivo = fopen($rutaSolicitudes, 'r');

    if ($archivo !== false) {

        $bloqueoAdquirido = false;

        try {

            if (!flock($archivo, LOCK_SH)) {

                throw new RuntimeException(
                    'No fue posible consultar las solicitudes.'
                );

            }

            $bloqueoAdquirido = true;

            $contenido = stream_get_contents($archivo);

            if ($contenido === false) {

                throw new RuntimeException(
                    'No fue posible leer las solicitudes.'
                );

            }

            $solicitudes = trim($contenido) === ''
                ? []
                : json_decode(
                    $contenido,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

            if (
                !is_array($solicitudes) ||
                !array_is_list($solicitudes)
            ) {

                throw new RuntimeException(
                    'El formato del archivo JSON no es valido.'
                );

            }

            foreach ($solicitudes as $solicitud) {

                if (
                    is_array($solicitud) &&
                    ($solicitud['estado'] ?? '') === 'pendiente'
                ) {

                    $totalSolicitudesPendientes++;

                }

            }

        } catch (Throwable $e) {

            error_log(
                'Error al consultar solicitudes pendientes: ' .
                $e->getMessage()
            );

            $errorSolicitudes = true;

        } finally {

            if ($bloqueoAdquirido) {

                flock($archivo, LOCK_UN);

            }

            fclose($archivo);

        }

    } else {

        $errorSolicitudes = true;

    }

} else {

    // Todavia no existen solicitudes registradas.
    $totalSolicitudesPendientes = 0;

}


// ==========================================
// 9. OBTENER MENSAJE TEMPORAL
// ==========================================

$mensajeFlash = obtenerMensaje();


// ==========================================
// 10. CARGAR CABECERA
// ==========================================

require_once __DIR__ . '/../includes/header.php';

?>

<!-- ====================================== -->
<!-- CONTENIDO PRINCIPAL -->
<!-- ====================================== -->

<div class="container-fluid py-4">

    <div class="row">

        <!-- ================================== -->
        <!-- MENU LATERAL -->
        <!-- ================================== -->

        <?php require __DIR__ . '/../includes/sidebar.php'; ?>


        <!-- ================================== -->
        <!-- PANEL ADMINISTRATIVO -->
        <!-- ================================== -->

        <main class="col-12 col-lg-9 col-xl-10">


            <!-- ENCABEZADO -->

            <div class="d-flex flex-column flex-md-row
                        justify-content-between align-items-md-center
                        gap-3 mb-4">

                <div>

                    <h2 class="fw-bold mb-1" style="color: #145c46;">

                        Panel administrativo

                    </h2>

                    <p class="text-muted mb-0">

                        Bienvenido,
                        <?= escapar($usuarioActual['nombre'] ?? 'Administrador') ?>.

                        Aqui puedes supervisar el funcionamiento
                        general de la pulperia.

                    </p>

                </div>

                <div class="text-md-end">

                    <span class="badge text-bg-success px-3 py-2">

                        <i class="bi bi-shield-check me-1"></i>

                        Administrador

                    </span>

                    <div class="text-muted small mt-2">

                        <?= date('d/m/Y') ?>

                    </div>

                </div>

            </div>


            <!-- MENSAJES -->

            <?php if ($mensajeFlash !== null): ?>

                <?php

                $tipoAlerta = match ($mensajeFlash['tipo'] ?? 'info') {

                    'success' => 'success',

                    'error' => 'danger',

                    'warning' => 'warning',

                    default => 'info'

                };

                ?>

                <div class="alert alert-<?= $tipoAlerta ?>">

                    <?= escapar($mensajeFlash['mensaje'] ?? '') ?>

                </div>

            <?php endif; ?>


            <?php if ($errorCarga): ?>

                <div class="alert alert-danger">

                    No fue posible cargar toda la informacion del panel.
                    Revisa los registros del servidor.

                </div>

            <?php endif; ?>


            <?php if ($errorSolicitudes): ?>

                <div class="alert alert-warning">

                    No fue posible consultar las solicitudes de recuperacion.

                </div>

            <?php endif; ?>


            <!-- ================================== -->
            <!-- TARJETAS DE RESUMEN -->
            <!-- ================================== -->

            <div class="row g-3 mb-4">


                <!-- PRODUCTOS -->

                <div class="col-12 col-sm-6 col-xl-4">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Productos registrados

                                    </span>

                                    <h3 class="fw-bold mb-0">

                                        <?= (int) $resumen['total_productos'] ?>

                                    </h3>

                                    <small class="text-muted">

                                        Productos activos

                                    </small>

                                </div>

                                <div class="dashboard-icon">

                                    <i class="bi bi-box-seam"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- VENTAS DEL DIA -->

                <div class="col-12 col-sm-6 col-xl-4">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Ventas de hoy

                                    </span>

                                    <h3 class="fw-bold mb-0">

                                        <?= (int) $ventasHoy['cantidad_ventas'] ?>

                                    </h3>

                                    <small class="text-muted">

                                        Ventas completadas

                                    </small>

                                </div>

                                <div class="dashboard-icon">

                                    <i class="bi bi-receipt"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- INGRESOS DEL DIA -->

                <div class="col-12 col-sm-6 col-xl-4">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Ingresos de hoy

                                    </span>

                                    <h3
                                        class="fw-bold mb-0"
                                        style="color: #145c46;"
                                    >

                                        <?= escapar(
                                            formatearMoneda(
                                                $ventasHoy['total_ventas']
                                            )
                                        ) ?>

                                    </h3>

                                    <small class="text-muted">

                                        Ventas completadas del dia

                                    </small>

                                </div>

                                <div class="dashboard-icon">

                                    <i class="bi bi-cash-stack"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- STOCK BAJO -->

                <div class="col-12 col-sm-6 col-xl-4">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Stock bajo

                                    </span>

                                    <h3 class="fw-bold text-warning mb-0">

                                        <?= (int) $resumen['productos_stock_bajo'] ?>

                                    </h3>

                                    <small class="text-muted">

                                        Necesitan reabastecimiento

                                    </small>

                                </div>

                                <div class="dashboard-icon">

                                    <i class="bi bi-exclamation-triangle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SIN STOCK -->

                <div class="col-12 col-sm-6 col-xl-4">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Sin existencias

                                    </span>

                                    <h3 class="fw-bold text-danger mb-0">

                                        <?= (int) $resumen['productos_sin_stock'] ?>

                                    </h3>

                                    <small class="text-muted">

                                        Productos agotados

                                    </small>

                                </div>

                                <div class="dashboard-icon">

                                    <i class="bi bi-box2"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- USUARIOS -->

                <div class="col-12 col-sm-6 col-xl-4">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Usuarios activos

                                    </span>

                                    <h3 class="fw-bold mb-0">

                                        <?= (int) $resumen['usuarios_activos'] ?>

                                    </h3>

                                    <small class="text-muted">

                                        Cuentas habilitadas

                                    </small>

                                </div>

                                <div class="dashboard-icon">

                                    <i class="bi bi-people"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ================================== -->
            <!-- SOLICITUDES Y ACCESOS RAPIDOS -->
            <!-- ================================== -->

            <div class="row g-3 mb-4">


                <!-- SOLICITUDES PENDIENTES -->

                <div class="col-12 col-lg-6">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body p-4">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <h5 class="fw-bold mb-1">

                                        Solicitudes de recuperacion

                                    </h5>

                                    <p class="text-muted mb-0 small">

                                        Solicitudes que requieren revision administrativa.

                                    </p>

                                </div>

                                <div style="font-size: 30px;">

                                    <i class="bi bi-bell"></i>

                                </div>

                            </div>


                            <div class="d-flex align-items-center justify-content-between mt-4">

                                <div>

                                    <h2
                                        class="fw-bold mb-0
                                        <?= $totalSolicitudesPendientes > 0
                                            ? 'text-warning'
                                            : 'text-success' ?>"
                                    >

                                        <?= $totalSolicitudesPendientes ?>

                                    </h2>

                                    <small class="text-muted">

                                        Solicitudes pendientes

                                    </small>

                                </div>


                                <a
                                    href="<?= escapar(urlSistema('admin/solicitudes.php')) ?>"
                                    class="btn btn-outline-success"
                                >

                                    Revisar solicitudes

                                    <i class="bi bi-arrow-right ms-1"></i>

                                </a>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- ACCESOS RAPIDOS -->

                <div class="col-12 col-lg-6">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body p-4">

                            <h5 class="fw-bold mb-3">

                                Accesos rapidos

                            </h5>


                            <div class="d-flex flex-wrap gap-2">

                                <a
                                    href="<?= escapar(urlSistema('admin/usuarios.php')) ?>"
                                    class="btn btn-outline-success"
                                >

                                    <i class="bi bi-people me-1"></i>

                                    Usuarios

                                </a>


                                <a
                                    href="<?= escapar(urlSistema('admin/crear_usuario.php')) ?>"
                                    class="btn btn-outline-success"
                                >

                                    <i class="bi bi-person-plus me-1"></i>

                                    Crear usuario

                                </a>


                                <a
                                    href="<?= escapar(urlSistema('admin/restablecer_password.php')) ?>"
                                    class="btn btn-outline-success"
                                >

                                    <i class="bi bi-key me-1"></i>

                                    Restablecer contrasenas

                                </a>


                                <a
                                    href="<?= escapar(urlSistema('cuenta.php')) ?>"
                                    class="btn btn-outline-secondary"
                                >

                                    <i class="bi bi-person-gear me-1"></i>

                                    Mi cuenta

                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ================================== -->
            <!-- ULTIMAS VENTAS -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3 mb-4">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center">

                        <h5 class="mb-0 fw-bold">

                            Ultimas ventas

                        </h5>

                        <span class="text-muted small">

                            Ultimos 8 registros

                        </span>

                    </div>

                </div>


                <div class="card-body">

                    <?php if (empty($ventasRecientes)): ?>

                        <div class="text-center py-4">

                            <div style="font-size: 40px;">

                                🧾

                            </div>

                            <h6 class="mt-2 fw-bold">

                                Todavia no hay ventas registradas.

                            </h6>

                            <p class="text-muted mb-0">

                                Cuando se realicen ventas, apareceran
                                en esta seccion.

                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th>Comprobante interno</th>

                                        <th>Fecha</th>

                                        <th>Cajero</th>

                                        <th>Metodo de pago</th>

                                        <th>Estado</th>

                                        <th class="text-end">Total</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($ventasRecientes as $venta): ?>

                                        <?php

                                        $estadoVenta = $venta['estado'];

                                        $colorEstado = (
                                            $estadoVenta === 'completada'
                                        )
                                            ? 'success'
                                            : 'danger';

                                        ?>

                                        <tr>

                                            <td>

                                                <strong>

                                                    <?= escapar(
                                                        generarNumeroComprobante(
                                                            (int) $venta['id_venta']
                                                        )
                                                    ) ?>

                                                </strong>

                                            </td>


                                            <td>

                                                <?= escapar(
                                                    formatearFechaHora(
                                                        $venta['fecha_venta']
                                                    )
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= escapar(
                                                    $venta['nombre_cajero']
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= escapar(
                                                    strtoupper($venta['metodo_pago'])
                                                ) ?>

                                            </td>


                                            <td>

                                                <span class="badge text-bg-<?= $colorEstado ?>">

                                                    <?= escapar(ucfirst($estadoVenta)) ?>

                                                </span>

                                            </td>


                                            <td class="text-end fw-semibold">

                                                <?= escapar(
                                                    formatearMoneda(
                                                        (float) $venta['total']
                                                    )
                                                ) ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- ================================== -->
            <!-- PRODUCTOS CON STOCK CRITICO -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold">

                        Productos que necesitan reabastecimiento

                    </h5>

                </div>


                <div class="card-body">

                    <?php if (empty($productosCriticos)): ?>

                        <div class="text-center py-4">

                            <div style="font-size: 40px;">

                                ✅

                            </div>

                            <h6 class="mt-2 fw-bold">

                                No hay productos con existencias criticas.

                            </h6>

                            <p class="text-muted mb-0">

                                El inventario se encuentra dentro
                                de los niveles minimos configurados.

                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th>Producto</th>

                                        <th>Codigo de barras</th>

                                        <th>Existencias</th>

                                        <th>Stock minimo</th>

                                        <th>Estado</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($productosCriticos as $producto): ?>

                                        <?php

                                        $sinStock = (
                                            (int) $producto['stock_actual'] === 0
                                        );

                                        ?>

                                        <tr>

                                            <td>

                                                <strong>

                                                    <?= escapar($producto['nombre']) ?>

                                                </strong>

                                            </td>


                                            <td>

                                                <?= escapar($producto['codigo_barras']) ?>

                                            </td>


                                            <td class="fw-bold">

                                                <?= (int) $producto['stock_actual'] ?>

                                            </td>


                                            <td>

                                                <?= (int) $producto['stock_minimo'] ?>

                                            </td>


                                            <td>

                                                <?php if ($sinStock): ?>

                                                    <span class="badge text-bg-danger">

                                                        Sin existencias

                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge text-bg-warning">

                                                        Stock bajo

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

            </div>


        </main>

    </div>

</div>


<!-- ====================================== -->
<!-- ESTILOS DEL PANEL ADMINISTRATIVO -->
<!-- ====================================== -->

<style>

    .dashboard-icon {

        width: 50px;

        height: 50px;

        border-radius: 12px;

        background-color: #e0f2e9;

        color: #145c46;

        display: flex;

        justify-content: center;

        align-items: center;

        font-size: 24px;

    }

</style>


<?php

// ==========================================
// 11. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
