<?php

/**
 * MI CUENTA
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite modificar los datos personales y la contrasena
 * del usuario que inicio sesion.
 *
 * No permite modificar el rol ni el estado de la cuenta.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/functions.php';

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/validators.php';

require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/csrf.php';


// ==========================================
// 2. VERIFICAR AUTENTICACION
// ==========================================

requerirAutenticacion();

$usuarioActual = obtenerUsuarioActual();

$idUsuario = (int) $usuarioActual['id_usuario'];

$tituloPagina = 'Mi cuenta';

$erroresDatos = [];

$erroresPassword = [];


// ==========================================
// 3. OBTENER INFORMACION ACTUAL DEL USUARIO
// ==========================================

$consultaUsuario = $pdo->prepare(
    "SELECT
        id_usuario,
        nombre,
        correo,
        contrasena_hash,
        rol,
        estado
     FROM usuarios
     WHERE id_usuario = :id_usuario
     LIMIT 1"
);

$consultaUsuario->execute([
    'id_usuario' => $idUsuario
]);

$usuarioBD = $consultaUsuario->fetch();


// Verificar que la cuenta exista y este activa.

if (
    !$usuarioBD ||
    (int) $usuarioBD['estado'] !== 1
) {

    $_SESSION = [];

    session_destroy();

    redirigir('login.php');

}


// ==========================================
// 4. VARIABLES PARA MOSTRAR INFORMACION
// ==========================================

$nombre = $usuarioBD['nombre'];

$correo = $usuarioBD['correo'];

$rol = $usuarioBD['rol'];


// ==========================================
// 5. PROCESAR FORMULARIOS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar token de seguridad.
    verificarTokenCSRF();

    $accion = $_POST['accion'] ?? '';


    // ==========================================
    // 6. ACTUALIZAR NOMBRE Y CORREO
    // ==========================================

    if ($accion === 'actualizar_datos') {

        $nombreEntrada = $_POST['nombre'] ?? '';

        $correoEntrada = $_POST['correo'] ?? '';

        $contrasenaActualEntrada = $_POST['contrasena_actual'] ?? '';

        $nombre = is_string($nombreEntrada)
            ? trim($nombreEntrada)
            : '';

        $correo = is_string($correoEntrada)
            ? strtolower(trim($correoEntrada))
            : '';

        $contrasenaActual = is_string($contrasenaActualEntrada)
            ? $contrasenaActualEntrada
            : '';


        // ==================================
        // 6.1 VALIDAR NOMBRE
        // ==================================

        if (!validarRequerido($nombre)) {

            $erroresDatos[] = 'El nombre es obligatorio.';

        } elseif (!validarLongitud($nombre, 2, 100)) {

            $erroresDatos[] = 'El nombre debe tener entre 2 y 100 caracteres.';

        }


        // ==================================
        // 6.2 VALIDAR CORREO
        // ==================================

        if (!validarRequerido($correo)) {

            $erroresDatos[] = 'El correo electronico es obligatorio.';

        } elseif (
            !validarCorreo($correo) ||
            strlen($correo) > 150
        ) {

            $erroresDatos[] = 'El correo electronico no es valido.';

        }


        // ==================================
        // 6.3 COMPROBAR CONTRASENA ACTUAL
        // ==================================

        if ($contrasenaActual === '') {

            $erroresDatos[] = 'Debes ingresar tu contraseña actual para modificar tus datos.';

        } elseif (
            !password_verify(
                $contrasenaActual,
                $usuarioBD['contrasena_hash']
            )
        ) {

            $erroresDatos[] = 'La contraseña actual es incorrecta.';

        }


        // ==================================
        // 6.4 COMPROBAR CORREO DUPLICADO
        // ==================================

        if (empty($erroresDatos)) {

            $consultaCorreo = $pdo->prepare(
                "SELECT id_usuario
                 FROM usuarios
                 WHERE correo = :correo
                 AND id_usuario <> :id_usuario
                 LIMIT 1"
            );

            $consultaCorreo->execute([

                'correo' => $correo,

                'id_usuario' => $idUsuario

            ]);

            if ($consultaCorreo->fetch()) {

                $erroresDatos[] = 'El correo electronico ya pertenece a otro usuario.';

            }

        }


        // ==================================
        // 6.5 GUARDAR MODIFICACIONES
        // ==================================

        if (empty($erroresDatos)) {

            try {

                $consultaActualizar = $pdo->prepare(
                    "UPDATE usuarios
                     SET nombre = :nombre,
                         correo = :correo
                     WHERE id_usuario = :id_usuario
                     AND estado = 1"
                );

                $consultaActualizar->execute([

                    'nombre' => $nombre,

                    'correo' => $correo,

                    'id_usuario' => $idUsuario

                ]);


                // Actualizar datos de la sesion.

                $_SESSION['usuario']['nombre'] = $nombre;

                $_SESSION['usuario']['correo'] = $correo;


                establecerMensaje(
                    'success',
                    'Tus datos personales fueron actualizados correctamente.'
                );

                redirigir('cuenta.php');

            } catch (PDOException $e) {

                error_log(
                    'Error al actualizar datos personales: ' .
                    $e->getMessage()
                );

                if (
                    isset($e->errorInfo[1]) &&
                    (int) $e->errorInfo[1] === 1062
                ) {

                    $erroresDatos[] = 'El correo electronico ya esta registrado.';

                } else {

                    $erroresDatos[] = 'No fue posible actualizar tus datos.';

                }

            }

        }

    }


    // ==========================================
    // 7. CAMBIAR CONTRASENA
    // ==========================================

    elseif ($accion === 'cambiar_contrasena') {

        $contrasenaActualEntrada = $_POST['contrasena_actual'] ?? '';

        $contrasenaNuevaEntrada = $_POST['contrasena_nueva'] ?? '';

        $confirmacionEntrada = $_POST['confirmar_contrasena'] ?? '';


        $contrasenaActual = is_string($contrasenaActualEntrada)
            ? $contrasenaActualEntrada
            : '';

        $contrasenaNueva = is_string($contrasenaNuevaEntrada)
            ? $contrasenaNuevaEntrada
            : '';

        $confirmacion = is_string($confirmacionEntrada)
            ? $confirmacionEntrada
            : '';


        // ==================================
        // 7.1 VALIDAR CONTRASENA ACTUAL
        // ==================================

        if ($contrasenaActual === '') {

            $erroresPassword[] = 'Debes ingresar tu contraseña actual.';

        } elseif (
            !password_verify(
                $contrasenaActual,
                $usuarioBD['contrasena_hash']
            )
        ) {

            $erroresPassword[] = 'La contraseña actual es incorrecta.';

        }


        // ==================================
        // 7.2 VALIDAR NUEVA CONTRASENA
        // ==================================

        if ($contrasenaNueva === '') {

            $erroresPassword[] = 'Debes ingresar una nueva contrasena.';

        } elseif (
            strlen($contrasenaNueva) < 10 ||
            strlen($contrasenaNueva) > 72
        ) {

            $erroresPassword[] = 'La nueva contraseña debe tener entre 10 y 72 bytes.';

        }


        // ==================================
        // 7.3 CONFIRMAR NUEVA CONTRASENA
        // ==================================

        if ($contrasenaNueva !== $confirmacion) {

            $erroresPassword[] = 'Las nuevas contraseñas no coinciden.';

        }


        // ==================================
        // 7.4 EVITAR REPETIR CONTRASENA
        // ==================================

        if (
            $contrasenaNueva !== '' &&
            password_verify(
                $contrasenaNueva,
                $usuarioBD['contrasena_hash']
            )
        ) {

            $erroresPassword[] = 'La nueva contraseña debe ser diferente a la actual.';

        }


        // ==================================
        // 7.5 ACTUALIZAR CONTRASENA
        // ==================================

        if (empty($erroresPassword)) {

            try {

                // Generar hash seguro.

                $nuevoHash = password_hash(
                    $contrasenaNueva,
                    PASSWORD_DEFAULT
                );

                if ($nuevoHash === false) {

                    throw new RuntimeException(
                        'No fue posible proteger la nueva contraseña.'
                    );

                }


                // Actualizar en MySQL.

                $consultaPassword = $pdo->prepare(
                    "UPDATE usuarios
                     SET contrasena_hash = :contrasena_hash
                     WHERE id_usuario = :id_usuario
                     AND estado = 1"
                );

                $consultaPassword->execute([

                    'contrasena_hash' => $nuevoHash,

                    'id_usuario' => $idUsuario

                ]);


                if ($consultaPassword->rowCount() !== 1) {

                    throw new RuntimeException(
                        'No fue posible actualizar la contraseña.'
                    );

                }


                // Renovar identificador de sesion.

                if (!session_regenerate_id(true)) {

                    throw new RuntimeException(
                        'No fue posible renovar la sesion.'
                    );

                }


                // Renovar token CSRF.

                unset($_SESSION['token_csrf']);

                obtenerTokenCSRF();


                establecerMensaje(
                    'success',
                    'Tu contraseña fue actualizada correctamente.'
                );

                redirigir('cuenta.php');

            } catch (Throwable $e) {

                error_log(
                    'Error al cambiar contrasena: ' .
                    $e->getMessage()
                );

                $erroresPassword[] = 'Ocurrio un error al cambiar la contraseña. Intenta nuevamente.';

            }

        }

    } else {

        $erroresDatos[] = 'La operacion solicitada no es valida.';

    }

}


// ==========================================
// 8. OBTENER MENSAJE TEMPORAL
// ==========================================

$mensajeFlash = obtenerMensaje();


// ==========================================
// 9. CARGAR CABECERA
// ==========================================

require_once __DIR__ . '/includes/header.php';

?>

<!-- ====================================== -->
<!-- CONTENIDO PRINCIPAL -->
<!-- ====================================== -->

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-12 col-xl-10">


            <!-- ENCABEZADO -->

            <div class="mb-4">

                <h2 class="fw-bold" style="color: #145c46;">

                    Mi cuenta

                </h2>

                <p class="text-muted">

                    Consulta y administra tu informacion personal
                    y las credenciales de acceso.

                </p>

            </div>


            <!-- ================================== -->
            <!-- MENSAJE GENERAL -->
            <!-- ================================== -->

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


            <div class="row g-4">


                <!-- ================================== -->
                <!-- INFORMACION PERSONAL -->
                <!-- ================================== -->

                <div class="col-12 col-lg-6">

                    <div class="card border-0 shadow-sm rounded-4 h-100">

                        <div class="card-body p-4">

                            <h5
                                class="fw-bold mb-4"
                                style="color: #145c46;"
                            >

                                <i class="bi bi-person-circle me-2"></i>

                                Datos personales

                            </h5>


                            <?php if (!empty($erroresDatos)): ?>

                                <div class="alert alert-danger">

                                    <ul class="mb-0">

                                        <?php foreach ($erroresDatos as $error): ?>

                                            <li>
                                                <?= escapar($error) ?>
                                            </li>

                                        <?php endforeach; ?>

                                    </ul>

                                </div>

                            <?php endif; ?>


                            <form method="POST" action="">

                                <?= campoTokenCSRF() ?>


                                <input
                                    type="hidden"
                                    name="accion"
                                    value="actualizar_datos"
                                >


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
                                        autocomplete="email"
                                        required
                                    >

                                </div>


                                <!-- ROL -->

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">

                                        Rol del usuario

                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        value="<?= escapar(obtenerNombreRol($rol)) ?>"
                                        disabled
                                    >

                                    <small class="text-muted">

                                        El rol solamente puede ser modificado
                                        por un administrador autorizado.

                                    </small>

                                </div>


                                <!-- CONFIRMAR CONTRASENA -->

                                <div class="mb-4">

                                    <label class="form-label fw-semibold">

                                        Confirmar contraseña actual

                                    </label>

                                    <input
                                        type="password"
                                        name="contrasena_actual"
                                        class="form-control"
                                        autocomplete="current-password"
                                        placeholder="Ingresa tu contraseña actual"
                                        required
                                    >

                                    <small class="text-muted">

                                        Necesitamos verificar tu identidad
                                        antes de modificar tus datos.

                                    </small>

                                </div>


                                <div class="d-grid">

                                    <button
                                        type="submit"
                                        class="btn btn-success"
                                    >

                                        Guardar cambios

                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>


                <!-- ================================== -->
                <!-- CAMBIAR CONTRASENA -->
                <!-- ================================== -->

                <div class="col-12 col-lg-6">

                    <div class="card border-0 shadow-sm rounded-4 h-100">

                        <div class="card-body p-4">

                            <h5
                                class="fw-bold mb-4"
                                style="color: #145c46;"
                            >

                                <i class="bi bi-shield-lock me-2"></i>

                                Cambiar contraseña

                            </h5>


                            <?php if (!empty($erroresPassword)): ?>

                                <div class="alert alert-danger">

                                    <ul class="mb-0">

                                        <?php foreach ($erroresPassword as $error): ?>

                                            <li>
                                                <?= escapar($error) ?>
                                            </li>

                                        <?php endforeach; ?>

                                    </ul>

                                </div>

                            <?php endif; ?>


                            <form method="POST" action="">

                                <?= campoTokenCSRF() ?>


                                <input
                                    type="hidden"
                                    name="accion"
                                    value="cambiar_contrasena"
                                >


                                <!-- CONTRASENA ACTUAL -->

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">

                                        Contraseña actual

                                    </label>

                                    <input
                                        type="password"
                                        name="contrasena_actual"
                                        class="form-control"
                                        autocomplete="current-password"
                                        required
                                    >

                                </div>


                                <!-- NUEVA CONTRASENA -->

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">

                                        Nueva contraseña

                                    </label>

                                    <input
                                        type="password"
                                        name="contrasena_nueva"
                                        class="form-control"
                                        minlength="10"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        placeholder="Minimo 10 caracteres"
                                        required
                                    >

                                    <small class="text-muted">

                                        Utiliza una contraseña diferente
                                        a la actual.

                                    </small>

                                </div>


                                <!-- CONFIRMACION -->

                                <div class="mb-4">

                                    <label class="form-label fw-semibold">

                                        Confirmar nueva contraseña

                                    </label>

                                    <input
                                        type="password"
                                        name="confirmar_contrasena"
                                        class="form-control"
                                        minlength="10"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        required
                                    >

                                </div>


                                <div class="d-grid">

                                    <button
                                        type="submit"
                                        class="btn btn-outline-success"
                                    >

                                        Actualizar contraseña

                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ================================== -->
            <!-- INFORMACION ADICIONAL -->
            <!-- ================================== -->

            <div class="alert alert-light border mt-4">

                <strong>Seguridad de tu cuenta:</strong>

                <p class="mb-0 mt-2">

                    Nunca compartas tu contraseña con otros usuarios.
                    Recuerda utilizar una contraseña segura y cambiarla
                    periódicamente.

                </p>

            </div>


        </div>

    </div>

</div>


<?php

// ==========================================
// 10. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/includes/footer.php';

?>
