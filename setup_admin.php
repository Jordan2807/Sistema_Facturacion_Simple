<?php

/**
 * CONFIGURACION INICIAL DEL SISTEMA
 * Sistema de Facturacion Sencilla y Control de Inventario.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validators.php';
require_once __DIR__ . '/includes/csrf.php';


// ==========================================
// 1. COMPROBAR SI EXISTE UN ADMINISTRADOR
// ==========================================

$consultaAdmin = $pdo->query(
    "SELECT COUNT(*)
     FROM usuarios
     WHERE rol = 'administrador'"
);

$cantidadAdministradores = (int) $consultaAdmin->fetchColumn();

// Si existe un administrador, salir inmediatamente.
if ($cantidadAdministradores > 0) {

    redirigir('login.php');

}


// ==========================================
// 2. RESTRINGIR ACCESO A LOCALHOST
// ==========================================

$ipCliente = $_SERVER['REMOTE_ADDR'] ?? '';

$accesoLocal = in_array(
    $ipCliente,
    [
        '127.0.0.1',
        '::1',
        '::ffff:127.0.0.1'
    ],
    true
);

if (!$accesoLocal) {

    http_response_code(403);

    exit(
        'Acceso denegado. La configuracion inicial debe realizarse desde el servidor local.'
    );

}


// ==========================================
// 3. VARIABLES INICIALES
// ==========================================

$tituloPagina = 'Configuracion inicial';

$errores = [];

$administradorCreado = false;

$setupBloqueado = false;

$nombre = '';

$correo = '';



// ==========================================
// 4. COMPROBAR SI EXISTE UN ADMINISTRADOR
// ==========================================

/**
 * Contamos todos los administradores registrados,
 * independientemente de que sus cuentas esten
 * activas o desactivadas.
 *
 * Esto evita que una cuenta desactivada permita
 * volver a utilizar el instalador.
 */

$consultaAdmin = $pdo->query(
    "SELECT COUNT(*)
     FROM usuarios
     WHERE rol = 'administrador'"
);

$cantidadAdministradores = (int) $consultaAdmin->fetchColumn();

// Si existe al menos un administrador, bloquear.
$setupBloqueado = $cantidadAdministradores > 0;


