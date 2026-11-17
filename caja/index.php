<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requerirRol('administrador', 'cajero');

$tituloPagina = 'Registrar venta';
$paginaActual = 'caja';

$errores = [];

$usuarioActual = obtenerUsuarioActual();
$idUsuario = (int) ($usuarioActual['id_usuario'] ?? 0);

$buscar = trim((string) ($_GET['buscar'] ?? $_POST['buscar'] ?? ''));
$clienteNombre = '';

$metodoPago = 'efectivo';
$descuento = '0.00';
$montoRecibido = '';

$carritoInicial = '[]';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $clienteNombre = is_string($_POST['cliente_nombre'] ?? null)
    ? trim($_POST['cliente_nombre'])
    : '';

    $longitudCliente = function_exists('mb_strlen')
        ? mb_strlen($clienteNombre)
        : strlen($clienteNombre);

    if ($longitudCliente > 100) {
        $errores[] = 'El nombre del cliente no puede superar los 100 caracteres.';
    }


    $carritoInicial = is_string($_POST['carrito_json'] ?? null)
        ? $_POST['carrito_json']
        : '[]';

    $descuento = is_string($_POST['descuento'] ?? null)
        ? trim($_POST['descuento'])
        : '';

    $metodoPago = is_string($_POST['metodo_pago'] ?? null)
        ? trim($_POST['metodo_pago'])
        : '';
        
    $montoRecibido = is_string($_POST['monto_recibido'] ?? null)
        ? trim($_POST['monto_recibido'])
        : '';
        
    $carritoRecibido = json_decode($carritoInicial, true);

    $metodosPermitidos = [
        'efectivo',
        'tarjeta',
        'sinpe',
        'otro'
    ];

    /**
     * Validar usuario.
     */
    if ($idUsuario <= 0) {
        $errores[] = 'No se pudo identificar al usuario que registra la venta.';
    }

    /**
     * Validar método de pago.
     */
    if (!in_array($metodoPago, $metodosPermitidos, true)) {
        $errores[] = 'Selecciona un método de pago válido.';
    }

    /**
     * Validar descuento.
     */
    if (
        !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $descuento)
        || (float) $descuento < 0
    ) {
        $errores[] = 'El descuento debe ser un monto válido y no negativo.';
    }

    /**
     * Validar estructura del carrito.
     */
    if (!is_array($carritoRecibido) || empty($carritoRecibido)) {

        $errores[] = 'Debes agregar al menos un producto a la venta.';

    } elseif (count($carritoRecibido) > 50) {

        $errores[] = 'Una venta puede contener un máximo de 50 productos diferentes.';

    }

    /**
     * Preparar líneas del carrito.
     */
    $cantidadesPorProducto = [];

    if (is_array($carritoRecibido)) {

        foreach ($carritoRecibido as $linea) {

            if (!is_array($linea)) {
                $errores[] = 'El carrito contiene información inválida.';
                continue;
            }

            $idProducto = filter_var(
                $linea['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $cantidad = filter_var(
                $linea['cantidad'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($idProducto === false || $idProducto <= 0) {
                $errores[] = 'Uno de los productos del carrito no es válido.';
                continue;
            }

            if (
                $cantidad === false
                || $cantidad <= 0
                || $cantidad > 1000000
            ) {
                $errores[] = 'La cantidad de uno de los productos no es válida.';
                continue;
            }

            if (isset($cantidadesPorProducto[$idProducto])) {

                $errores[] = 'El carrito contiene productos duplicados.';

                continue;
            }

            $cantidadesPorProducto[$idProducto] = $cantidad;
        }
    }

    /**
     * Procesar venta mediante una transacción.
     */
    if (empty($errores) && !empty($cantidadesPorProducto)) {

    $etapaRegistro = 'Inicio de la transacción';

    try {

            $pdo->beginTransaction();

            $productosVenta = [];
            $subtotalVenta = 0.00;

            /**
             * Consultar y bloquear cada producto.
             */
            $stmtProducto = $pdo->prepare(
                "SELECT
                    id_producto,
                    codigo_barras,
                    nombre,
                    precio_venta,
                    stock_actual
                 FROM productos
                 WHERE id_producto = :id_producto
                   AND estado = 1
                 FOR UPDATE"
            );

            foreach ($cantidadesPorProducto as $idProducto => $cantidad) {

                $stmtProducto->execute([
                    ':id_producto' => $idProducto
                ]);

                $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

                if (!$producto) {
                    throw new DomainException(
                        'Uno de los productos ya no existe o está inactivo.'
                    );
                }

                $stockDisponible = (int) $producto['stock_actual'];

                if ($cantidad > $stockDisponible) {
                    throw new DomainException(
                        'No hay suficientes existencias de '
                        . $producto['nombre']
                        . '. Disponibles: '
                        . number_format($stockDisponible)
                        . ', solicitadas: '
                        . number_format($cantidad)
                        . '.'
                    );
                }

                $precioUnitario = (float) $producto['precio_venta'];

                $subtotalLinea = round(
                    $precioUnitario * $cantidad,
                    2
                );

                if ($subtotalLinea > 99999999.99) {
                    throw new DomainException(
                        'El importe de uno de los productos supera el límite permitido.'
                    );
                }

                $subtotalVenta = round(
                    $subtotalVenta + $subtotalLinea,
                    2
                );

                $productosVenta[] = [
                    'id_producto' => (int) $producto['id_producto'],
                    'nombre' => $producto['nombre'],
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotalLinea
                ];
            }

            /**
             * Comprobar los montos de la venta.
             */
            $descuentoValidado = round((float) $descuento, 2);

            if ($descuentoValidado > $subtotalVenta) {
                throw new DomainException(
                    'El descuento no puede superar el subtotal de la venta.'
                );
            }

            $totalVenta = round(
                $subtotalVenta - $descuentoValidado,
                2
            );

            /*
            |--------------------------------------------------------------------------
            | Validar monto recibido y calcular cambio
            |--------------------------------------------------------------------------
            */

            // Para tarjeta, SINPE y otros, se registra el total exacto.
            $montoRecibidoValidado = $totalVenta;
            $cambioVenta = 0.00;

            // En efectivo se utiliza el monto introducido por el cajero.
            if ($metodoPago === 'efectivo') {

                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $montoRecibido)) {

                    throw new DomainException(
                        'Ingresa un monto recibido válido para el pago en efectivo.'
                    );

                }

                $montoRecibidoValidado = round((float) $montoRecibido, 2);

                // Comparar en céntimos para evitar problemas de precisión decimal.
                $montoRecibidoCentimos = (int) round($montoRecibidoValidado * 100);
                $totalVentaCentimos = (int) round($totalVenta * 100);

                if ($montoRecibidoCentimos < $totalVentaCentimos) {

                    $faltante = round(
                        ($totalVentaCentimos - $montoRecibidoCentimos) / 100,
                        2
                    );

                    throw new DomainException(
                        'El dinero recibido es insuficiente. Falta '
                        . formatearMoneda($faltante)
                        . '.'
                    );

                }

                $cambioVenta = round(
                    ($montoRecibidoCentimos - $totalVentaCentimos) / 100,
                    2
                );

            }

            /**
             * Registrar encabezado de la venta.
             */
            $observacionesVenta = $clienteNombre !== ''
                ? 'Cliente: ' . $clienteNombre
                : null;

            $stmtVenta = $pdo->prepare(
                "INSERT INTO ventas
                (
                    id_usuario,
                    subtotal,
                    descuento,
                    total,
                    metodo_pago,
                    monto_recibido,
                    cambio,
                    estado,
                    observaciones
                )
                VALUES
                (
                    :id_usuario,
                    :subtotal,
                    :descuento,
                    :total,
                    :metodo_pago,
                    :monto_recibido,
                    :cambio,
                    'completada',
                    :observaciones
                )"
            );

            $stmtVenta->execute([
                ':id_usuario' => $idUsuario,

                ':subtotal' => number_format(
                    $subtotalVenta,
                    2,
                    '.',
                    ''
                ),

                ':descuento' => number_format(
                    $descuentoValidado,
                    2,
                    '.',
                    ''
                ),

                ':total' => number_format(
                    $totalVenta,
                    2,
                    '.',
                    ''
                ),

                ':metodo_pago' => $metodoPago,

                ':monto_recibido' => number_format(
                    $montoRecibidoValidado,
                    2,
                    '.',
                    ''
                ),

                ':cambio' => number_format(
                    $cambioVenta,
                    2,
                    '.',
                    ''
                ),

                ':observaciones' => $observacionesVenta
            ]);

            $idVenta = (int) $pdo->lastInsertId();

            if ($idVenta <= 0) {
                throw new RuntimeException(
                    'No se pudo obtener el identificador de la venta.'
                );
            }

            /**
             * Preparar consultas para detalle y movimientos.
             */
            $stmtDetalle = $pdo->prepare(
                "INSERT INTO detalle_venta
                (
                    id_venta,
                    id_producto,
                    cantidad,
                    precio_unitario,
                    subtotal
                )
                VALUES
                (
                    :id_venta,
                    :id_producto,
                    :cantidad,
                    :precio_unitario,
                    :subtotal
                )"
            );

            $stmtActualizarStock = $pdo->prepare(
                "UPDATE productos
                 SET stock_actual = stock_actual - :cantidad_restar
                 WHERE id_producto = :id_producto
                   AND stock_actual >= :cantidad_disponible"
            );

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
                    :id_venta,
                    'salida',
                    :cantidad,
                    CURRENT_TIMESTAMP,
                    :observaciones
                )"
            );

            $numeroComprobante = generarNumeroComprobante($idVenta);

            /**
             * Registrar cada producto vendido.
             */
            foreach ($productosVenta as $producto) {

                // Detalle de venta.
                $stmtDetalle->execute([
                    ':id_venta' => $idVenta,
                    ':id_producto' => $producto['id_producto'],
                    ':cantidad' => $producto['cantidad'],
                    ':precio_unitario' => number_format(
                        $producto['precio_unitario'],
                        2,
                        '.',
                        ''
                    ),
                    ':subtotal' => number_format(
                        $producto['subtotal'],
                        2,
                        '.',
                        ''
                    )
                ]);

                // Descontar existencias.
                $stmtActualizarStock->execute([
                    ':cantidad_restar' => $producto['cantidad'],
                    ':id_producto' => $producto['id_producto'],
                    ':cantidad_disponible' => $producto['cantidad']
                ]);

                if ($stmtActualizarStock->rowCount() !== 1) {
                    throw new RuntimeException(
                        'No se pudieron actualizar las existencias de '
                        . $producto['nombre']
                        . '.'
                    );
                }

                // Registrar movimiento de inventario.
                $stmtMovimiento->execute([
                    ':id_producto' => $producto['id_producto'],
                    ':id_usuario' => $idUsuario,
                    ':id_venta' => $idVenta,
                    ':cantidad' => $producto['cantidad'],
                    ':observaciones' => 'Salida por venta ' . $numeroComprobante
                ]);
            }

            /**
             * Confirmar toda la operación.
             */
            $etapaRegistro = 'Confirmar transacción';
            $pdo->commit();

            establecerMensaje(
            'success',
            '¡Venta realizada correctamente! '
            . 'Comprobante: ' . $numeroComprobante
            . '. Total cobrado: ' . formatearMoneda($totalVenta)
        );

        header(
            'Location: ' . urlSistema('caja/comprobante.php?id=' . $idVenta)
        );

        exit;

        } catch (DomainException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errores[] = $e->getMessage();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Guardar el error técnico en el registro de Apache.
            error_log(
                'Error al registrar venta: ' . $e->getMessage()
            );

            $errores[] = 'Ocurrió un error al registrar la venta. No se guardaron los cambios. Inténtalo nuevamente.';
        }
    }
}





