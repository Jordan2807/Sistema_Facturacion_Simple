<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador', 'almacen');

/**
 * Obtener y validar el identificador del producto.
 */
$idProducto = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idProducto = filter_var(
        $_POST['id_producto'] ?? null,
        FILTER_VALIDATE_INT
    ) ?: 0;
} else {
    $idProducto = filter_var(
        $_GET['id'] ?? null,
        FILTER_VALIDATE_INT
    ) ?: 0;
}

if ($idProducto <= 0) {
    establecerMensaje('warning', 'El producto seleccionado no es válido.');
    redirigir(urlSistema('inventario/index.php'));
}

/**
 * Consultar el producto.
 */
$stmtProducto = $pdo->prepare(
    "SELECT *
     FROM productos
     WHERE id_producto = :id
     LIMIT 1"
);

$stmtProducto->execute([
    ':id' => $idProducto
]);

$producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    establecerMensaje('warning', 'El producto solicitado no existe.');
    redirigir(urlSistema('inventario/index.php'));
}

/**
 * Valores iniciales del formulario.
 */
$codigoBarras = $producto['codigo_barras'];
$nombre = $producto['nombre'];
$categoria = $producto['categoria'] ?? '';
$costo = $producto['costo'];
$precioVenta = $producto['precio_venta'];
$stockMinimo = (string) $producto['stock_minimo'];

$errores = [];

/**
 * Procesamiento del formulario.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verificarTokenCSRF()) {
        http_response_code(403);
        exit('La solicitud no es válida. Recarga la página e inténtalo nuevamente.');
    }

    $codigoBarras = trim($_POST['codigo_barras'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $categoria = trim($_POST['categoria'] ?? '');
    $costo = trim($_POST['costo'] ?? '');
    $precioVenta = trim($_POST['precio_venta'] ?? '');
    $stockMinimo = trim($_POST['stock_minimo'] ?? '');

    // Código de barras.
    if (
        !preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $codigoBarras)
    ) {
        $errores[] = 'El código de barras debe contener entre 1 y 50 caracteres alfanuméricos, guiones o guiones bajos.';
    }

    // Nombre.
    $longitudNombre = function_exists('mb_strlen')
        ? mb_strlen($nombre)
        : strlen($nombre);

    if ($longitudNombre < 2 || $longitudNombre > 150) {
        $errores[] = 'El nombre debe tener entre 2 y 150 caracteres.';
    }

    // Categoría.
    $longitudCategoria = function_exists('mb_strlen')
        ? mb_strlen($categoria)
        : strlen($categoria);

    if ($longitudCategoria > 100) {
        $errores[] = 'La categoría no puede superar los 100 caracteres.';
    }

    // Validación de montos.
    $formatoMontoValido = '/^\d{1,8}(?:\.\d{1,2})?$/';

    if (
        !preg_match($formatoMontoValido, $costo) ||
        (float) $costo < 0
    ) {
        $errores[] = 'El costo debe ser un monto válido y no puede ser negativo.';
    }

    if (
        !preg_match($formatoMontoValido, $precioVenta) ||
        (float) $precioVenta <= 0
    ) {
        $errores[] = 'El precio de venta debe ser mayor que cero.';
    }

    // Stock mínimo.
    $stockMinimoValidado = filter_var(
        $stockMinimo,
        FILTER_VALIDATE_INT
    );

    if ($stockMinimoValidado === false || $stockMinimoValidado < 0) {
        $errores[] = 'El stock mínimo debe ser un número entero igual o mayor que cero.';
    }

    /**
     * Comprobar que el código de barras no pertenezca
     * a otro producto.
     */
    if (empty($errores)) {
        $stmtDuplicado = $pdo->prepare(
            "SELECT id_producto
             FROM productos
             WHERE codigo_barras = :codigo
               AND id_producto <> :id
             LIMIT 1"
        );

        $stmtDuplicado->execute([
            ':codigo' => $codigoBarras,
            ':id' => $idProducto
        ]);

        if ($stmtDuplicado->fetch()) {
            $errores[] = 'Ya existe otro producto registrado con ese código de barras.';
        }
    }

    /**
     * Guardar los cambios.
     */
    if (empty($errores)) {

        try {
            $pdo->beginTransaction();

            $sql = "UPDATE productos
                    SET codigo_barras = :codigo_barras,
                        nombre = :nombre,
                        categoria = :categoria,
                        costo = :costo,
                        precio_venta = :precio_venta,
                        stock_minimo = :stock_minimo
                    WHERE id_producto = :id_producto";

            $stmtActualizar = $pdo->prepare($sql);

            $stmtActualizar->execute([
                ':codigo_barras' => $codigoBarras,
                ':nombre' => $nombre,
                ':categoria' => $categoria !== '' ? $categoria : null,
                ':costo' => number_format((float) $costo, 2, '.', ''),
                ':precio_venta' => number_format((float) $precioVenta, 2, '.', ''),
                ':stock_minimo' => $stockMinimoValidado,
                ':id_producto' => $idProducto
            ]);

            $pdo->commit();

            establecerMensaje('success', 'Los datos del producto se actualizaron correctamente.');
            redirigir(urlSistema('inventario/index.php'));

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Error al editar producto: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errores[] = 'No se pudo actualizar el producto porque el código de barras ya está registrado.';
            } else {
                $errores[] = 'Ocurrió un error al actualizar el producto. Inténtalo nuevamente.';
            }
        }
    }
}

