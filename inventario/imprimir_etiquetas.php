<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador', 'cajero', 'almacen');

$tituloPagina = 'Imprimir etiquetas';
$paginaActual = 'inventario';

$errores = [];
$productosParaImprimir = [];

/**
 * Búsqueda.
 */
$buscar = trim((string) ($_GET['buscar'] ?? $_POST['buscar'] ?? ''));

if (strlen($buscar) > 100) {
    $buscar = substr($buscar, 0, 100);
    $errores[] = 'La búsqueda no puede superar los 100 caracteres.';
}

/**
 * Procesar selección de productos para imprimir.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $seleccionados = $_POST['productos_seleccionados'] ?? [];
    $cantidades = $_POST['cantidades'] ?? [];

    if (!is_array($seleccionados) || !is_array($cantidades)) {

        $errores[] = 'La selección de productos no es válida.';

    } elseif (empty($seleccionados)) {

        $errores[] = 'Selecciona al menos un producto para imprimir.';

    } else {

        $idsSeleccionados = [];
        $cantidadesPorProducto = [];
        $totalEtiquetas = 0;

        foreach ($seleccionados as $idSeleccionado) {

            if (!is_scalar($idSeleccionado)) {
                $errores[] = 'Se recibió un identificador de producto inválido.';
                continue;
            }

            $id = filter_var(
                $idSeleccionado,
                FILTER_VALIDATE_INT
            );

            if ($id === false || $id <= 0) {
                $errores[] = 'Uno de los productos seleccionados no es válido.';
                continue;
            }

            if (isset($cantidadesPorProducto[$id])) {
                continue;
            }

            $cantidad = filter_var(
                $cantidades[$id] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($cantidad === false || $cantidad < 1 || $cantidad > 100) {
                $errores[] = 'Cada producto debe tener entre 1 y 100 etiquetas.';
                continue;
            }

            $idsSeleccionados[] = $id;
            $cantidadesPorProducto[$id] = $cantidad;

            $totalEtiquetas += $cantidad;
        }

        if (count($idsSeleccionados) > 50) {
            $errores[] = 'Puedes seleccionar un máximo de 50 productos por impresión.';
        }

        if ($totalEtiquetas > 300) {
            $errores[] = 'Puedes preparar un máximo de 300 etiquetas por operación.';
        }

        if (
            empty($errores)
            && !empty($idsSeleccionados)
        ) {

            /**
             * Consultar únicamente productos existentes.
             * No se generan códigos nuevos.
             */
            $marcadores = [];
            $parametros = [];

            foreach ($idsSeleccionados as $indice => $id) {

                $nombreParametro = ':id_' . $indice;

                $marcadores[] = $nombreParametro;
                $parametros[$nombreParametro] = $id;
            }

            $sql = "
                SELECT
                    id_producto,
                    codigo_barras,
                    nombre,
                    estado
                FROM productos
                WHERE id_producto IN (" . implode(', ', $marcadores) . ")
            ";

            $stmtSeleccionados = $pdo->prepare($sql);

            foreach ($parametros as $nombreParametro => $valor) {
                $stmtSeleccionados->bindValue(
                    $nombreParametro,
                    $valor,
                    PDO::PARAM_INT
                );
            }

            $stmtSeleccionados->execute();

            $productosEncontrados = $stmtSeleccionados->fetchAll(PDO::FETCH_ASSOC);

            $productosPorId = [];

            foreach ($productosEncontrados as $producto) {
                $productosPorId[(int) $producto['id_producto']] = $producto;
            }

            /**
             * Conservar el orden de selección
             * y preparar las copias solicitadas.
             */
            foreach ($idsSeleccionados as $id) {

                if (!isset($productosPorId[$id])) {
                    $errores[] = 'Uno de los productos seleccionados ya no existe.';
                    continue;
                }

                $producto = $productosPorId[$id];

                $producto['cantidad_etiquetas'] = $cantidadesPorProducto[$id];

                $productosParaImprimir[] = $producto;
            }
        }
    }
}