/**
 * Buscar productos activos con existencias.
 */
$sqlProductos = "
    SELECT
        id_producto,
        codigo_barras,
        nombre,
        categoria,
        precio_venta,
        stock_actual
    FROM productos
    WHERE estado = 1
      AND stock_actual > 0
";

$parametrosBusqueda = [];

if ($buscar !== '') {

    $sqlProductos .= "
        AND (
            nombre LIKE :buscar_nombre
            OR codigo_barras LIKE :buscar_codigo
        )
    ";

    $parametrosBusqueda = [
        ':buscar_nombre' => '%' . $buscar . '%',
        ':buscar_codigo' => '%' . $buscar . '%'
    ];
}

$sqlProductos .= "
    ORDER BY nombre ASC
    LIMIT 50
";

$stmtProductos = $pdo->prepare($sqlProductos);

foreach ($parametrosBusqueda as $nombreParametro => $valor) {
    $stmtProductos->bindValue($nombreParametro, $valor);
}

$stmtProductos->execute();

$productosDisponibles = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

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
                        Registrar venta
                    </h1>

                    <p class="text-muted mb-0">
                        Selecciona productos, cantidades y método de pago.
                    </p>

                </div>

                <a
                    href="<?= escapar(urlSistema('caja/ventas.php')) ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-clock-history"></i>
                    Historial de ventas
                </a>

            </div>

            <!-- Errores -->
            <?php if (!empty($errores)): ?>

                <div class="alert alert-danger">

                    <strong>No se pudo registrar la venta:</strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach (array_unique($errores) as $error): ?>

                            <li><?= escapar($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            
                <!-- LECTOR DE CODIGOS DE BARRAS -->
                <div class="card shadow-sm border-0 mb-4">

                    <div class="card-body">

                        <label for="lector_codigo" class="form-label fw-semibold">
                            <i class="bi bi-upc-scan text-success"></i>
                            Escanear producto
                        </label>

                        <input
                            type="text"
                            id="lector_codigo"
                            class="form-control form-control-lg"
                            placeholder="Escanea el código de barras y presiona Enter"
                            autocomplete="off"
                            autofocus
                            data-url="<?= escapar(urlSistema('caja/buscar_producto.php')) ?>"
                        >

                        <div class="form-text">
                            Al escanear un producto, se agregará automáticamente al carrito.
                            Puedes escanear el mismo producto varias veces para aumentar la cantidad.
                        </div>

                        <div
                            id="estado_lector"
                            class="small mt-2"
                            aria-live="polite"
                        ></div>

                    </div>

                </div>
                    


            <!-- Buscador -->
            <div class="card shadow-sm border-0 mb-4">

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
                                    placeholder="Escribe el nombre o escanea el código de barras"
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
                                    href="<?= escapar(urlSistema('caja/index.php')) ?>"
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

            <form method="POST" action="" id="formVenta">

                <?= campoTokenCSRF() ?>

                <input
                    type="hidden"
                    name="carrito_json"
                    id="carrito_json"
                    value="<?= escapar($carritoInicial) ?>"
                >

                <input
                    type="hidden"
                    name="buscar"
                    value="<?= escapar($buscar) ?>"
                >

                <div class="row g-4">

                    <!-- Productos disponibles -->
                    <div class="col-lg-7">

                        <div class="card shadow-sm border-0 h-100">

                            <div class="card-header bg-white py-3">

                                <h2 class="h5 mb-0">
                                    <i class="bi bi-box-seam"></i>
                                    Productos disponibles
                                </h2>

                            </div>

                            <div class="card-body p-0">

                                <?php if (empty($productosDisponibles)): ?>

                                    <div class="text-center py-5">

                                        <i class="bi bi-search display-4 text-muted"></i>

                                        <h5 class="mt-3">
                                            No se encontraron productos
                                        </h5>

                                        <p class="text-muted mb-0">
                                            Comprueba el nombre, código o disponibilidad del producto.
                                        </p>

                                    </div>

                                <?php else: ?>

                                    <div class="table-responsive">

                                        <table class="table table-hover align-middle mb-0">

                                            <thead class="table-light">

                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Precio</th>
                                                    <th class="text-center">Stock</th>
                                                    <th></th>
                                                </tr>

                                            </thead>

                                            <tbody>

                                                <?php foreach ($productosDisponibles as $producto): ?>

                                                    <tr>

                                                        <td>

                                                            <strong>
                                                                <?= escapar($producto['nombre']) ?>
                                                            </strong>

                                                            <div class="small text-muted">
                                                                <?= escapar($producto['codigo_barras']) ?>
                                                            </div>

                                                        </td>

                                                        <td class="text-nowrap">

                                                            <?= escapar(
                                                                formatearMoneda((float) $producto['precio_venta'])
                                                            ) ?>

                                                        </td>

                                                        <td class="text-center">

                                                            <span class="badge bg-light text-dark border">
                                                                <?= number_format((int) $producto['stock_actual']) ?>
                                                            </span>

                                                        </td>

                                                        <td class="text-end">

                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-outline-success agregar-producto"
                                                                data-id="<?= (int) $producto['id_producto'] ?>"
                                                                data-nombre="<?= escapar($producto['nombre']) ?>"
                                                                data-codigo="<?= escapar($producto['codigo_barras']) ?>"
                                                                data-precio="<?= escapar((string) $producto['precio_venta']) ?>"
                                                                data-stock="<?= (int) $producto['stock_actual'] ?>"
                                                            >
                                                                <i class="bi bi-plus-lg"></i>
                                                            </button>

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
                                    Se muestran hasta 50 productos activos con existencias.
                                    Utiliza la búsqueda para localizar otros productos.
                                </small>

                            </div>

                        </div>

                    </div>

                    <!-- Carrito -->
                    <div class="col-lg-5">

                        <div class="card shadow-sm border-0">

                            <div class="card-header bg-white py-3">

                                <h2 class="h5 mb-0">
                                    <i class="bi bi-cart3"></i>
                                    Detalle de la venta
                                </h2>

                            </div>

                            <div class="card-body p-0">

                                <div class="table-responsive">

                                    <table class="table table-sm align-middle mb-0">

                                        <thead class="table-light">

                                            <tr>
                                                <th>Producto</th>
                                                <th style="width: 90px;">Cantidad</th>
                                                <th class="text-end">Subtotal</th>
                                                <th></th>
                                            </tr>

                                        </thead>

                                        <tbody id="carrito_productos">

                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">
                                                    No hay productos agregados.
                                                </td>
                                            </tr>

                                        </tbody>

                                    </table>

                                </div>

                            </div>

                            <div class="card-body">

                                <div class="d-flex justify-content-between mb-2">

                                    <span class="text-muted">
                                        Subtotal:
                                    </span>

                                    <strong id="subtotal_texto">
                                        ₡0,00
                                    </strong>

                                </div>

                                
                                <!-- CLIENTE -->
                                <div class="mb-3">

                                    <label for="cliente_nombre" class="form-label">
                                        Nombre del cliente
                                        <span class="text-muted small">(Opcional)</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="cliente_nombre"
                                        id="cliente_nombre"
                                        class="form-control"
                                        maxlength="100"
                                        value="<?= escapar($clienteNombre) ?>"
                                        placeholder="Ingrese el nombre del cliente"
                                        autocomplete="off"
                                    >

                                    <div class="form-text">
                                        Puedes dejarlo vacío para una venta sin identificación del cliente.
                                    </div>

                                </div>
                    

                                <div class="mb-3">

                                    <label for="descuento" class="form-label">
                                        Descuento (₡)
                                    </label>

                                    <input
                                        type="number"
                                        id="descuento"
                                        name="descuento"
                                        class="form-control"
                                        min="0"
                                        max="99999999.99"
                                        step="0.01"
                                        value="<?= escapar($descuento) ?>"
                                    >

                                </div>

                                <div class="d-flex justify-content-between align-items-center border-top pt-3 mb-4">

                                    <span class="fw-bold">
                                        TOTAL:
                                    </span>

                                    <strong class="h4 text-success mb-0" id="total_texto">
                                        ₡0,00
                                    </strong>

                                </div>

                                <div class="mb-4">

                                    <label for="metodo_pago" class="form-label">
                                        Método de pago
                                    </label>

                                    <select
                                        name="metodo_pago"
                                        id="metodo_pago"
                                        class="form-select"
                                        required
                                    >

                                        <option value="efectivo"
                                            <?= $metodoPago === 'efectivo' ? 'selected' : '' ?>>
                                            Efectivo
                                        </option>

                                        <option value="tarjeta"
                                            <?= $metodoPago === 'tarjeta' ? 'selected' : '' ?>>
                                            Tarjeta
                                        </option>

                                        <option value="sinpe"
                                            <?= $metodoPago === 'sinpe' ? 'selected' : '' ?>>
                                            SINPE Móvil
                                        </option>

                                        <option value="otro"
                                            <?= $metodoPago === 'otro' ? 'selected' : '' ?>>
                                            Otro
                                        </option>

                                    </select>
                                
                                    <!-- PAGO EN EFECTIVO -->

                                    <div class="mt-3" id="contenedorPagoEfectivo">

                                        <label for="monto_recibido" class="form-label fw-semibold">
                                            Dinero recibido del cliente (₡)
                                        </label>

                                        <input
                                            type="number"
                                            name="monto_recibido"
                                            id="monto_recibido"
                                            class="form-control"
                                            min="0"
                                            max="99999999.99"
                                            step="0.01"
                                            value="<?= escapar($montoRecibido) ?>"
                                            placeholder="Ejemplo: 10000"
                                            autocomplete="off"
                                        >

                                        <div class="form-text">
                                            Ingresa cuánto dinero entregó el cliente.
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-3 border-top pt-3">

                                            <span class="fw-semibold">
                                                Cambio a devolver:
                                            </span>

                                            <strong class="text-success fs-5" id="cambio_texto">
                                                ₡0,00
                                            </strong>

                                        </div>

                                        <div
                                            id="estado_pago_efectivo"
                                            class="small mt-2"
                                            aria-live="polite"
                                        ></div>

                                    </div>    
                                    
                                </div>

                                <button
                                    type="submit"
                                    class="btn btn-success btn-lg w-100"
                                    id="btnRegistrarVenta"
                                >
                                    <i class="bi bi-check-circle"></i>
                                    Registrar venta
                                </button>

                                <div class="form-text text-center mt-2">
                                    Al confirmar, se descontarán las existencias y se guardará la venta.
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </form>

        </main>

    </div>

</div>



<!-- MODAL DE CONFIRMACION DE VENTA -->
<div
    class="modal fade"
    id="modalConfirmacionVenta"
    tabindex="-1"
    aria-labelledby="tituloConfirmacionVenta"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title" id="tituloConfirmacionVenta">
                    <i class="bi bi-receipt text-success"></i>
                    Confirmar venta
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar"
                ></button>

            </div>

            <div class="modal-body">

                <p class="text-muted">
                    Revisa los datos antes de confirmar el cobro.
                </p>

                <div class="mb-3">

                    <span class="text-muted small">
                        Cliente
                    </span>

                    <div class="fw-semibold" id="confirmacionCliente">
                        Consumidor final
                    </div>

                </div>

                <div class="mb-3">

                    <div class="d-flex justify-content-between mb-2">

                        <span class="text-muted" id="confirmacionEtiquetaMonto">
                            Dinero recibido
                        </span>

                        <strong id="confirmacionMontoRecibido">
                            ₡0,00
                        </strong>

                    </div>

                    <div class="d-flex justify-content-between">

                        <span class="text-muted">
                            Cambio a devolver
                        </span>

                        <strong class="text-success" id="confirmacionCambio">
                            ₡0,00
                        </strong>

                    </div>

                </div>
                                                    
                <div class="mb-3">

                    <span class="text-muted small">
                        Método de pago
                    </span>

                    <div class="fw-semibold" id="confirmacionMetodo">
                        Efectivo
                    </div>

                </div>

                <div class="border-top pt-3">

                    <div class="d-flex justify-content-between align-items-center">

                        <span class="fw-bold">
                            TOTAL A PAGAR
                        </span>

                        <strong
                            class="text-success fs-3"
                            id="confirmacionTotal"
                        >
                            ₡0,00
                        </strong>

                    </div>

                </div>

                <div class="alert alert-warning mt-3 mb-0">

                    <i class="bi bi-exclamation-triangle"></i>

                    Al confirmar, se registrará la venta y se descontarán
                    las existencias correspondientes.

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal"
                >
                    Volver a revisar
                </button>

                <button
                    type="button"
                    class="btn btn-success"
                    id="btnConfirmarVenta"
                >
                    <i class="bi bi-check-circle"></i>
                    Sí, confirmar venta
                </button>

            </div>

        </div>

    </div>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const botonesAgregar = document.querySelectorAll('.agregar-producto');

    const tablaCarrito = document.getElementById('carrito_productos');

    const campoCarrito = document.getElementById('carrito_json');

    const campoDescuento = document.getElementById('descuento');

    const textoSubtotal = document.getElementById('subtotal_texto');

    const textoTotal = document.getElementById('total_texto');

    const campoMetodoPago = document.getElementById('metodo_pago');

    const contenedorPagoEfectivo = document.getElementById('contenedorPagoEfectivo');

    const campoMontoRecibido = document.getElementById('monto_recibido');

    const textoCambio = document.getElementById('cambio_texto');

    const estadoPagoEfectivo = document.getElementById('estado_pago_efectivo');

    const formulario = document.getElementById('formVenta');

    const botonRegistrar = document.getElementById('btnRegistrarVenta');

    const carrito = new Map();

    /**
     * Formato de moneda.
     */
    function moneda(valor) {

        return '₡' + Number(valor).toLocaleString('es-CR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

    }

    /*
    |--------------------------------------------------------------------------
    | Calcular el total actual
    |--------------------------------------------------------------------------
    */

    function obtenerTotalActual() {

        let subtotal = 0;

        carrito.forEach(function (item) {

            subtotal += Number(item.precio) * Number(item.cantidad);

        });

        const descuento = Math.max(
            0,
            Number(campoDescuento.value) || 0
        );

        return Math.max(
            0,
            Math.round((subtotal - descuento) * 100) / 100
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Obtener información del pago
    |--------------------------------------------------------------------------
    */

    function obtenerDatosPago(totalActual) {

        // Tarjeta, SINPE y otros registran el total exacto.
        if (campoMetodoPago.value !== 'efectivo') {

            return {
                valido: true,
                montoRecibido: totalActual,
                cambio: 0,
                mensaje: ''
            };

        }

        const valorIngresado = campoMontoRecibido.value.trim();

        if (!/^\d{1,8}(?:\.\d{1,2})?$/.test(valorIngresado)) {

            return {
                valido: false,
                mensaje: 'Ingresa cuánto dinero entregó el cliente.'
            };

        }

        const montoCentimos = Math.round(Number(valorIngresado) * 100);

        const totalCentimos = Math.round(Number(totalActual) * 100);

        if (montoCentimos < totalCentimos) {

            const faltante = (totalCentimos - montoCentimos) / 100;

            return {
                valido: false,
                mensaje: 'El dinero recibido es insuficiente. Falta ' + moneda(faltante) + '.'
            };

        }

        return {
            valido: true,
            montoRecibido: montoCentimos / 100,
            cambio: (montoCentimos - totalCentimos) / 100,
            mensaje: ''
        };

    }

    /*
    |--------------------------------------------------------------------------
    | Actualizar la interfaz del pago
    |--------------------------------------------------------------------------
    */

    function actualizarInterfazPago(totalActual) {

        const esEfectivo = campoMetodoPago.value === 'efectivo';

        contenedorPagoEfectivo.classList.toggle('d-none', !esEfectivo);

        campoMontoRecibido.disabled = !esEfectivo;

        if (!esEfectivo) {

            textoCambio.textContent = moneda(0);

            estadoPagoEfectivo.textContent =
                'Este método de pago no requiere calcular cambio.';

            estadoPagoEfectivo.className = 'small mt-2 text-muted';

            return;

        }

        const datosPago = obtenerDatosPago(totalActual);

        if (!datosPago.valido) {

            textoCambio.textContent = moneda(0);

            estadoPagoEfectivo.textContent = datosPago.mensaje;

            estadoPagoEfectivo.className = 'small mt-2 text-danger';

        } else {

            textoCambio.textContent = moneda(datosPago.cambio);

            estadoPagoEfectivo.textContent =
                'Monto suficiente. Cambio calculado correctamente.';

            estadoPagoEfectivo.className = 'small mt-2 text-success';

        }

    }

    /**
     * Recuperar el carrito cuando hubo errores
     * de validación en el servidor.
     */
    try {

        const inicial = JSON.parse(campoCarrito.value || '[]');

        if (Array.isArray(inicial)) {

            inicial.forEach(function (item) {

                const id = Number(item.id);
                const cantidad = Number(item.cantidad);

                if (
                    Number.isInteger(id)
                    && id > 0
                    && Number.isInteger(cantidad)
                    && cantidad > 0
                ) {

                    carrito.set(id, {
                        id: id,
                        nombre: String(item.nombre || 'Producto'),
                        codigo: String(item.codigo || ''),
                        precio: Number(item.precio || 0),
                        stock: Number(item.stock || cantidad),
                        cantidad: cantidad
                    });

                }

            });

        }

    } catch (error) {

        console.error('No se pudo recuperar el carrito:', error);

    }

    /**
     * Actualizar el campo JSON oculto.
     */
    function actualizarCampoCarrito() {

        const elementos = Array.from(carrito.values()).map(function (item) {

            return {
                id: item.id,
                cantidad: item.cantidad,
                nombre: item.nombre,
                codigo: item.codigo,
                precio: item.precio,
                stock: item.stock
            };

        });

        campoCarrito.value = JSON.stringify(elementos);

    }

    /**
     * Dibujar el carrito.
     */
    function mostrarCarrito() {

        tablaCarrito.replaceChildren();

        if (carrito.size === 0) {

            const fila = document.createElement('tr');
            const celda = document.createElement('td');

            celda.colSpan = 4;
            celda.className = 'text-center text-muted py-4';
            celda.textContent = 'No hay productos agregados.';

            fila.appendChild(celda);
            tablaCarrito.appendChild(fila);

        } else {

            carrito.forEach(function (item, id) {

                const fila = document.createElement('tr');

                const celdaProducto = document.createElement('td');

                const nombre = document.createElement('strong');
                nombre.textContent = item.nombre;

                const codigo = document.createElement('div');
                codigo.className = 'small text-muted';
                codigo.textContent = item.codigo;

                celdaProducto.appendChild(nombre);
                celdaProducto.appendChild(codigo);

                const celdaCantidad = document.createElement('td');

                const entradaCantidad = document.createElement('input');

                entradaCantidad.type = 'number';
                entradaCantidad.min = '1';
                entradaCantidad.max = String(item.stock);
                entradaCantidad.value = String(item.cantidad);
                entradaCantidad.className = 'form-control form-control-sm';
                entradaCantidad.setAttribute('aria-label', 'Cantidad de ' + item.nombre);

                entradaCantidad.addEventListener('change', function () {

                    const nuevaCantidad = Number(this.value);

                    if (
                        !Number.isInteger(nuevaCantidad)
                        || nuevaCantidad < 1
                        || nuevaCantidad > item.stock
                    ) {

                        alert(
                            'La cantidad debe estar entre 1 y '
                            + item.stock
                            + ' unidades.'
                        );

                        this.value = String(item.cantidad);
                        return;

                    }

                    item.cantidad = nuevaCantidad;

                    actualizarCampoCarrito();
                    mostrarCarrito();

                });

                celdaCantidad.appendChild(entradaCantidad);

                const celdaSubtotal = document.createElement('td');

                celdaSubtotal.className = 'text-end text-nowrap';

                celdaSubtotal.textContent = moneda(
                    item.precio * item.cantidad
                );

                const celdaEliminar = document.createElement('td');

                const botonEliminar = document.createElement('button');

                botonEliminar.type = 'button';
                botonEliminar.className = 'btn btn-sm btn-outline-danger';
                botonEliminar.innerHTML = '<i class="bi bi-trash"></i>';
                botonEliminar.title = 'Eliminar producto';
                botonEliminar.setAttribute(
                    'aria-label',
                    'Eliminar ' + item.nombre
                );

                botonEliminar.addEventListener('click', function () {

                    carrito.delete(id);

                    actualizarCampoCarrito();
                    mostrarCarrito();

                });

                celdaEliminar.appendChild(botonEliminar);

                fila.appendChild(celdaProducto);
                fila.appendChild(celdaCantidad);
                fila.appendChild(celdaSubtotal);
                fila.appendChild(celdaEliminar);

                tablaCarrito.appendChild(fila);

            });

        }

        /**
         * Calcular subtotal y total.
         */
        let subtotal = 0;

        carrito.forEach(function (item) {

            subtotal += item.precio * item.cantidad;

        });

        const descuento = Math.max(
            0,
            Number(campoDescuento.value) || 0
        );

        const total = Math.max(0, subtotal - descuento);

        textoSubtotal.textContent = moneda(subtotal);
        textoTotal.textContent = moneda(total);

        actualizarInterfazPago(total);
        actualizarCampoCarrito();

    }

    /**
     * Agregar un producto al carrito.
     */
    
/**
 * Agregar un producto al carrito.
 *
 * Esta función se utiliza tanto para los botones
 * manuales como para el lector de códigos de barras.
 */
function agregarProductoAlCarrito(producto) {

    const id = Number(producto.id);

    const stock = Number(producto.stock);

    if (!Number.isInteger(id) || id <= 0 || stock <= 0) {

        return {
            success: false,
            message: 'El producto no tiene existencias disponibles.'
        };

    }

    if (carrito.has(id)) {

        const existente = carrito.get(id);

        // Actualizar información recibida desde MySQL.
        existente.stock = stock;
        existente.precio = Number(producto.precio);

        if (existente.cantidad >= existente.stock) {

            return {
                success: false,
                message: 'Ya agregaste todas las unidades disponibles de este producto.'
            };

        }

        existente.cantidad++;

    } else {

        carrito.set(id, {
            id: id,
            nombre: String(producto.nombre),
            codigo: String(producto.codigo),
            precio: Number(producto.precio),
            stock: stock,
            cantidad: 1
        });

    }

    mostrarCarrito();

    return {
        success: true,
        message: 'Producto agregado: ' + producto.nombre
    };

}

/**
 * Agregar productos utilizando los botones de la tabla.
 */
botonesAgregar.forEach(function (boton) {

    boton.addEventListener('click', function () {

        const resultado = agregarProductoAlCarrito({

            id: this.dataset.id,
            nombre: this.dataset.nombre,
            codigo: this.dataset.codigo,
            precio: this.dataset.precio,
            stock: this.dataset.stock

        });

        if (!resultado.success) {
            alert(resultado.message);
        }

    });

});



/**
 * Lector de códigos de barras.
 */
const lectorCodigo = document.getElementById('lector_codigo');

const estadoLector = document.getElementById('estado_lector');

let escaneoEnProceso = false;

if (lectorCodigo && estadoLector) {

    lectorCodigo.addEventListener('keydown', async function (evento) {

        // El lector normalmente envía Enter después del código.
        if (evento.key !== 'Enter') {
            return;
        }

        evento.preventDefault();

        const codigo = lectorCodigo.value.trim();

        if (codigo === '' || escaneoEnProceso) {
            return;
        }

        const tokenCSRF = document.querySelector(
            'input[name="csrf_token"]'
        );

        if (!tokenCSRF) {

            estadoLector.textContent =
                'No se encontró el token de seguridad. Recarga la página.';

            estadoLector.className = 'small mt-2 text-danger';

            return;

        }

        escaneoEnProceso = true;

        estadoLector.textContent = 'Buscando producto...';

        estadoLector.className = 'small mt-2 text-muted';

        try {

            const datos = new FormData();

            datos.append('codigo_barras', codigo);

            datos.append('csrf_token', tokenCSRF.value);

            const respuesta = await fetch(
                lectorCodigo.dataset.url,
                {
                    method: 'POST',
                    body: datos,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }
            );

            const contenido = await respuesta.json();

            if (!respuesta.ok || !contenido.success) {

                throw new Error(
                    contenido.message || 'No fue posible encontrar el producto.'
                );

            }

            // Agregar automáticamente al carrito.
            const resultado = agregarProductoAlCarrito(
                contenido.producto
            );

            if (!resultado.success) {
                throw new Error(resultado.message);
            }

            estadoLector.textContent = resultado.message;

            estadoLector.className = 'small mt-2 text-success fw-semibold';

            // Limpiar para recibir el siguiente escaneo.
            lectorCodigo.value = '';

        } catch (error) {

            estadoLector.textContent =
                error.message || 'Ocurrió un error al escanear el producto.';

            estadoLector.className = 'small mt-2 text-danger fw-semibold';

            lectorCodigo.select();

        } finally {

            escaneoEnProceso = false;

            // Dejar el cursor listo para el siguiente producto.
            lectorCodigo.focus();

        }

    });

}


    /**
     * Recalcular cuando cambia el descuento.
     */
    campoDescuento.addEventListener('input', mostrarCarrito);

    // Actualizar el formulario cuando cambia el método de pago.

    campoMetodoPago.addEventListener('change', function () {

        actualizarInterfazPago(obtenerTotalActual());

    });

    // Actualizar el cambio mientras se escribe el dinero recibido.

    campoMontoRecibido.addEventListener('input', function () {

        actualizarInterfazPago(obtenerTotalActual());

    });

    /**
     * Evitar enviar una venta sin productos.
     */
    
/**
 * Confirmación de venta antes de enviarla al servidor.
 */

let ventaConfirmada = false;

const elementoModal = document.getElementById(
    'modalConfirmacionVenta'
);

const modalConfirmacion = bootstrap.Modal.getOrCreateInstance(
    elementoModal
);

const botonConfirmar = document.getElementById(
    'btnConfirmarVenta'
);

formulario.addEventListener('submit', function (evento) {

    // Si el usuario ya confirmó, permitir el envío.
    if (ventaConfirmada) {

        botonRegistrar.disabled = true;

        botonRegistrar.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Registrando venta...';

        return;
    }

    // Detener el envío hasta que confirme.
    evento.preventDefault();

    if (carrito.size === 0) {

        alert('Debes agregar al menos un producto al carrito.');

        return;

    }

    let subtotalActual = 0;

    carrito.forEach(function (producto) {

        subtotalActual += producto.precio * producto.cantidad;

    });

    const descuentoActual = Number(campoDescuento.value);

    if (
        !Number.isFinite(descuentoActual)
        || descuentoActual < 0
        || descuentoActual > subtotalActual
    ) {

        alert('El descuento ingresado no es válido.');

        campoDescuento.focus();

        return;

    }

    const totalActual = Math.max(
        0,
        subtotalActual - descuentoActual
    );

    // Obtener información del cliente.
    const cliente = document.getElementById('cliente_nombre').value.trim();

    document.getElementById('confirmacionCliente').textContent =
        cliente || 'Consumidor final';

    // Obtener el método de pago seleccionado.
    const metodo = document.getElementById('metodo_pago');

    document.getElementById('confirmacionMetodo').textContent =
        metodo.options[metodo.selectedIndex].text;

    // Validar el dinero recibido y calcular el cambio.

        const datosPago = obtenerDatosPago(totalActual);

        if (!datosPago.valido) {

            alert(datosPago.mensaje);

            if (metodo.value === 'efectivo') {
                campoMontoRecibido.focus();
            }

            return;

        }

        // Mostrar el resumen del pago en la confirmación.

        document.getElementById('confirmacionEtiquetaMonto').textContent =
            metodo.value === 'efectivo'
                ? 'Dinero recibido'
                : 'Monto registrado';

        document.getElementById('confirmacionMontoRecibido').textContent =
            moneda(datosPago.montoRecibido);

        document.getElementById('confirmacionCambio').textContent =
            moneda(datosPago.cambio);

    // Mostrar el total final.
    document.getElementById('confirmacionTotal').textContent =
        moneda(totalActual);

    actualizarCampoCarrito();

    // Mostrar ventana.
    modalConfirmacion.show();

});

/**
 * Confirmar definitivamente la venta.
 */
botonConfirmar.addEventListener('click', function () {

    ventaConfirmada = true;

    botonConfirmar.disabled = true;

    botonConfirmar.innerHTML =
        '<span class="spinner-border spinner-border-sm"></span> Confirmando...';

    modalConfirmacion.hide();

    formulario.requestSubmit();

});


    mostrarCarrito();

});

</script>

<?php

require_once __DIR__ . '/../includes/alerts.php';

require_once __DIR__ . '/../includes/footer.php';

?>