// ==========================================
// 5. PROCESAR EL FORMULARIO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Comprobar nuevamente el estado del instalador.
    if ($setupBloqueado) {

        http_response_code(403);

    } else {

        // Verificar proteccion CSRF.
        verificarTokenCSRF();


        // ==================================
        // 5.1 OBTENER LOS DATOS
        // ==================================

        $nombreEntrada = $_POST['nombre'] ?? '';

        $correoEntrada = $_POST['correo'] ?? '';

        $contrasenaEntrada = $_POST['contrasena'] ?? '';

        $confirmacionEntrada = $_POST['confirmar_contrasena'] ?? '';


        // Comprobar tipos de datos recibidos.

        $nombre = is_string($nombreEntrada)
            ? trim($nombreEntrada)
            : '';

        $correo = is_string($correoEntrada)
            ? strtolower(trim($correoEntrada))
            : '';

        $contrasena = is_string($contrasenaEntrada)
            ? $contrasenaEntrada
            : '';

        $confirmacion = is_string($confirmacionEntrada)
            ? $confirmacionEntrada
            : '';


        // ==================================
        // 5.2 VALIDAR EL NOMBRE
        // ==================================

        if (!validarRequerido($nombre)) {

            $errores[] = 'El nombre completo es obligatorio.';

        } elseif (!validarLongitud($nombre, 2, 100)) {

            $errores[] = 'El nombre debe tener entre 2 y 100 caracteres.';

        }


        // ==================================
        // 5.3 VALIDAR EL CORREO
        // ==================================

        if (!validarRequerido($correo)) {

            $errores[] = 'El correo electronico es obligatorio.';

        } elseif (
            !validarCorreo($correo) ||
            strlen($correo) > 150
        ) {

            $errores[] = 'El correo electronico no tiene un formato valido.';

        }


        // ==================================
        // 5.4 VALIDAR LA CONTRASENA
        // ==================================

        if ($contrasena === '') {

            $errores[] = 'La contrasena es obligatoria.';

        } elseif (
            strlen($contrasena) < 10 ||
            strlen($contrasena) > 72
        ) {

            $errores[] = 'La contrasena debe tener entre 10 y 72 bytes.';

        }


        // ==================================
        // 5.5 CONFIRMAR LA CONTRASENA
        // ==================================

        if ($contrasena !== $confirmacion) {

            $errores[] = 'Las contrasenas no coinciden.';

        }


        // ==================================
        // 6. REGISTRAR EL PRIMER ADMINISTRADOR
        // ==================================

        if (empty($errores)) {

            // Nombre unico del bloqueo en MySQL.
            $nombreBloqueo = 'pulperia_elena_setup_admin';

            $bloqueoAdquirido = false;


            try {

                // ==================================
                // 6.1 ADQUIRIR BLOQUEO EN MYSQL
                // ==================================

                /**
                 * Evita que dos solicitudes simultaneas
                 * creen dos administradores iniciales.
                 */

                $consultaBloqueo = $pdo->prepare(
                    'SELECT GET_LOCK(:nombre, 5)'
                );

                $consultaBloqueo->execute([
                    'nombre' => $nombreBloqueo
                ]);

                $resultadoBloqueo = $consultaBloqueo->fetchColumn();

                if ((int) $resultadoBloqueo !== 1) {

                    throw new RuntimeException(
                        'No fue posible iniciar el registro. Intenta nuevamente.'
                    );

                }

                $bloqueoAdquirido = true;


                // ==================================
                // 6.2 VOLVER A COMPROBAR ADMINISTRADORES
                // ==================================

                /**
                 * La comprobacion se realiza nuevamente
                 * despues de adquirir el bloqueo.
                 */

                $consultaVerificacion = $pdo->query(
                    "SELECT COUNT(*)
                     FROM usuarios
                     WHERE rol = 'administrador'"
                );

                $cantidadAdministradores = (int) $consultaVerificacion->fetchColumn();


                // Si otro administrador ya fue creado,
                // impedir el registro.
                if ($cantidadAdministradores > 0) {

                    $setupBloqueado = true;

                } else {


                    // ==================================
                    // 6.3 GENERAR HASH DE CONTRASENA
                    // ==================================

                    $contrasenaHash = password_hash(
                        $contrasena,
                        PASSWORD_DEFAULT
                    );

                    if ($contrasenaHash === false) {

                        throw new RuntimeException(
                            'No fue posible proteger la contrasena.'
                        );

                    }


                    // ==================================
                    // 6.4 INICIAR TRANSACCION
                    // ==================================

                    $pdo->beginTransaction();


                    // ==================================
                    // 6.5 INSERTAR ADMINISTRADOR
                    // ==================================

                    $consultaInsertar = $pdo->prepare(
                        "INSERT INTO usuarios (
                            nombre,
                            correo,
                            contrasena_hash,
                            rol,
                            estado
                        ) VALUES (
                            :nombre,
                            :correo,
                            :contrasena_hash,
                            'administrador',
                            1
                        )"
                    );


                    $consultaInsertar->execute([

                        'nombre' => $nombre,

                        'correo' => $correo,

                        'contrasena_hash' => $contrasenaHash

                    ]);


                    // Confirmar la transaccion.
                    $pdo->commit();


                    // Actualizar estado del instalador.
                    $administradorCreado = true;

                    $setupBloqueado = true;

                }

            } catch (PDOException $e) {

                // Revertir cualquier transaccion pendiente.
                if ($pdo->inTransaction()) {

                    $pdo->rollBack();

                }

                // Guardar el error detallado en el servidor.
                error_log(
                    'Error PDO al crear administrador: ' .
                    $e->getMessage()
                );


                // Detectar correo duplicado.
                if (
                    isset($e->errorInfo[1]) &&
                    (int) $e->errorInfo[1] === 1062
                ) {

                    $errores[] = 'El correo electronico ya se encuentra registrado.';

                } else {

                    $errores[] = 'Ocurrio un error al registrar el administrador.';

                }

            } catch (Throwable $e) {

                // Revertir la transaccion si existe.
                if ($pdo->inTransaction()) {

                    $pdo->rollBack();

                }

                // Registrar error internamente.
                error_log(
                    'Error en configuracion inicial: ' .
                    $e->getMessage()
                );

                $errores[] = 'No fue posible completar el registro. Intenta nuevamente.';


            } finally {

                // ==================================
                // 6.6 LIBERAR BLOQUEO DE MYSQL
                // ==================================

                if ($bloqueoAdquirido) {

                    try {

                        $consultaLiberar = $pdo->prepare(
                            'SELECT RELEASE_LOCK(:nombre)'
                        );

                        $consultaLiberar->execute([
                            'nombre' => $nombreBloqueo
                        ]);

                    } catch (Throwable $e) {

                        error_log(
                            'Error al liberar bloqueo MySQL: ' .
                            $e->getMessage()
                        );

                    }

                }

            }

            // ==========================================
            // 7. REDIRIGIR SI YA EXISTE UN ADMINISTRADOR
            // ==========================================

            if ($setupBloqueado) {

                redirigir('login.php');

            }

        }

    }

}


// ==========================================
// 7. CARGAR CABECERA
// ==========================================

require_once __DIR__ . '/includes/header.php';

?>

