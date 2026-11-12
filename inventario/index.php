<?php

/**
 * MODULO DE INVENTARIO
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite consultar y filtrar los productos registrados.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

/** @var PDO $pdo */

// Permitir acceso a administradores y almacen.
requerirRol('administrador', 'almacen');

$tituloPagina = 'Inventario';


// ==========================================
// 2. VARIABLES INICIALES
// ==========================================

$errorCarga = false;

$productos = [];

$categorias = [];

$totalResultados = 0;

$porPagina = 15;


// ==========================================
// 3. OBTENER FILTROS
// ==========================================

$buscarEntrada = $_GET['buscar'] ?? '';

$categoriaEntrada = $_GET['categoria'] ?? '';

$estadoEntrada = $_GET['estado'] ?? '1';

$stockEntrada = $_GET['stock'] ?? '';


$buscar = is_string($buscarEntrada)
    ? trim($buscarEntrada)
    : '';

$categoriaFiltro = is_string($categoriaEntrada)
    ? trim($categoriaEntrada)
    : '';

$estadoFiltro = is_string($estadoEntrada)
    ? $estadoEntrada
    : '1';

$stockFiltro = is_string($stockEntrada)
    ? $stockEntrada
    : '';


// Limitar longitud de busqueda.

$buscar = substr($buscar, 0, 150);

$categoriaFiltro = substr($categoriaFiltro, 0, 100);


// Validar filtro de estado.

if (!in_array($estadoFiltro, ['', '0', '1'], true)) {

    $estadoFiltro = '1';

}


// Validar filtro de existencias.

$stocksPermitidos = [
    '',
    'bajo',
    'agotado',
    'disponible'
];

if (!in_array($stockFiltro, $stocksPermitidos, true)) {

    $stockFiltro = '';

}


// ==========================================
// 4. CONTROL DE PAGINACION
// ==========================================

$paginaEntrada = $_GET['pagina'] ?? '1';

