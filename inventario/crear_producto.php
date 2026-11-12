<?php

/**
 * REGISTRO DE PRODUCTOS
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite registrar nuevos productos y sus existencias iniciales.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

/** @var PDO $pdo */

// Administrador y almacen pueden registrar productos.
requerirRol('administrador', 'almacen');

$tituloPagina = 'Registrar producto';


// ==========================================
// 2. VARIABLES INICIALES
// ==========================================

$errores = [];

$codigoBarras = '';

$nombre = '';

$categoria = '';

$costo = '0.00';

$precioVenta = '';

$stockActual = '0';

$stockMinimo = '0';


// ==========================================
// 3. PROCESAR FORMULARIO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar CSRF.
    verificarTokenCSRF();


    // ==========================================
    // 3.1 OBTENER DATOS
    // ==========================================

    $codigoEntrada = $_POST['codigo_barras'] ?? '';

    $nombreEntrada = $_POST['nombre'] ?? '';

    $categoriaEntrada = $_POST['categoria'] ?? '';

    $costoEntrada = $_POST['costo'] ?? '';

    $precioEntrada = $_POST['precio_venta'] ?? '';

    $stockEntrada = $_POST['stock_actual'] ?? '';

    $stockMinimoEntrada = $_POST['stock_minimo'] ?? '';


    $codigoBarras = is_string($codigoEntrada)
        ? trim($codigoEntrada)
        : '';

    $nombre = is_string($nombreEntrada)
        ? trim($nombreEntrada)
        : '';

    $categoria = is_string($categoriaEntrada)
        ? trim($categoriaEntrada)
        : '';

    $costo = is_string($costoEntrada)
        ? trim($costoEntrada)
        : '';

    $precioVenta = is_string($precioEntrada)
        ? trim($precioEntrada)
        : '';

    $stockActual = is_string($stockEntrada)
        ? trim($stockEntrada)
        : '';

    $stockMinimo = is_string($stockMinimoEntrada)
        ? trim($stockMinimoEntrada)
        : '';


    // ==========================================
    // 3.2 VALIDAR CODIGO DE BARRAS
    // ==========================================

    if (!validarRequerido($codigoBarras)) {

        $errores[] = 'El codigo de barras es obligatorio.';

    } elseif (!validarCodigoBarras($codigoBarras)) {

        $errores[] = 'El codigo de barras contiene caracteres no permitidos o supera los 50 caracteres.';

    }


    // ==========================================
    // 3.3 VALIDAR NOMBRE
    // ==========================================

    if (!validarRequerido($nombre)) {

        $errores[] = 'El nombre del producto es obligatorio.';

    } elseif (!validarLongitud($nombre, 2, 150)) {

        $errores[] = 'El nombre debe tener entre 2 y 150 caracteres.';

    }


    // ==========================================
    // 3.4 VALIDAR CATEGORIA
    // ==========================================

    if (
        $categoria !== '' &&
        !validarLongitud($categoria, 1, 100)
    ) {

        $errores[] = 'La categoria no puede superar los 100 caracteres.';

    }


    // ==========================================
    // 3.5 VALIDAR COSTO
    // ==========================================

    if (!validarMonto($costo, true)) {

        $errores[] = 'El costo debe ser un monto valido y no negativo.';

    }


    // ==========================================
    // 3.6 VALIDAR PRECIO DE VENTA
    // ==========================================

    if (!validarMonto($precioVenta, false)) {

        $errores[] = 'El precio de venta debe ser mayor que cero y tener como maximo dos decimales.';

    }


    // ==========================================
    // 3.7 VALIDAR EXISTENCIAS
    // ==========================================

    if (!validarExistencias($stockActual)) {

        $errores[] = 'Las existencias iniciales deben ser un numero entero igual o mayor que cero.';

    }


    // ==========================================
    // 3.8 VALIDAR STOCK MINIMO
    // ==========================================

    if (!validarExistencias($stockMinimo)) {

        $errores[] = 'El stock minimo debe ser un numero entero igual o mayor que cero.';

    }


    // ==========================================
    // 4. COMPROBAR CODIGO DUPLICADO
    // ==========================================

    if (empty($errores)) {

        try {

            $consultaCodigo = $pdo->prepare(
                "SELECT id_producto
                 FROM productos
                 WHERE codigo_barras = :codigo
                 LIMIT 1"
            );

            $consultaCodigo->execute([
                'codigo' => $codigoBarras
            ]);

            if ($consultaCodigo->fetch()) {

                $errores[] = 'Ya existe un producto registrado con ese codigo de barras.';

            }

        } catch (PDOException $e) {

            error_log(
                'Error al verificar codigo de barras: ' .
                $e->getMessage()
            );

            $errores[] = 'No fue posible validar el codigo de barras.';

        }

    }


    // ==========================================
    // 5. REGISTRAR PRODUCTO
    // ==========================================

    if (empty($errores)) {

        try {

            // Iniciar transaccion.
            $pdo->beginTransaction();


            // ==================================
            // 5.1 INSERTAR PRODUCTO
            // ==================================

            $consultaProducto = $pdo->prepare(
                "INSERT INTO productos (

                    codigo_barras,

                    nombre,

                    categoria,

                    costo,

                    precio_venta,

                    stock_actual,

                    stock_minimo,

                    estado

                ) VALUES (

                    :codigo_barras,

                    :nombre,

                    :categoria,

                    :costo,

                    :precio_venta,

                    :stock_actual,

                    :stock_minimo,

                    1

                )"
            );


            $consultaProducto->execute([

                'codigo_barras' => $codigoBarras,

                'nombre' => $nombre,

                'categoria' => $categoria !== ''
                    ? $categoria
                    : null,

                'costo' => $costo,

                'precio_venta' => $precioVenta,

                'stock_actual' => (int) $stockActual,

                'stock_minimo' => (int) $stockMinimo

            ]);


            // Obtener ID del producto registrado.

            $idProducto = (int) $pdo->lastInsertId();


            // ==================================
            // 5.2 REGISTRAR EXISTENCIAS INICIALES
            // ==================================

            /**
             * Si el producto comienza con existencias,
             * registramos automaticamente una entrada
             * en el historial de inventario.
             */

            if ((int) $stockActual > 0) {

                $usuarioActual = obtenerUsuarioActual();

                $consultaMovimiento = $pdo->prepare(
                    "INSERT INTO movimientos_inventario (

                        id_producto,

                        id_usuario,

                        id_venta,

                        tipo_movimiento,

                        cantidad,

                        observaciones

                    ) VALUES (

                        :id_producto,

                        :id_usuario,

                        NULL,

                        'entrada',

                        :cantidad,

                        :observaciones

                    )"
                );


                $consultaMovimiento->execute([

                    'id_producto' => $idProducto,

                    'id_usuario' => (int) $usuarioActual['id_usuario'],

                    'cantidad' => (int) $stockActual,

                    'observaciones' => 'Registro inicial de existencias del producto.'

                ]);

            }


            // ==================================
            // 5.3 CONFIRMAR TRANSACCION
            // ==================================

            $pdo->commit();


            establecerMensaje(
                'success',
                'El producto fue registrado correctamente.'
            );

            redirigir('inventario/index.php');


        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            error_log(
                'Error PDO al registrar producto: ' .
                $e->getMessage()
            );


            if (
                isset($e->errorInfo[1]) &&
                (int) $e->errorInfo[1] === 1062
            ) {

                $errores[] = 'El codigo de barras ya se encuentra registrado.';

            } else {

                $errores[] = 'No fue posible registrar el producto en la base de datos.';

            }


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            error_log(
                'Error al registrar producto: ' .
                $e->getMessage()
            );

            $errores[] = 'Ocurrio un error al registrar el producto.';

        }

    }

}