$tituloPagina = 'Editar producto';
$paginaActual = 'inventario';

require_once __DIR__ . '/../includes/header.php';

?>

<div class="container-fluid py-4">
    <div class="row">

        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Editar producto</h1>
                    <p class="text-muted mb-0">
                        Modifica la información del producto seleccionado.
                    </p>
                </div>

                <a href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                   class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i>
                    Volver
                </a>
            </div>

            <?php if (!empty($errores)): ?>
                <div class="alert alert-danger">
                    <strong>No se pudieron guardar los cambios:</strong>

                    <ul class="mb-0 mt-2">
                        <?php foreach ($errores as $error): ?>
                            <li><?= escapar($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h2 class="h5 mb-0">
                        Información del producto
                    </h2>
                </div>

                <div class="card-body">

                    <form method="POST" action="">

                        <?= campoTokenCSRF() ?>

                        <input type="hidden"
                               name="id_producto"
                               value="<?= (int) $idProducto ?>">

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label for="codigo_barras" class="form-label">
                                    Código de barras
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="codigo_barras"
                                    name="codigo_barras"
                                    maxlength="50"
                                    value="<?= escapar($codigoBarras) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label for="nombre" class="form-label">
                                    Nombre del producto
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="nombre"
                                    name="nombre"
                                    maxlength="150"
                                    value="<?= escapar($nombre) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label for="categoria" class="form-label">
                                    Categoría
                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="categoria"
                                    name="categoria"
                                    maxlength="100"
                                    value="<?= escapar($categoria) ?>"
                                    placeholder="Ejemplo: Bebidas, Limpieza, Alimentos"
                                >
                            </div>

                            <div class="col-md-3">
                                <label for="costo" class="form-label">
                                    Costo (₡)
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    id="costo"
                                    name="costo"
                                    min="0"
                                    max="99999999.99"
                                    step="0.01"
                                    value="<?= escapar((string) $costo) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-3">
                                <label for="precio_venta" class="form-label">
                                    Precio de venta (₡)
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    id="precio_venta"
                                    name="precio_venta"
                                    min="0.01"
                                    max="99999999.99"
                                    step="0.01"
                                    value="<?= escapar((string) $precioVenta) ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Existencias actuales
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    value="<?= (int) $producto['stock_actual'] ?>"
                                    disabled
                                >

                                <div class="form-text">
                                    Las existencias se modifican desde los movimientos de inventario.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="stock_minimo" class="form-label">
                                    Stock mínimo
                                </label>

                                <input
                                    type="number"
                                    class="form-control"
                                    id="stock_minimo"
                                    name="stock_minimo"
                                    min="0"
                                    step="1"
                                    value="<?= escapar($stockMinimo) ?>"
                                    required
                                >

                                <div class="form-text">
                                    Cantidad mínima que se desea mantener disponible.
                                </div>
                            </div>

                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-end gap-2">

                            <a href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                               class="btn btn-outline-secondary">
                                Cancelar
                            </a>

                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i>
                                Guardar cambios
                            </button>

                        </div>

                    </form>

                </div>
            </div>

            <div class="alert alert-info mt-4">
                <i class="bi bi-info-circle"></i>
                <strong>Importante:</strong>
                modificar los datos del producto no altera sus existencias.
                Para registrar entradas, salidas o ajustes, utilizaremos el módulo
                de movimientos de inventario.
            </div>

        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