<!-- ====================================== -->
<!-- CONTENIDO PRINCIPAL -->
<!-- ====================================== -->

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-12 col-md-8 col-lg-6">

            <div class="card border-0 shadow-sm rounded-4">

                <div class="card-body p-4 p-md-5">

                    <!-- ENCABEZADO -->

                    <div class="text-center mb-4">

                        <div style="font-size: 45px;">
                            👤
                        </div>

                        <h2
                            class="fw-bold"
                            style="color: #145c46;"
                        >

                            Configuracion inicial

                        </h2>

                        <p class="text-muted">

                            Registro del administrador principal del sistema.

                        </p>

                    </div>


                    <!-- ================================== -->
                    <!-- ADMINISTRADOR CREADO -->
                    <!-- ================================== -->

                    <?php if ($administradorCreado): ?>

                        <div class="alert alert-success text-center">

                            <h5 class="fw-bold">

                                ¡Administrador creado correctamente!

                            </h5>

                            <p class="mb-0">

                                La cuenta administrativa ha sido registrada
                                correctamente en la base de datos.

                            </p>

                        </div>

                        <div class="alert alert-info">

                            <strong>Proteccion del sistema:</strong>

                            El instalador permanece disponible en el proyecto,
                            pero se encuentra bloqueado porque ya existe
                            un administrador.

                            No necesitas eliminar este archivo.

                        </div>

                        <div class="text-center">

                            <a
                                href="<?= escapar(urlSistema()) ?>"
                                class="btn btn-success px-4"
                            >

                                Ir al inicio

                            </a>

                        </div>


                    <!-- ================================== -->
                    <!-- INSTALADOR BLOQUEADO -->
                    <!-- ================================== -->

                    <?php elseif ($setupBloqueado): ?>

                        <div class="text-center">

                            <div style="font-size: 50px;">
                                🔒
                            </div>

                            <h4 class="fw-bold text-success">

                                Sistema configurado

                            </h4>

                            <p class="text-muted">

                                Ya existe un administrador registrado
                                en esta instalacion.

                            </p>

                        </div>

                        <div class="alert alert-info">

                            <strong>Registro inicial bloqueado.</strong>

                            <p class="mb-0 mt-2">

                                Por motivos de seguridad, no es posible
                                crear otro administrador desde este formulario.

                                El archivo permanece en el proyecto para
                                futuras instalaciones.

                            </p>

                        </div>

                        <div class="text-center">

                            <a
                                href="<?= escapar(urlSistema()) ?>"
                                class="btn btn-success px-4"
                            >

                                Ir al inicio

                            </a>

                        </div>


                    <!-- ================================== -->
                    <!-- FORMULARIO DE REGISTRO -->
                    <!-- ================================== -->

                    <?php else: ?>


                        <!-- ERRORES DE VALIDACION -->

                        <?php if (!empty($errores)): ?>

                            <div class="alert alert-danger">

                                <strong>
                                    Revisa los siguientes errores:
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


                        <!-- INFORMACION DEL REGISTRO -->

                        <div class="alert alert-light border">

                            <small class="text-muted">

                                Esta es una instalacion nueva.
                                Debes registrar al administrador principal
                                antes de utilizar el sistema.

                            </small>

                        </div>


                        <!-- FORMULARIO -->

                        <form method="POST" action="">

                            <?= campoTokenCSRF() ?>


                            <!-- NOMBRE -->

                            <div class="mb-3">

                                <label class="form-label fw-semibold">

                                    Nombre completo

                                </label>

                                <input
                                    type="text"
                                    name="nombre"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= escapar($nombre) ?>"
                                    placeholder="Ingrese su nombre"
                                    autocomplete="name"
                                    required
                                >

                            </div>


                            <!-- CORREO -->

                            <div class="mb-3">

                                <label class="form-label fw-semibold">

                                    Correo electronico

                                </label>

                                <input
                                    type="email"
                                    name="correo"
                                    class="form-control"
                                    maxlength="150"
                                    value="<?= escapar($correo) ?>"
                                    placeholder="ejemplo@correo.com"
                                    autocomplete="email"
                                    required
                                >

                            </div>


                            <!-- CONTRASENA -->

                            <div class="mb-3">

                                <label class="form-label fw-semibold">

                                    Contrasena

                                </label>

                                <input
                                    type="password"
                                    name="contrasena"
                                    class="form-control"
                                    minlength="10"
                                    maxlength="72"
                                    placeholder="Minimo 10 caracteres"
                                    autocomplete="new-password"
                                    required
                                >

                                <small class="text-muted">

                                    Debe contener al menos 10 caracteres.

                                </small>

                            </div>


                            <!-- CONFIRMACION DE CONTRASENA -->

                            <div class="mb-4">

                                <label class="form-label fw-semibold">

                                    Confirmar contrasena

                                </label>

                                <input
                                    type="password"
                                    name="confirmar_contrasena"
                                    class="form-control"
                                    minlength="10"
                                    maxlength="72"
                                    placeholder="Repita su contrasena"
                                    autocomplete="new-password"
                                    required
                                >

                            </div>


                            <!-- BOTON -->

                            <div class="d-grid">

                                <button
                                    type="submit"
                                    class="btn btn-success btn-lg"
                                >

                                    Crear administrador

                                </button>

                            </div>

                        </form>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<?php

// ==========================================
// 8. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/includes/footer.php';

?>