// ==========================================
// 6. CARGAR CABECERA
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


            <!-- ENCABEZADO -->

            <div class="mb-4">

                <h2
                    class="fw-bold"
                    style="color: #145c46;"
                >

                    Registrar producto

                </h2>

                <p class="text-muted">

                    Ingresa la informacion del nuevo producto
                    que formara parte del inventario.

                </p>

            </div>


            <!-- FORMULARIO -->

            <div class="row justify-content-center">

                <div class="col-12 col-xl-10">

                    <div class="card border-0 shadow-sm rounded-4">

                        <div class="card-body p-4 p-md-5">


                            <!-- ENCABEZADO DEL FORMULARIO -->

                            <div class="d-flex align-items-center gap-3 mb-4">

                                <div class="product-form-icon">

                                    <i class="bi bi-box-seam"></i>

                                </div>

                                <div>

                                    <h5 class="fw-bold mb-1">

                                        Informacion del producto

                                    </h5>

                                    <small class="text-muted">

                                        Los campos marcados son obligatorios.

                                    </small>

                                </div>

                            </div>


                            <!-- ERRORES -->

                            <?php if (!empty($errores)): ?>

                                <div class="alert alert-danger">

                                    <strong>

                                        No fue posible registrar el producto:

                                    </strong>

                                    <ul class="mb-0 mt-2">

                                        <?php foreach ($errores as $error): ?>

                                            <li>

                                                <?= escapar($error) ?>

                                            </li>

                                        <?php endforeach; ?>

                                    </ul>

                                </div>

                            <?php endif; ?>


                            <!-- FORMULARIO -->

                            <form method="POST" action="">

                                <?= campoTokenCSRF() ?>


                                <!-- IDENTIFICACION -->

                                <h6 class="fw-bold mb-3" style="color: #145c46;">

                                    Identificacion del producto

                                </h6>


                                <div class="row g-3 mb-4">


                                    <!-- CODIGO -->
                                    
                                    <div class="col-md-6">

                                        <label for="codigo_barras" class="form-label fw-semibold">
                                            Código de barras
                                        </label>

                                        <div class="input-group">

                                            <input
                                                type="text"
                                                class="form-control"
                                                id="codigo_barras"
                                                name="codigo_barras"
                                                maxlength="50"
                                                value="<?= escapar($codigoBarras) ?>"
                                                placeholder="Escribe o genera un código"
                                                required
                                            >

                                            <button
                                                type="button"
                                                class="btn btn-outline-success"
                                                id="btnGenerarCodigo"
                                                data-url="<?= escapar(urlSistema('inventario/generar_codigo.php')) ?>"
                                            >
                                                <i class="bi bi-magic"></i>
                                                Generar
                                            </button>

                                        </div>

                                        <div class="form-text">
                                            Puedes escribir un código manualmente o generar uno aleatorio.
                                        </div>

                                        <div
                                            id="resultado_codigo"
                                            class="small mt-1"
                                            aria-live="polite"
                                        ></div>

                                    </div>


                                    <!-- NOMBRE -->

                                    <div class="col-12 col-md-6">

                                        <label class="form-label fw-semibold">

                                            Nombre del producto *

                                        </label>

                                        <input
                                            type="text"
                                            name="nombre"
                                            class="form-control"
                                            maxlength="150"
                                            value="<?= escapar($nombre) ?>"
                                            placeholder="Ingrese el nombre del producto"
                                            required
                                        >

                                    </div>


                                    <!-- CATEGORIA -->

                                    <div class="col-12">

                                        <label class="form-label fw-semibold">

                                            Categoria

                                        </label>

                                        <input
                                            type="text"
                                            name="categoria"
                                            class="form-control"
                                            maxlength="100"
                                            value="<?= escapar($categoria) ?>"
                                            placeholder="Ejemplo: Bebidas, alimentos, limpieza..."
                                        >

                                        <small class="text-muted">

                                            Puedes dejar este campo vacio.

                                        </small>

                                    </div>

                                </div>


                                <!-- INFORMACION ECONOMICA -->

                                <h6 class="fw-bold mb-3" style="color: #145c46;">

                                    Informacion de precios

                                </h6>


                                <div class="row g-3 mb-4">


                                    <!-- COSTO -->

                                    <div class="col-12 col-md-6">

                                        <label class="form-label fw-semibold">

                                            Costo de adquisicion (₡) *

                                        </label>

                                        <input
                                            type="number"
                                            name="costo"
                                            class="form-control"
                                            min="0"
                                            max="99999999.99"
                                            step="0.01"
                                            value="<?= escapar($costo) ?>"
                                            required
                                        >

                                    </div>


                                    <!-- PRECIO -->

                                    <div class="col-12 col-md-6">

                                        <label class="form-label fw-semibold">

                                            Precio de venta (₡) *

                                        </label>

                                        <input
                                            type="number"
                                            name="precio_venta"
                                            class="form-control"
                                            min="0.01"
                                            max="99999999.99"
                                            step="0.01"
                                            value="<?= escapar($precioVenta) ?>"
                                            required
                                        >

                                    </div>

                                </div>


                                <!-- INFORMACION DE INVENTARIO -->

                                <h6 class="fw-bold mb-3" style="color: #145c46;">

                                    Control de existencias

                                </h6>


                                <div class="row g-3 mb-4">


                                    <!-- EXISTENCIAS INICIALES -->

                                    <div class="col-12 col-md-6">

                                        <label class="form-label fw-semibold">

                                            Existencias iniciales *

                                        </label>

                                        <input
                                            type="number"
                                            name="stock_actual"
                                            class="form-control"
                                            min="0"
                                            step="1"
                                            value="<?= escapar($stockActual) ?>"
                                            required
                                        >

                                        <small class="text-muted">

                                            Cantidad de unidades disponibles
                                            al registrar el producto.

                                        </small>

                                    </div>


                                    <!-- STOCK MINIMO -->

                                    <div class="col-12 col-md-6">

                                        <label class="form-label fw-semibold">

                                            Stock minimo *

                                        </label>

                                        <input
                                            type="number"
                                            name="stock_minimo"
                                            class="form-control"
                                            min="0"
                                            step="1"
                                            value="<?= escapar($stockMinimo) ?>"
                                            required
                                        >

                                        <small class="text-muted">

                                            Se utilizara para identificar
                                            productos que necesitan reposicion.

                                        </small>

                                    </div>

                                </div>


                                <!-- INFORMACION -->

                                <div class="alert alert-light border">

                                    <i class="bi bi-info-circle me-2"></i>

                                    Si registras existencias iniciales mayores
                                    a cero, el sistema creara automaticamente
                                    un movimiento de entrada en el historial
                                    del inventario.

                                </div>


                                <!-- BOTONES -->

                                <div class="d-flex flex-column flex-sm-row
                                            justify-content-between gap-2 mt-4">


                                    <a
                                        href="<?= escapar(urlSistema('inventario/index.php')) ?>"
                                        class="btn btn-outline-secondary px-4"
                                    >

                                        <i class="bi bi-arrow-left me-1"></i>

                                        Volver al inventario

                                    </a>


                                    <button
                                        type="submit"
                                        class="btn btn-success px-4"
                                    >

                                        <i class="bi bi-save me-1"></i>

                                        Registrar producto

                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>