$pagina = filter_var(
    $paginaEntrada,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($pagina === false) {

    $pagina = 1;

}


// ==========================================
// 5. CONSULTAR INFORMACION DEL INVENTARIO
// ==========================================

$resumen = [

    'total_productos' => 0,

    'productos_disponibles' => 0,

    'productos_stock_bajo' => 0,

    'productos_sin_stock' => 0

];


try {

    // ==========================================
    // 5.1 CONSULTAR RESUMEN GENERAL
    // ==========================================

    $consultaResumen = $pdo->query(
        "SELECT

            COUNT(*) AS total_productos,

            COALESCE(
                SUM(
                    CASE
                        WHEN stock_actual > stock_minimo
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS productos_disponibles,

            COALESCE(
                SUM(
                    CASE
                        WHEN stock_actual > 0
                        AND stock_actual <= stock_minimo
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS productos_stock_bajo,

            COALESCE(
                SUM(
                    CASE
                        WHEN stock_actual = 0
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS productos_sin_stock

         FROM productos

         WHERE estado = 1"
    );

    $resumen = $consultaResumen->fetch();


    // ==========================================
    // 5.2 CONSULTAR CATEGORIAS EXISTENTES
    // ==========================================

    $consultaCategorias = $pdo->query(
        "SELECT DISTINCT categoria

         FROM productos

         WHERE categoria IS NOT NULL

         AND TRIM(categoria) <> ''

         ORDER BY categoria ASC"
    );

    $categorias = $consultaCategorias->fetchAll();


    // ==========================================
    // 5.3 CONSTRUIR CONSULTA DE PRODUCTOS
    // ==========================================

    $sqlCondiciones = [];

    $parametros = [];


    // Filtrar por nombre o codigo de barras.

    if ($buscar !== '') {

        $sqlCondiciones[] = "
            (
                nombre LIKE :buscar_nombre

                OR codigo_barras LIKE :buscar_codigo
            )
        ";

        $parametros['buscar_nombre'] = '%' . $buscar . '%';

        $parametros['buscar_codigo'] = '%' . $buscar . '%';

    }


    // Filtrar por categoria.

    if ($categoriaFiltro !== '') {

        $sqlCondiciones[] = 'categoria = :categoria';

        $parametros['categoria'] = $categoriaFiltro;

    }


    // Filtrar por estado.

    if ($estadoFiltro !== '') {

        $sqlCondiciones[] = 'estado = :estado';

        $parametros['estado'] = (int) $estadoFiltro;

    }


    // Filtrar por nivel de existencias.

    if ($stockFiltro === 'agotado') {

        $sqlCondiciones[] = 'stock_actual = 0';

    } elseif ($stockFiltro === 'bajo') {

        $sqlCondiciones[] = '
            stock_actual > 0
            AND stock_actual <= stock_minimo
        ';

    } elseif ($stockFiltro === 'disponible') {

        $sqlCondiciones[] = 'stock_actual > stock_minimo';

    }


    // Construir WHERE.

    $whereSql = '';

    if (!empty($sqlCondiciones)) {

        $whereSql = 'WHERE ' . implode(
            ' AND ',
            $sqlCondiciones
        );

    }


    // ==========================================
    // 5.4 CONTAR RESULTADOS
    // ==========================================

    $sqlTotal = "
        SELECT COUNT(*)

        FROM productos

        $whereSql
    ";

    $consultaTotal = $pdo->prepare($sqlTotal);

    $consultaTotal->execute($parametros);

    $totalResultados = (int) $consultaTotal->fetchColumn();


    // Calcular cantidad de paginas.

    $totalPaginas = max(
        1,
        (int) ceil($totalResultados / $porPagina)
    );


    // Evitar solicitar paginas inexistentes.

    if ($pagina > $totalPaginas) {

        $pagina = $totalPaginas;

    }


    $offset = ($pagina - 1) * $porPagina;


    // ==========================================
    // 5.5 CONSULTAR PRODUCTOS
    // ==========================================

    $sqlProductos = "
        SELECT

            id_producto,

            codigo_barras,

            nombre,

            categoria,

            costo,

            precio_venta,

            stock_actual,

            stock_minimo,

            estado,

            fecha_creacion

        FROM productos

        $whereSql

        ORDER BY

            CASE

                WHEN stock_actual = 0 THEN 1

                WHEN stock_actual <= stock_minimo THEN 2

                ELSE 3

            END,

            nombre ASC

        LIMIT $porPagina

        OFFSET $offset
    ";


    $consultaProductos = $pdo->prepare($sqlProductos);

    $consultaProductos->execute($parametros);

    $productos = $consultaProductos->fetchAll();


} catch (Throwable $e) {

    error_log(
        'Error al consultar inventario: ' .
        $e->getMessage()
    );

    $errorCarga = true;

}


// ==========================================
// 6. PARAMETROS PARA PAGINACION
// ==========================================

$parametrosPaginacion = [

    'buscar' => $buscar,

    'categoria' => $categoriaFiltro,

    'estado' => $estadoFiltro,

    'stock' => $stockFiltro

];


// ==========================================
// 7. CARGAR CABECERA
// ==========================================

require_once __DIR__ . '/../includes/header.php';

?>

<!-- ====================================== -->
<!-- CONTENIDO PRINCIPAL -->
<!-- ====================================== -->

<div class="container-fluid py-4">

    <div class="row">

        <!-- MENU LATERAL -->

        <?php require __DIR__ . '/../includes/sidebar.php'; ?>


        <!-- CONTENIDO -->

        <main class="col-12 col-lg-9 col-xl-10">


            <!-- ================================== -->
            <!-- ENCABEZADO -->
            <!-- ================================== -->

            <div class="d-flex flex-column flex-md-row
                        justify-content-between align-items-md-center
                        gap-3 mb-4">

                <div>

                    <h2
                        class="fw-bold mb-1"
                        style="color: #145c46;"
                    >

                        Control de inventario

                    </h2>

                    <p class="text-muted mb-0">

                        Consulta y supervisa los productos
                        registrados en la pulperia.

                    </p>

                </div>


                <div>

                    <span class="badge text-bg-success px-3 py-2">

                        <i class="bi bi-box-seam me-1"></i>

                        Modulo de inventario

                    </span>

                </div>

            </div>


            <!-- ================================== -->
            <!-- ERRORES -->
            <!-- ================================== -->

            <?php if ($errorCarga): ?>

                <div class="alert alert-danger">

                    No fue posible cargar los datos del inventario.
                    Verifica la conexion con MySQL.

                </div>

            <?php endif; ?>


            <!-- ================================== -->
            <!-- RESUMEN DE INVENTARIO -->
            <!-- ================================== -->

            <div class="row g-3 mb-4">


                <!-- TOTAL PRODUCTOS -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Total de productos

                                    </span>

                                    <h3 class="fw-bold mb-0">

                                        <?= (int) $resumen['total_productos'] ?>

                                    </h3>

                                </div>

                                <div class="inventory-icon">

                                    <i class="bi bi-box-seam"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- DISPONIBLES -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Disponibles

                                    </span>

                                    <h3 class="fw-bold text-success mb-0">

                                        <?= (int) $resumen['productos_disponibles'] ?>

                                    </h3>

                                </div>

                                <div class="inventory-icon text-success">

                                    <i class="bi bi-check-circle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- STOCK BAJO -->

                <div class="col-12 col-sm-6 col-xl-3">

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

                                </div>

                                <div class="inventory-icon text-warning">

                                    <i class="bi bi-exclamation-triangle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- AGOTADOS -->

                <div class="col-12 col-sm-6 col-xl-3">

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

                                </div>

                                <div class="inventory-icon text-danger">

                                    <i class="bi bi-x-circle"></i>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


            </div>


            <!-- ================================== -->
            <!-- FILTROS DE BUSQUEDA -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3 mb-4">

                <div class="card-body p-4">

                    <h5 class="fw-bold mb-3">

                        <i class="bi bi-search me-2"></i>

                        Buscar productos

                    </h5>


                    <form method="GET" action="">


                        <div class="row g-3">


                            <!-- BUSQUEDA -->

                            <div class="col-12 col-md-6 col-xl-3">

                                <label class="form-label">

                                    Nombre o codigo de barras

                                </label>

                                <input
                                    type="search"
                                    name="buscar"
                                    class="form-control"
                                    value="<?= escapar($buscar) ?>"
                                    maxlength="150"
                                    placeholder="Buscar producto..."
                                >

                            </div>


                            <!-- CATEGORIA -->

                            <div class="col-12 col-md-6 col-xl-3">

                                <label class="form-label">

                                    Categoria

                                </label>

                                <select
                                    name="categoria"
                                    class="form-select"
                                >

                                    <option value="">

                                        Todas las categorias

                                    </option>


                                    <?php foreach ($categorias as $categoria): ?>

                                        <?php

                                        $nombreCategoria = $categoria['categoria'];

                                        ?>

                                        <option
                                            value="<?= escapar($nombreCategoria) ?>"
                                            <?= $categoriaFiltro === $nombreCategoria ? 'selected' : '' ?>
                                        >

                                            <?= escapar($nombreCategoria) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <!-- ESTADO -->

                            <div class="col-12 col-md-6 col-xl-2">

                                <label class="form-label">

                                    Estado del producto

                                </label>

                                <select
                                    name="estado"
                                    class="form-select"
                                >

                                    <option value="1"
                                        <?= $estadoFiltro === '1' ? 'selected' : '' ?>
                                    >

                                        Activos

                                    </option>

                                    <option value="0"
                                        <?= $estadoFiltro === '0' ? 'selected' : '' ?>
                                    >

                                        Inactivos

                                    </option>

                                    <option value=""
                                        <?= $estadoFiltro === '' ? 'selected' : '' ?>
                                    >

                                        Todos

                                    </option>

                                </select>

                            </div>


                            <!-- EXISTENCIAS -->

                            <div class="col-12 col-md-6 col-xl-2">

                                <label class="form-label">

                                    Existencias

                                </label>

                                <select
                                    name="stock"
                                    class="form-select"
                                >

                                    <option value="">

                                        Todas

                                    </option>

                                    <option
                                        value="disponible"
                                        <?= $stockFiltro === 'disponible' ? 'selected' : '' ?>
                                    >

                                        Disponibles

                                    </option>

                                    <option
                                        value="bajo"
                                        <?= $stockFiltro === 'bajo' ? 'selected' : '' ?>
                                    >

                                        Stock bajo

                                    </option>

                                    <option
                                        value="agotado"
                                        <?= $stockFiltro === 'agotado' ? 'selected' : '' ?>
                                    >

                                        Agotados

                                    </option>

                                </select>

                            </div>


                            <!-- BOTONES -->

                            <div class="col-12 col-xl-2 d-flex align-items-end">

                                <div class="d-flex gap-2 w-100">

                                    <button
                                        type="submit"
                                        class="btn btn-success flex-grow-1"
                                    >

                                        Buscar

                                    </button>

                                    <a
                                        href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                                        class="btn btn-outline-secondary"
                                        title="Limpiar filtros"
                                    >

                                        <i class="bi bi-arrow-counterclockwise"></i>

                                    </a>

                                </div>

                            </div>


                        </div>

                    </form>

                </div>

            </div>


            <!-- ================================== -->
            <!-- TABLA DE PRODUCTOS -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white py-3">

                    <div class="d-flex flex-column flex-sm-row
                                justify-content-between align-items-sm-center gap-2">

                        <h5 class="mb-0 fw-bold">

                            Productos registrados

                        </h5>

                        <span class="badge text-bg-success">

                            <?= $totalResultados ?> resultados

                        </span>

                    </div>

                </div>


                <div class="card-body">


                    <?php if (empty($productos)): ?>


                        <div class="text-center py-5">

                            <div style="font-size: 45px;">

                                📦

                            </div>

                            <h5 class="mt-3 fw-bold">

                                No se encontraron productos.

                            </h5>

                            <p class="text-muted mb-0">

                                Todavia no hay productos registrados
                                o los filtros no encontraron resultados.

                            </p>

                        </div>


                    <?php else: ?>


                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead class="table-light">

                                    <tr>

                                        <th>Producto</th>

                                        <th>Codigo de barras</th>

                                        <th>Categoria</th>

                                        <th>Precio de venta</th>

                                        <th>Existencias</th>

                                        <th>Stock minimo</th>

                                        <th>Estado</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($productos as $producto): ?>

                                        <?php

                                        $stockActual = (int) $producto['stock_actual'];

                                        $stockMinimo = (int) $producto['stock_minimo'];

                                        $estadoInventario = obtenerEstadoInventario(
                                            $stockActual,
                                            $stockMinimo
                                        );


                                        $configEstado = match ($estadoInventario) {

                                            'sin_stock' => [
                                                'color' => 'danger',
                                                'texto' => 'Sin existencias'
                                            ],

                                            'stock_bajo' => [
                                                'color' => 'warning',
                                                'texto' => 'Stock bajo'
                                            ],

                                            default => [
                                                'color' => 'success',
                                                'texto' => 'Disponible'
                                            ]

                                        };


                                        ?>

                                        <tr>


                                            <!-- PRODUCTO -->

                                            <td>

                                                <strong>

                                                    <?= escapar($producto['nombre']) ?>

                                                </strong>

                                                <br>

                                                <small class="text-muted">

                                                    ID: #<?= (int) $producto['id_producto'] ?>

                                                </small>

                                            </td>


                                            <!-- CODIGO DE BARRAS -->

                                            <td>

                                                <code>

                                                    <?= escapar($producto['codigo_barras']) ?>

                                                </code>

                                            </td>


                                            <!-- CATEGORIA -->

                                            <td>

                                                <?= escapar(
                                                    $producto['categoria'] ?: 'Sin categoria'
                                                ) ?>

                                            </td>


                                            <!-- PRECIO -->

                                            <td class="fw-semibold">

                                                <?= escapar(
                                                    formatearMoneda(
                                                        $producto['precio_venta']
                                                    )
                                                ) ?>

                                            </td>


                                            <!-- EXISTENCIAS -->

                                            <td class="fw-bold">

                                                <?= $stockActual ?>

                                            </td>


                                            <!-- STOCK MINIMO -->

                                            <td>

                                                <?= $stockMinimo ?>

                                            </td>


                                            <!-- ESTADO -->

                                            <td>

                                                <span class="badge text-bg-<?= $configEstado['color'] ?>">

                                                    <?= escapar($configEstado['texto']) ?>

                                                </span>

                                                <?php if ((int) $producto['estado'] === 0): ?>

                                                    <br>

                                                    <span class="badge text-bg-secondary mt-1">

                                                        Producto inactivo

                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                            <!-- EDITAR -->
                                            <td class="text-end">
                                                <a
                                                    href="<?= escapar(urlSistema('inventario/editar_producto.php?id=' . (int) $producto['id_producto'])) ?>"
                                                    class="btn btn-sm btn-outline-success"
                                                    title="Editar producto"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                    Editar
                                                </a>
                                            </td>


                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>


                        <!-- ================================== -->
                        <!-- PAGINACION -->
                        <!-- ================================== -->

                        <?php if ($totalPaginas > 1): ?>

                            <nav
                                class="mt-4"
                                aria-label="Paginacion de productos"
                            >

                                <ul class="pagination justify-content-center flex-wrap">


                                    <!-- ANTERIOR -->

                                    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>">

                                        <?php

                                        $urlAnterior = urlSistema(
                                            'inventario/index.php?' .
                                            http_build_query(
                                                array_merge(
                                                    $parametrosPaginacion,
                                                    ['pagina' => max(1, $pagina - 1)]
                                                )
                                            )
                                        );

                                        ?>

                                        <a
                                            class="page-link"
                                            href="<?= escapar($urlAnterior) ?>"
                                        >

                                            Anterior

                                        </a>

                                    </li>


                                    <!-- NUMEROS -->

                                    <?php for (
                                        $i = max(1, $pagina - 2);
                                        $i <= min($totalPaginas, $pagina + 2);
                                        $i++
                                    ): ?>

                                        <?php

                                        $urlPagina = urlSistema(
                                            'inventario/index.php?' .
                                            http_build_query(
                                                array_merge(
                                                    $parametrosPaginacion,
                                                    ['pagina' => $i]
                                                )
                                            )
                                        );

                                        ?>

                                        <li class="page-item <?= $pagina === $i ? 'active' : '' ?>">

                                            <a
                                                class="page-link"
                                                href="<?= escapar($urlPagina) ?>"
                                            >

                                                <?= $i ?>

                                            </a>

                                        </li>

                                    <?php endfor; ?>


                                    <!-- SIGUIENTE -->

                                    <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">

                                        <?php

                                        $urlSiguiente = urlSistema(
                                            'inventario/index.php?' .
                                            http_build_query(
                                                array_merge(
                                                    $parametrosPaginacion,
                                                    ['pagina' => min($totalPaginas, $pagina + 1)]
                                                )
                                            )
                                        );

                                        ?>

                                        <a
                                            class="page-link"
                                            href="<?= escapar($urlSiguiente) ?>"
                                        >

                                            Siguiente

                                        </a>

                                    </li>


                                </ul>

                            </nav>

                        <?php endif; ?>


                    <?php endif; ?>


                </div>

            </div>

        </main>

    </div>

</div>


<!-- ====================================== -->
<!-- ESTILOS DEL MODULO -->
<!-- ====================================== -->

<style>

    .inventory-icon {

        width: 48px;

        height: 48px;

        border-radius: 12px;

        background-color: #e0f2e9;

        color: #145c46;

        display: flex;

        justify-content: center;

        align-items: center;

        font-size: 23px;

    }

</style>


<?php

// ==========================================
// 8. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