/**
 * Paginación de productos.
 */
$porPagina = 30;

$pagina = filter_var(
    $_GET['pagina'] ?? 1,
    FILTER_VALIDATE_INT
);

$pagina = $pagina !== false && $pagina > 0 ? $pagina : 1;

/**
 * Condiciones de búsqueda.
 */
$where = '';
$parametrosBusqueda = [];

if ($buscar !== '') {

    $where = "
        WHERE nombre LIKE :buscar_nombre
        OR codigo_barras LIKE :buscar_codigo
    ";

    $parametrosBusqueda = [
        ':buscar_nombre' => '%' . $buscar . '%',
        ':buscar_codigo' => '%' . $buscar . '%'
    ];
}

/**
 * Contar productos.
 */
$stmtConteo = $pdo->prepare(
    "SELECT COUNT(*) FROM productos $where"
);

foreach ($parametrosBusqueda as $nombreParametro => $valor) {
    $stmtConteo->bindValue($nombreParametro, $valor);
}

$stmtConteo->execute();

$totalProductos = (int) $stmtConteo->fetchColumn();

$totalPaginas = max(
    1,
    (int) ceil($totalProductos / $porPagina)
);

if ($pagina > $totalPaginas) {
    $pagina = $totalPaginas;
}

$offset = ($pagina - 1) * $porPagina;

/**
 * Consultar productos para la tabla.
 *
 * Se incluyen los activos e inactivos porque también
 * puede ser necesario reimprimir una etiqueta antigua.
 */
$stmtProductos = $pdo->prepare(
    "SELECT
        id_producto,
        codigo_barras,
        nombre,
        categoria,
        stock_actual,
        estado
     FROM productos
     $where
     ORDER BY nombre ASC
     LIMIT :limite OFFSET :offset"
);

foreach ($parametrosBusqueda as $nombreParametro => $valor) {
    $stmtProductos->bindValue($nombreParametro, $valor);
}

$stmtProductos->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmtProductos->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmtProductos->execute();

$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

/**
 * Crear URL de paginación.
 */
$crearUrlPagina = static function (int $numeroPagina) use ($buscar): string {

    return urlSistema(
        'inventario/imprimir_etiquetas.php?' .
        http_build_query([
            'buscar' => $buscar,
            'pagina' => $numeroPagina
        ])
    );
};

require_once __DIR__ . '/../includes/header.php';

?>

<style>

    .etiqueta {

        box-sizing: border-box;
        width: 58mm;
        min-height: 31mm;
        padding: 2mm;

        background: #fff;
        border: 1px dashed #c8c8c8;

        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: center;

        text-align: center;
        overflow: hidden;
        break-inside: avoid;

    }

    .nombre-etiqueta {

        font-size: 9pt;
        font-weight: 600;

        max-height: 9mm;
        overflow: hidden;

        width: 100%;

    }

    .codigo-barras-svg {

        display: block;
        width: 100%;
        height: 13mm;

    }

    .codigo-texto {

        font-size: 8pt;
        letter-spacing: 1px;

    }

    .etiquetas-grid {

        display: grid;
        grid-template-columns: repeat(3, 58mm);
        gap: 3mm;
        justify-content: start;

    }

    @media print {

        @page {
            size: A4;
            margin: 7mm;
        }

        body * {
            visibility: hidden !important;
        }

        #area-etiquetas,
        #area-etiquetas * {
            visibility: visible !important;
        }

        #area-etiquetas {

            position: absolute;
            top: 0;
            left: 0;
            width: 100%;

            margin: 0;
            padding: 0;

        }

        .etiquetas-grid {

            display: grid;
            grid-template-columns: repeat(3, 58mm);
            gap: 3mm;

        }

        .etiqueta {

            border: 0.3mm solid #ccc;
            border-radius: 0;

        }

    }

</style>