<!-- ====================================== -->
<!-- ESTILOS DEL MODULO -->
<!-- ====================================== -->

<style>

    .product-form-icon {

        width: 55px;

        height: 55px;

        border-radius: 12px;

        background-color: #e0f2e9;

        color: #145c46;

        display: flex;

        align-items: center;

        justify-content: center;

        font-size: 27px;

    }

</style>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const boton = document.getElementById('btnGenerarCodigo');
    const campoCodigo = document.getElementById('codigo_barras');
    const resultado = document.getElementById('resultado_codigo');

    if (!boton || !campoCodigo || !resultado) {
        return;
    }

    boton.addEventListener('click', async function () {

        const url = boton.dataset.url;
        const tokenCSRF = document.querySelector(
            'input[name="csrf_token"]'
        );

        if (!tokenCSRF) {
            resultado.textContent = 'No se encontró el token de seguridad.';
            resultado.className = 'small mt-1 text-danger';
            return;
        }

        boton.disabled = true;
        resultado.textContent = 'Generando código...';
        resultado.className = 'small mt-1 text-muted';

        const textoOriginal = boton.innerHTML;

        boton.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Generando';

        try {

            const datos = new FormData();

            datos.append('csrf_token', tokenCSRF.value);

            const respuesta = await fetch(url, {
                method: 'POST',
                body: datos,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            const contenido = await respuesta.json();

            if (!respuesta.ok || !contenido.success) {
                throw new Error(
                    contenido.message || 'No fue posible generar el código.'
                );
            }

            campoCodigo.value = contenido.codigo;

            resultado.textContent =
                'Código generado correctamente: ' + contenido.codigo;

            resultado.className = 'small mt-1 text-success';

            campoCodigo.dispatchEvent(
                new Event('input', { bubbles: true })
            );

        } catch (error) {

            resultado.textContent =
                error.message || 'Ocurrió un error inesperado.';

            resultado.className = 'small mt-1 text-danger';

        } finally {

            boton.disabled = false;
            boton.innerHTML = textoOriginal;

        }

    });

});

</script>

<?php

// Cargar pie de pagina.

require_once __DIR__ . '/../includes/footer.php';

?>
