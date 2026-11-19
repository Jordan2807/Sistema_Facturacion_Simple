<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador', 'almacen');

$tituloPagina = 'Ajustar existencias';
$paginaActual = 'inventario';

$errores = [];

$idProductoSeleccionado = '';
$stockFisico = '';
$observaciones = '';

$usuarioActual = obtenerUsuarioActual();
$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);

/**
 * Procesar el formulario.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $idEntrada = $_POST['id_producto'] ?? '';
    $stockEntrada = $_POST['stock_fisico'] ?? '';
    $observacionesEntrada = $_POST['observaciones'] ?? '';

    $idProductoSeleccionado = is_scalar($idEntrada)
        ? trim((string) $idEntrada)
        : '';

    $stockFisico = is_scalar($stockEntrada)
        ? trim((string) $stockEntrada)
        : '';

    $observaciones = is_scalar($observacionesEntrada)
        ? trim((string) $observacionesEntrada)
        : '';

    // Validar usuario.
    if ($idUsuario <= 0) {
        $errores[] = 'No se pudo identificar al usuario que realiza el ajuste.';
    }

    // Validar producto.
    $idProductoValidado = filter_var(
        $idProductoSeleccionado,
        FILTER_VALIDATE_INT
    );

    if ($idProductoValidado === false || $idProductoValidado <= 0) {
        $errores[] = 'Selecciona un producto válido.';
    }

    // Validar existencias físicas.
    $stockFisicoValidado = filter_var(
        $stockFisico,
        FILTER_VALIDATE_INT
    );

    if (
        $stockFisicoValidado === false
        || $stockFisicoValidado < 0
        || $stockFisicoValidado > 2147483647
    ) {
        $errores[] = 'Las existencias físicas deben ser un número entero entre 0 y 2.147.483.647.';
    }

    // Validar observaciones.
    $longitudObservaciones = function_exists('mb_strlen')
        ? mb_strlen($observaciones)
        : strlen($observaciones);

    if ($longitudObservaciones > 220) {
        $errores[] = 'Las observaciones no pueden superar los 220 caracteres.';
    }

    /**
     * Ejecutar el ajuste.
     */
    if (empty($errores)) {

        try {

            $pdo->beginTransaction();

            /**
             * Consultar y bloquear el producto.
             */
            $stmtProducto = $pdo->prepare(
                "SELECT
                    id_producto,
                    nombre,
                    stock_actual
                 FROM productos
                 WHERE id_producto = :id_producto
                   AND estado = 1
                 FOR UPDATE"
            );

            $stmtProducto->execute([
                ':id_producto' => $idProductoValidado
            ]);

            $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {

                $pdo->rollBack();

                $errores[] = 'El producto seleccionado no existe o está inactivo.';

            } else {

                $stockActual = (int) $producto['stock_actual'];

                /**
                 * Calcular diferencia entre las existencias
                 * registradas y las existencias físicas.
                 */
                $diferencia = $stockFisicoValidado - $stockActual;

                if ($diferencia === 0) {

                    $pdo->rollBack();

                    $errores[] = 'Las existencias físicas coinciden con las del sistema. No es necesario realizar un ajuste.';

                } else {

                    /**
                     * Determinar automáticamente el tipo
                     * y la cantidad del movimiento.
                     */
                    if ($diferencia > 0) {

                        $tipoMovimiento = 'ajuste_positivo';
                        $cantidadMovimiento = $diferencia;

                    } else {

                        $tipoMovimiento = 'ajuste_negativo';
                        $cantidadMovimiento = abs($diferencia);

                    }

                    /**
                     * Actualizar las existencias.
                     */
                    $stmtActualizar = $pdo->prepare(
                        "UPDATE productos
                         SET stock_actual = :nuevo_stock
                         WHERE id_producto = :id_producto"
                    );

                    $stmtActualizar->execute([
                        ':nuevo_stock' => $stockFisicoValidado,
                        ':id_producto' => $idProductoValidado
                    ]);

                    if ($stmtActualizar->rowCount() !== 1) {
                        throw new RuntimeException(
                            'No fue posible actualizar las existencias.'
                        );
                    }

                    /**
                     * Preparar observación.
                     */
                    $observacionFinal = $observaciones !== ''
                        ? 'Ajuste por conteo físico. ' . $observaciones
                        : 'Ajuste por conteo físico.';

                    /**
                     * Registrar movimiento.
                     */
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
                            :id_producto,
                            :id_usuario,
                            NULL,
                            :tipo_movimiento,
                            :cantidad,
                            CURRENT_TIMESTAMP,
                            :observaciones
                        )"
                    );

                    $stmtMovimiento->execute([
                        ':id_producto' => $idProductoValidado,
                        ':id_usuario' => $idUsuario,
                        ':tipo_movimiento' => $tipoMovimiento,
                        ':cantidad' => $cantidadMovimiento,
                        ':observaciones' => $observacionFinal
                    ]);

                    $pdo->commit();

                    establecerMensaje(
                        'success',
                        'Las existencias de '
                        . $producto['nombre']
                        . ' se ajustaron correctamente. Cantidad anterior: '
                        . number_format($stockActual)
                        . '. Cantidad nueva: '
                        . number_format($stockFisicoValidado)
                        . '.'
                    );

                    header(
                        'Location: ' . urlSistema('inventario/index.php')
                    );

                    exit;
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Error al ajustar existencias: '
                . $e->getMessage()
            );

            $errores[] = 'Ocurrió un error al ajustar las existencias. Inténtalo nuevamente.';
        }
    }
}