<div class="container-fluid py-4">

    <div class="row">

        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

            <!-- ENCABEZADO -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">

                <div>

                    <h1 class="h3 mb-1">
                        Imprimir etiquetas
                    </h1>

                    <p class="text-muted mb-0">
                        Busca productos existentes y prepara sus etiquetas de códigos de barras.
                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                    class="btn btn-outline-secondary no-imprimir"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver al inventario
                </a>

            </div>

            <!-- ERRORES -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger no-imprimir" id="erroresEtiquetas">

                    <strong>No se pudo completar la operación:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach (array_unique($errores) as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>


            <!-- VISTA PREVIA DE ETIQUETAS -->
            <?php if (!empty($productosParaImprimir)): ?>

                <div class="card shadow-sm border-0 mb-4 no-imprimir">

                    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">

                        <div>

                            <h2 class="h5 mb-1">
                                <i class="bi bi-upc-scan text-success"></i>
                                Etiquetas preparadas
                            </h2>

                            <p class="text-muted mb-0">

                                <?= count($productosParaImprimir) ?>
                                productos seleccionados.

                                <?php
                                $totalEtiquetasPreparadas = array_sum(
                                    array_column($productosParaImprimir, 'cantidad_etiquetas')
                                );
                                ?>

                                <?= number_format($totalEtiquetasPreparadas) ?>
                                etiquetas en total.

                            </p>

                        </div>

                        <button
                            type="button"
                            class="btn btn-success"
                            onclick="imprimirEtiquetas()"
                        >
                            <i class="bi bi-printer"></i>
                            Imprimir etiquetas
                        </button>

                    </div>

                </div>

                <div id="area-etiquetas" class="mb-4">

                    <div class="etiquetas-grid">

                        <?php foreach ($productosParaImprimir as $producto): ?>

                            <?php
                            $cantidadCopias = (int) $producto['cantidad_etiquetas'];
                            ?>

                            <?php for ($i = 0; $i < $cantidadCopias; $i++): ?>

                                <div class="etiqueta">

                                    <div class="nombre-etiqueta">
                                        <?= escapar($producto['nombre']) ?>
                                    </div>

                                    <svg
                                        class="codigo-barras-svg"
                                        data-code="<?= escapar($producto['codigo_barras']) ?>"
                                    ></svg>

                                    <div class="codigo-texto">
                                        <?= escapar($producto['codigo_barras']) ?>
                                    </div>

                                </div>

                            <?php endfor; ?>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endif; ?>


            <!-- BUSCADOR -->
            <div class="card shadow-sm border-0 mb-4 no-imprimir">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-0">
                        <i class="bi bi-search"></i>
                        Buscar productos
                    </h2>

                </div>

                <div class="card-body">

                    <form method="GET" action="">

                        <div class="row g-3">

                            <div class="col-md-9">

                                <label for="buscar" class="form-label">
                                    Nombre o código de barras
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="buscar"
                                    name="buscar"
                                    maxlength="100"
                                    value="<?= escapar($buscar) ?>"
                                    placeholder="Escribe el nombre o código del producto"
                                >

                            </div>

                            <div class="col-md-3 d-flex align-items-end gap-2">

                                <button
                                    type="submit"
                                    class="btn btn-success flex-grow-1"
                                >
                                    <i class="bi bi-search"></i>
                                    Buscar
                                </button>

                                <a
                                    href="<?= escapar(urlSistema('inventario/imprimir_etiquetas.php')) ?>"
                                    class="btn btn-outline-secondary"
                                    title="Limpiar búsqueda"
                                >
                                    <i class="bi bi-x-circle"></i>
                                </a>

                            </div>

                        </div>

                    </form>

                </div>

            </div>


            <!-- TABLA DE PRODUCTOS -->
            <div class="card shadow-sm border-0 mb-4 no-imprimir">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <h2 class="h5 mb-0">
                            Productos registrados
                        </h2>

                        <span class="badge bg-success">
                            <?= number_format($totalProductos) ?> productos
                        </span>

                    </div>

                </div>

                <div class="card-body p-0">

                    <?php if (empty($productos)): ?>

                        <div class="text-center py-5">

                            <i class="bi bi-search display-4 text-muted"></i>

                            <h5 class="mt-3">
                                No se encontraron productos
                            </h5>

                            <p class="text-muted mb-0">
                                Prueba con otro nombre o código de barras.
                            </p>

                        </div>

                    <?php else: ?>

                        <form method="POST" action="" id="formPrepararEtiquetas">

                            <?= campoTokenCSRF() ?>

                            <input
                                type="hidden"
                                name="buscar"
                                value="<?= escapar($buscar) ?>"
                            >

                            <div class="table-responsive">

                                <table class="table table-hover align-middle mb-0">

                                    <thead class="table-light">

                                        <tr>

                                            <th class="text-center" style="width: 55px;">
                                                <input
                                                    type="checkbox"
                                                    class="form-check-input"
                                                    id="seleccionar_todos"
                                                    title="Seleccionar todos"
                                                >
                                            </th>

                                            <th>Producto</th>

                                            <th>Código de barras</th>

                                            <th class="text-center">
                                                Existencias
                                            </th>

                                            <th class="text-center">
                                                Estado
                                            </th>

                                            <th style="width: 130px;">
                                                Etiquetas
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach ($productos as $producto): ?>

                                            <tr>

                                                <td class="text-center">

                                                    <input
                                                        type="checkbox"
                                                        class="form-check-input seleccionar-producto"
                                                        name="productos_seleccionados[]"
                                                        value="<?= (int) $producto['id_producto'] ?>"
                                                    >

                                                </td>

                                                <td>

                                                    <strong>
                                                        <?= escapar($producto['nombre']) ?>
                                                    </strong>

                                                    <?php if (!empty($producto['categoria'])): ?>

                                                        <div class="small text-muted">
                                                            <?= escapar($producto['categoria']) ?>
                                                        </div>

                                                    <?php endif; ?>

                                                </td>

                                                <td>
                                                    <code>
                                                        <?= escapar($producto['codigo_barras']) ?>
                                                    </code>
                                                </td>

                                                <td class="text-center">
                                                    <?= number_format((int) $producto['stock_actual']) ?>
                                                </td>

                                                <td class="text-center">

                                                    <?php if ((int) $producto['estado'] === 1): ?>

                                                        <span class="badge bg-success">
                                                            Activo
                                                        </span>

                                                    <?php else: ?>

                                                        <span class="badge bg-secondary">
                                                            Inactivo
                                                        </span>

                                                    <?php endif; ?>

                                                </td>

                                                <td>

                                                    <input
                                                        type="number"
                                                        name="cantidades[<?= (int) $producto['id_producto'] ?>]"
                                                        class="form-control form-control-sm"
                                                        min="1"
                                                        max="100"
                                                        value="1"
                                                        title="Cantidad de etiquetas"
                                                    >

                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                            <div class="card-footer bg-white py-3">

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                                    <span class="small text-muted">
                                        Selecciona los productos e indica cuántas etiquetas necesitas de cada uno.
                                    </span>

                                    <button
                                        type="submit"
                                        class="btn btn-success"
                                    >
                                        <i class="bi bi-upc-scan"></i>
                                        Preparar etiquetas
                                    </button>

                                </div>

                            </div>

                        </form>

                    <?php endif; ?>

                </div>

                <!-- PAGINACIÓN -->
                <?php if ($totalPaginas > 1): ?>

                    <div class="card-footer bg-white no-imprimir">

                        <nav aria-label="Paginación de productos">

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

<!-- Biblioteca para generar visualmente los códigos de barras -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const formulario = document.getElementById('formPrepararEtiquetas');

    const erroresEtiquetas = document.getElementById('erroresEtiquetas');

    const areaEtiquetas = document.getElementById('area-etiquetas');

    const codigosBarras = document.querySelectorAll('.codigo-barras-svg');

    /*
    |--------------------------------------------------------------------------
    | Función general para mostrar alertas
    |--------------------------------------------------------------------------
    */

    function mostrarAlerta(tipo, titulo, mensaje) {

        if (typeof Swal !== 'undefined') {

            return Swal.fire({

                icon: tipo,

                title: titulo,

                text: mensaje,

                confirmButtonText: 'Entendido',

                confirmButtonColor: '#198754'

            });

        }

        console.error(titulo + ': ' + mensaje);

    }

    /*
    |--------------------------------------------------------------------------
    | Escapar texto para mostrarlo dentro de HTML
    |--------------------------------------------------------------------------
    */

    function escaparHTML(texto) {

        const elemento = document.createElement('div');

        elemento.textContent = texto;

        return elemento.innerHTML;

    }

    /*
    |--------------------------------------------------------------------------
    | Mostrar errores enviados por PHP
    |--------------------------------------------------------------------------
    */

    if (erroresEtiquetas && typeof Swal !== 'undefined') {

        const errores = Array.from(
            erroresEtiquetas.querySelectorAll('li')
        ).map(function (elemento) {

            return elemento.textContent.trim();

        }).filter(Boolean);

        if (errores.length > 0) {

            const listaHTML = `
                <ul class="text-start mb-0">
                    ${errores.map(function (error) {
                        return '<li>' + escaparHTML(error) + '</li>';
                    }).join('')}
                </ul>
            `;

            erroresEtiquetas.classList.add('d-none');

            Swal.fire({

                icon: 'error',

                title: 'No se pudo preparar las etiquetas',

                html: listaHTML,

                confirmButtonText: 'Corregir selección',

                confirmButtonColor: '#198754'

            });

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Seleccionar o deseleccionar todos los productos
    |--------------------------------------------------------------------------
    */

    const seleccionarTodos = document.getElementById('seleccionar_todos');

    if (seleccionarTodos) {

        seleccionarTodos.addEventListener('change', function () {

            const seleccionados = document.querySelectorAll(
                '.seleccionar-producto'
            );

            seleccionados.forEach(function (checkbox) {

                checkbox.checked = seleccionarTodos.checked;

            });

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Confirmar preparación de etiquetas
    |--------------------------------------------------------------------------
    */

    if (formulario) {

        let preparacionConfirmada = false;

        let confirmacionEnCurso = false;

        formulario.addEventListener('submit', function (evento) {

            if (preparacionConfirmada) {
                return;
            }

            if (typeof Swal === 'undefined') {
                return;
            }

            evento.preventDefault();

            if (confirmacionEnCurso) {
                return;
            }

            const productosSeleccionados = Array.from(
                formulario.querySelectorAll('.seleccionar-producto:checked')
            );

            if (productosSeleccionados.length === 0) {

                mostrarAlerta(
                    'warning',
                    'No hay productos seleccionados',
                    'Selecciona al menos un producto para preparar sus etiquetas.'
                );

                return;

            }

            if (productosSeleccionados.length > 50) {

                mostrarAlerta(
                    'warning',
                    'Demasiados productos',
                    'Puedes seleccionar un máximo de 50 productos por impresión.'
                );

                return;

            }

            let totalEtiquetas = 0;

            let cantidadInvalida = false;

            /*
             * Calcular la cantidad total de etiquetas.
             */

            productosSeleccionados.forEach(function (checkbox) {

                const idProducto = checkbox.value;

                const campoCantidad = Array.from(
                    formulario.querySelectorAll('input[name^="cantidades["]')
                ).find(function (campo) {

                    return campo.name === 'cantidades[' + idProducto + ']';

                });

                const cantidad = campoCantidad
                    ? Number(campoCantidad.value)
                    : NaN;

                if (
                    !Number.isInteger(cantidad)
                    || cantidad < 1
                    || cantidad > 100
                ) {

                    cantidadInvalida = true;

                    return;

                }

                totalEtiquetas += cantidad;

            });

            if (cantidadInvalida) {

                mostrarAlerta(
                    'warning',
                    'Cantidad de etiquetas no válida',
                    'Cada producto debe tener entre 1 y 100 etiquetas.'
                );

                return;

            }

            if (totalEtiquetas > 300) {

                mostrarAlerta(
                    'warning',
                    'Se supera el límite de impresión',
                    'Puedes preparar un máximo de 300 etiquetas por operación.'
                );

                return;

            }

            confirmacionEnCurso = true;

            Swal.fire({

                icon: 'question',

                title: '¿Preparar las etiquetas?',

                html: `
                    <p>Vas a preparar etiquetas para:</p>

                    <h4 class="text-success">
                        ${productosSeleccionados.length} productos
                    </h4>

                    <p class="mb-1">Cantidad total de etiquetas:</p>

                    <h3>${totalEtiquetas}</h3>

                    <p class="text-muted mb-0">
                        Se utilizarán los códigos de barras que ya están registrados.
                    </p>
                `,

                showCancelButton: true,

                confirmButtonText: 'Sí, preparar etiquetas',

                cancelButtonText: 'Cancelar',

                confirmButtonColor: '#198754',

                cancelButtonColor: '#6c757d',

                reverseButtons: true,

                allowOutsideClick: false

            }).then(function (respuesta) {

                confirmacionEnCurso = false;

                if (!respuesta.isConfirmed) {
                    return;
                }

                preparacionConfirmada = true;

                const botonPreparar = formulario.querySelector(
                    'button[type="submit"]'
                );

                if (botonPreparar) {

                    botonPreparar.disabled = true;

                    botonPreparar.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-1"></span> Preparando etiquetas...';

                }

                formulario.requestSubmit();

            });

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Generar visualmente los códigos de barras
    |--------------------------------------------------------------------------
    */

    let huboErrorCodigo = false;

    if (
        codigosBarras.length > 0
        && typeof JsBarcode === 'undefined'
    ) {

        huboErrorCodigo = true;

        mostrarAlerta(
            'error',
            'No se pudo cargar JsBarcode',
            'Comprueba tu conexión a Internet e intenta nuevamente.'
        );

    } else {

        codigosBarras.forEach(function (svg) {

            try {

                JsBarcode(svg, svg.dataset.code, {

                    format: 'CODE128',

                    lineColor: '#000000',

                    width: 1.5,

                    height: 42,

                    displayValue: false,

                    margin: 0

                });

            } catch (error) {

                huboErrorCodigo = true;

                console.error(
                    'No se pudo generar el código de barras:',
                    error
                );

            }

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Notificar que las etiquetas están preparadas
    |--------------------------------------------------------------------------
    */

    if (
        areaEtiquetas
        && codigosBarras.length > 0
        && !huboErrorCodigo
        && typeof Swal !== 'undefined'
    ) {

        Swal.fire({

            toast: true,

            position: 'top-end',

            icon: 'success',

            title: codigosBarras.length + ' etiquetas preparadas correctamente',

            showConfirmButton: false,

            timer: 3000,

            timerProgressBar: true

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Imprimir únicamente las etiquetas preparadas
    |--------------------------------------------------------------------------
    */

    window.imprimirEtiquetas = function () {

        if (
            codigosBarras.length === 0
            || typeof JsBarcode === 'undefined'
        ) {

            mostrarAlerta(
                'error',
                'No se pueden imprimir las etiquetas',
                'No se encontraron códigos de barras preparados o la biblioteca no está disponible.'
            );

            return;

        }

        const codigosSinGenerar = Array.from(codigosBarras).some(
            function (svg) {

                return !svg.innerHTML.trim();

            }
        );

        if (codigosSinGenerar) {

            mostrarAlerta(
                'error',
                'Hay códigos de barras sin generar',
                'No se puede continuar con la impresión. Comprueba los códigos de los productos.'
            );

            return;

        }

        // Abrir el diálogo de impresión del navegador.
        window.print();

    };

});

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