/**
 * Consultar productos activos.
 */
$stmtProductos = $pdo->query(
    "SELECT
        id_producto,
        codigo_barras,
        nombre,
        stock_actual
     FROM productos
     WHERE estado = 1
     ORDER BY nombre ASC"
);

$productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

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
                        Ajustar existencias
                    </h1>

                    <p class="text-muted mb-0">
                        Corrige las diferencias entre el inventario físico y el sistema.
                    </p>
                </div>

                <a
                    href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver al inventario
                </a>

            </div>

            <!-- Errores -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger" id="erroresAjuste">

                    <strong>No se pudo realizar el ajuste:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach ($errores as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <?php if (empty($productos)): ?>

                <div class="card shadow-sm border-0">

                    <div class="card-body text-center py-5">

                        <i class="bi bi-box-seam display-4 text-muted"></i>

                        <h5 class="mt-3">
                            No hay productos disponibles
                        </h5>

                        <p class="text-muted">
                            Primero debes registrar productos activos.
                        </p>

                    </div>

                </div>

            <?php else: ?>

                <div class="row justify-content-center">

                    <div class="col-xl-8 col-lg-10">

                        <div class="card shadow-sm border-0">

                            <div class="card-header bg-white py-3">

                                <h2 class="h5 mb-0">
                                    <i class="bi bi-clipboard-check text-success"></i>
                                    Información del ajuste
                                </h2>

                            </div>

                            <div class="card-body">

                                <form method="POST" action="" id="formAjustarStock">

                                    <?= campoTokenCSRF() ?>

                                    <!-- Producto -->
                                    <div class="mb-4">

                                        <label for="id_producto" class="form-label">
                                            Producto
                                            <span class="text-danger">*</span>
                                        </label>

                                        <select
                                            name="id_producto"
                                            id="id_producto"
                                            class="form-select"
                                            required
                                        >

                                            <option value="">
                                                Selecciona un producto
                                            </option>

                                            <?php foreach ($productos as $producto): ?>

                                                <option
                                                    value="<?= (int) $producto['id_producto'] ?>"
                                                    <?= (string) $producto['id_producto'] === $idProductoSeleccionado ? 'selected' : '' ?>
                                                >
                                                    <?= escapar($producto['nombre']) ?>
                                                    — Código:
                                                    <?= escapar($producto['codigo_barras']) ?>
                                                    — Existencias:
                                                    <?= number_format((int) $producto['stock_actual']) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>

                                    <!-- Cantidad física -->
                                    <div class="mb-4">

                                        <label for="stock_fisico" class="form-label">
                                            Existencias físicas
                                            <span class="text-danger">*</span>
                                        </label>

                                        <input
                                            type="number"
                                            name="stock_fisico"
                                            id="stock_fisico"
                                            class="form-control"
                                            min="0"
                                            max="2147483647"
                                            step="1"
                                            value="<?= escapar($stockFisico) ?>"
                                            placeholder="Cantidad real de unidades"
                                            required
                                        >

                                        <div class="form-text">
                                            Introduce la cantidad de unidades que contaste físicamente.
                                            Puedes ingresar cero si el producto ya no tiene existencias.
                                        </div>

                                    </div>

                                    <!-- Observaciones -->
                                    <div class="mb-4">

                                        <label for="observaciones" class="form-label">
                                            Observaciones
                                        </label>

                                        <textarea
                                            name="observaciones"
                                            id="observaciones"
                                            class="form-control"
                                            rows="3"
                                            maxlength="220"
                                            placeholder="Ejemplo: Diferencia encontrada durante el inventario físico."
                                        ><?= escapar($observaciones) ?></textarea>

                                        <div class="form-text">
                                            Campo opcional. Puedes explicar por qué fue necesario
                                            realizar el ajuste.
                                        </div>

                                    </div>

                                    <hr>

                                    <div class="d-flex justify-content-end gap-2">

                                        <a
                                            href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                                            class="btn btn-outline-secondary"
                                        >
                                            Cancelar
                                        </a>

                                        <button
                                            type="submit"
                                            class="btn btn-success"
                                        >
                                            <i class="bi bi-check-circle"></i>
                                            Aplicar ajuste
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                        <!-- Información -->
                        <div class="alert alert-info mt-4">

                            <i class="bi bi-info-circle"></i>

                            <strong>¿Cómo funciona?</strong>

                            <ul class="mb-0 mt-2">

                                <li>
                                    Si la cantidad física es mayor, se registra un
                                    <strong>ajuste positivo</strong>.
                                </li>

                                <li>
                                    Si la cantidad física es menor, se registra un
                                    <strong>ajuste negativo</strong>.
                                </li>

                                <li>
                                    Si ambas cantidades coinciden, no se registra ningún movimiento.
                                </li>

                            </ul>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </main>

    </div>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const formulario = document.getElementById('formAjustarStock');

    const erroresAjuste = document.getElementById('erroresAjuste');

    const campoProducto = document.getElementById('id_producto');

    const campoStockFisico = document.getElementById('stock_fisico');

    /*
    |--------------------------------------------------------------------------
    | Mostrar errores de validación
    |--------------------------------------------------------------------------
    */

    if (erroresAjuste && typeof Swal !== 'undefined') {

        const errores = Array.from(
            erroresAjuste.querySelectorAll('li')
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

            erroresAjuste.classList.add('d-none');

            Swal.fire({

                icon: 'error',

                title: 'No se pudo realizar el ajuste',

                html: listaHTML,

                confirmButtonText: 'Entendido',

                confirmButtonColor: '#198754'

            });

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Confirmar ajuste de existencias
    |--------------------------------------------------------------------------
    */

    if (formulario) {

        let ajusteConfirmado = false;

        let confirmacionEnCurso = false;

        formulario.addEventListener('submit', function (evento) {

            if (ajusteConfirmado) {
                return;
            }

            // Permitir el envío normal si SweetAlert2 no está disponible.
            if (typeof Swal === 'undefined') {
                return;
            }

            evento.preventDefault();

            // Evitar confirmaciones duplicadas.
            if (confirmacionEnCurso) {
                return;
            }

            confirmacionEnCurso = true;

            const opcionProducto =
                campoProducto.options[campoProducto.selectedIndex];

            const nombreProducto = opcionProducto
                ? opcionProducto.textContent.trim()
                : 'Producto seleccionado';

            const cantidadFisica = campoStockFisico.value;

            Swal.fire({

                icon: 'warning',

                title: '¿Aplicar este ajuste?',

                html: `
                    <p>Vas a actualizar las existencias del siguiente producto:</p>

                    <p><strong>${nombreProducto}</strong></p>

                    <p>Cantidad física indicada:</p>

                    <h3 class="text-success">${cantidadFisica} unidades</h3>

                    <p class="text-danger mb-0">
                        Esta operación sustituirá las existencias actuales por
                        la cantidad física indicada.
                    </p>
                `,

                showCancelButton: true,

                confirmButtonText: 'Sí, aplicar ajuste',

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

                ajusteConfirmado = true;

                const botonGuardar = formulario.querySelector(
                    'button[type="submit"]'
                );

                if (botonGuardar) {

                    botonGuardar.disabled = true;

                    botonGuardar.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-1"></span> Aplicando ajuste...';

                }

                formulario.requestSubmit();

            });

        });

    }

});

</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
