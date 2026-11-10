<?php

/**
 * EDICION DE USUARIOS
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite modificar nombre, correo y rol de los usuarios.
 * Protege la existencia del ultimo administrador activo.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/validators.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

/** @var PDO $pdo */

// Verificar permisos.
requerirRol('administrador');


// ==========================================
// 2. IDENTIFICAR USUARIO QUE SE EDITARA
// ==========================================

$idEntrada = $_GET['id'] ?? '';

$idUsuarioEditar = filter_var(
    $idEntrada,
    FILTER_VALIDATE_INT
);

if (
    $idUsuarioEditar === false ||
    $idUsuarioEditar === null ||
    $idUsuarioEditar <= 0
) {

    establecerMensaje(
        'error',
        'El identificador del usuario no es valido.'
    );

    redirigir('admin/usuarios.php');

}


// ==========================================
// 3. OBTENER ADMINISTRADOR ACTUAL
// ==========================================

$administradorActual = obtenerUsuarioActual();

$idAdministradorActual = (int) $administradorActual['id_usuario'];


// ==========================================
// 4. CONSULTAR USUARIO QUE SE EDITARA
// ==========================================

$consultaUsuario = $pdo->prepare(
    "SELECT
        id_usuario,
        nombre,
        correo,
        rol,
        estado
     FROM usuarios
     WHERE id_usuario = :id_usuario
     LIMIT 1"
);

$consultaUsuario->execute([
    'id_usuario' => $idUsuarioEditar
]);

$usuarioBD = $consultaUsuario->fetch();


if (!$usuarioBD) {

    establecerMensaje(
        'error',
        'El usuario solicitado no existe.'
    );

    redirigir('admin/usuarios.php');

}


// ==========================================
// 5. DETERMINAR SI ES LA CUENTA PROPIA
// ==========================================

$esUsuarioActual = (
    $idUsuarioEditar === $idAdministradorActual
);


// ==========================================
// 6. VARIABLES INICIALES
// ==========================================

$tituloPagina = 'Editar usuario';

$errores = [];

$nombre = $usuarioBD['nombre'];

$correo = $usuarioBD['correo'];

$rol = $usuarioBD['rol'];


// ==========================================
// 7. PROCESAR FORMULARIO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();


    // ==========================================
    // 7.1 OBTENER DATOS
    // ==========================================

    $nombreEntrada = $_POST['nombre'] ?? '';

    $correoEntrada = $_POST['correo'] ?? '';

    $rolEntrada = $_POST['rol'] ?? '';


    $nombre = is_string($nombreEntrada)
        ? trim($nombreEntrada)
        : '';

    $correo = is_string($correoEntrada)
        ? strtolower(trim($correoEntrada))
        : '';


    // El administrador no puede modificar su propio rol.

    if ($esUsuarioActual) {

        $rol = $usuarioBD['rol'];

    } else {

        $rol = is_string($rolEntrada)
            ? $rolEntrada
            : '';

    }


    // ==========================================
    // 7.2 VALIDAR NOMBRE
    // ==========================================

    if (!validarRequerido($nombre)) {

        $errores[] = 'El nombre es obligatorio.';

    } elseif (!validarLongitud($nombre, 2, 100)) {

        $errores[] = 'El nombre debe tener entre 2 y 100 caracteres.';

    }


    // ==========================================
    // 7.3 VALIDAR CORREO
    // ==========================================

    if (!validarRequerido($correo)) {

        $errores[] = 'El correo electronico es obligatorio.';

    } elseif (
        !validarCorreo($correo) ||
        strlen($correo) > 150
    ) {

        $errores[] = 'El correo electronico no tiene un formato valido.';

    }


    // ==========================================
    // 7.4 VALIDAR ROL
    // ==========================================

    if (!validarRolUsuario($rol)) {

        $errores[] = 'El rol seleccionado no es valido.';

    }


    // ==========================================
    // 7.5 COMPROBAR CORREO DUPLICADO
    // ==========================================

    if (empty($errores)) {

        $consultaCorreo = $pdo->prepare(
            "SELECT id_usuario
             FROM usuarios
             WHERE correo = :correo
             AND id_usuario <> :id_usuario
             LIMIT 1"
        );

        $consultaCorreo->execute([

            'correo' => $correo,

            'id_usuario' => $idUsuarioEditar

        ]);

        if ($consultaCorreo->fetch()) {

            $errores[] = 'Ese correo electronico ya pertenece a otro usuario.';

        }

    }


    // ==========================================
    // 8. GUARDAR MODIFICACIONES
    // ==========================================

    if (empty($errores)) {

        $bloqueoAdquirido = false;

        $nombreBloqueo = 'pulperia_elena_admins_guard';

        try {

            // ==================================
            // 8.1 PROTEGER CAMBIOS DE ROL ADMINISTRATIVO
            // ==================================

            /**
             * El bloqueo evita que dos administradores
             * realicen simultaneamente cambios que
             * puedan dejar el sistema sin administradores activos.
             */

            if (!$esUsuarioActual && $rol !== $usuarioBD['rol']) {

                $consultaBloqueo = $pdo->prepare(
                    'SELECT GET_LOCK(:nombre, 5)'
                );

                $consultaBloqueo->execute([
                    'nombre' => $nombreBloqueo
                ]);

                if ((int) $consultaBloqueo->fetchColumn() !== 1) {

                    throw new RuntimeException(
                        'No fue posible procesar el cambio de rol. Intenta nuevamente.'
                    );

                }

                $bloqueoAdquirido = true;

            }


            // ==================================
            // 8.2 INICIAR TRANSACCION
            // ==================================

            $pdo->beginTransaction();


            // Volver a consultar el usuario con bloqueo.
            // Esto permite trabajar con su estado actualizado.

            $consultaActualizada = $pdo->prepare(
                "SELECT
                    id_usuario,
                    nombre,
                    correo,
                    rol,
                    estado
                 FROM usuarios
                 WHERE id_usuario = :id_usuario
                 FOR UPDATE"
            );

            $consultaActualizada->execute([
                'id_usuario' => $idUsuarioEditar
            ]);

            $usuarioActualizado = $consultaActualizada->fetch();


            if (!$usuarioActualizado) {

                throw new RuntimeException(
                    'El usuario ya no existe.'
                );

            }


            // Reforzar proteccion de cuenta propia.

            if ($esUsuarioActual) {

                $rol = $usuarioActualizado['rol'];

            }


            // ==================================
            // 8.3 PROTEGER ULTIMO ADMINISTRADOR
            // ==================================

            if (
                $usuarioActualizado['rol'] === 'administrador' &&
                (int) $usuarioActualizado['estado'] === 1 &&
                $rol !== 'administrador'
            ) {

                $consultaAdministradores = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM usuarios
                     WHERE rol = 'administrador'
                     AND estado = 1
                     AND id_usuario <> :id_usuario"
                );

                $consultaAdministradores->execute([
                    'id_usuario' => $idUsuarioEditar
                ]);

                $otrosAdministradores = (int)
                    $consultaAdministradores->fetchColumn();


                if ($otrosAdministradores === 0) {

                    throw new DomainException(
                        'No puedes cambiar el rol del ultimo administrador activo. Primero debes contar con otro administrador activo.'
                    );

                }

            }


            // ==================================
            // 8.4 ACTUALIZAR INFORMACION
            // ==================================

            $consultaGuardar = $pdo->prepare(
                "UPDATE usuarios
                 SET nombre = :nombre,
                     correo = :correo,
                     rol = :rol
                 WHERE id_usuario = :id_usuario"
            );

            $consultaGuardar->execute([

                'nombre' => $nombre,

                'correo' => $correo,

                'rol' => $rol,

                'id_usuario' => $idUsuarioEditar

            ]);


            // ==================================
            // 8.5 CONFIRMAR TRANSACCION
            // ==================================

            $pdo->commit();


            // Actualizar informacion en la sesion
            // cuando el administrador edita su propia cuenta.

            if ($esUsuarioActual) {

                $_SESSION['usuario']['nombre'] = $nombre;

                $_SESSION['usuario']['correo'] = $correo;

                $_SESSION['usuario']['rol'] = $rol;

            }


            establecerMensaje(
                'success',
                'La informacion del usuario fue actualizada correctamente.'
            );

            redirigir('admin/usuarios.php');


        } catch (DomainException $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            $errores[] = $e->getMessage();


        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            error_log(
                'Error PDO al editar usuario: ' .
                $e->getMessage()
            );


            if (
                isset($e->errorInfo[1]) &&
                (int) $e->errorInfo[1] === 1062
            ) {

                $errores[] = 'El correo electronico ya esta registrado.';

            } else {

                $errores[] = 'No fue posible actualizar el usuario.';

            }


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();

            }

            error_log(
                'Error al editar usuario: ' .
                $e->getMessage()
            );

            $errores[] = 'Ocurrio un error al modificar el usuario.';


        } finally {

            // Liberar bloqueo administrativo.

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
                        'Error al liberar bloqueo administrativo: ' .
                        $e->getMessage()
                    );

                }

            }

        }

    }

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

        <!-- MENU LATERAL -->

        <?php require __DIR__ . '/../includes/sidebar.php'; ?>


        <!-- CONTENIDO -->

        <main class="col-12 col-lg-9 col-xl-10">

            <div class="mb-4">

                <h2 class="fw-bold" style="color: #145c46;">

                    Editar usuario

                </h2>

                <p class="text-muted">

                    Modifica la informacion de la cuenta seleccionada.

                </p>

            </div>


            <div class="row justify-content-center">

                <div class="col-12 col-xl-9">

                    <div class="card border-0 shadow-sm rounded-4">

                        <div class="card-body p-4 p-md-5">


                            <!-- INFORMACION DEL USUARIO -->

                            <div class="d-flex align-items-center gap-3 mb-4">

                                <div
                                    class="rounded-circle d-flex align-items-center justify-content-center"
                                    style="background: #e0f2e9; width: 55px; height: 55px;"
                                >

                                    <i
                                        class="bi bi-person-gear fs-3"
                                        style="color: #145c46;"
                                    ></i>

                                </div>

                                <div>

                                    <h5 class="fw-bold mb-1">

                                        Informacion de la cuenta

                                    </h5>

                                    <small class="text-muted">

                                        Usuario #<?= $idUsuarioEditar ?>

                                    </small>

                                </div>

                            </div>


                            <!-- MENSAJES DE ERROR -->

                            <?php if (!empty($errores)): ?>

                                <div class="alert alert-danger">

                                    <strong>
                                        No fue posible guardar los cambios:
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


                            <!-- AVISO CUENTA PROPIA -->

                            <?php if ($esUsuarioActual): ?>

                                <div class="alert alert-info">

                                    <strong>Estás modificando tu propia cuenta.</strong>

                                    Puedes actualizar tu nombre y correo,
                                    pero tu rol administrativo no podrá
                                    modificarse desde esta pantalla.

                                </div>

                            <?php endif; ?>


                            <!-- AVISO ULTIMO ADMINISTRADOR -->

                            <?php if (
                                $usuarioBD['rol'] === 'administrador' &&
                                (int) $usuarioBD['estado'] === 1
                            ): ?>

                                <div class="alert alert-warning">

                                    <i class="bi bi-shield-exclamation me-2"></i>

                                    Esta cuenta es un administrador activo.
                                    El sistema protegerá la existencia
                                    de al menos un administrador activo.

                                </div>

                            <?php endif; ?>


                            <!-- FORMULARIO -->

                            <form
                                method="POST"
                                action="<?= escapar(
                                    urlSistema('admin/editar_usuario.php?id=' . $idUsuarioEditar)
                                ) ?>"
                            >

                                <?= campoTokenCSRF() ?>


                                <!-- NOMBRE -->

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">

                                        Nombre completo

                                    </label>

                                    <input
                                        type="text"
                                        name="nombre"
                                        class="form-control form-control-lg"
                                        maxlength="100"
                                        value="<?= escapar($nombre) ?>"
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
                                        class="form-control form-control-lg"
                                        maxlength="150"
                                        value="<?= escapar($correo) ?>"
                                        required
                                    >

                                </div>


                                <!-- ROL -->

                                <div class="mb-4">

                                    <label class="form-label fw-semibold">

                                        Rol del usuario

                                    </label>


                                    <?php if ($esUsuarioActual): ?>

                                        <!-- No se permite editar el propio rol. -->

                                        <input
                                            type="text"
                                            class="form-control form-control-lg"
                                            value="<?= escapar(obtenerNombreRol($rol)) ?>"
                                            disabled
                                        >

                                        <small class="text-muted">

                                            Tu rol solamente puede ser
                                            modificado desde otra cuenta
                                            administrativa autorizada.

                                        </small>

                                    <?php else: ?>

                                        <select
                                            name="rol"
                                            class="form-select form-select-lg"
                                            required
                                        >

                                            <option
                                                value="administrador"
                                                <?= $rol === 'administrador' ? 'selected' : '' ?>
                                            >

                                                Administrador

                                            </option>

                                            <option
                                                value="cajero"
                                                <?= $rol === 'cajero' ? 'selected' : '' ?>
                                            >

                                                Cajero

                                            </option>

                                            <option
                                                value="almacen"
                                                <?= $rol === 'almacen' ? 'selected' : '' ?>
                                            >

                                                Empleado de almacen

                                            </option>

                                        </select>

                                    <?php endif; ?>

                                </div>


                                <!-- INFORMACION ADICIONAL -->

                                <div class="alert alert-light border">

                                    <i class="bi bi-info-circle me-2"></i>

                                    Para cambiar la contraseña del usuario,
                                    utiliza el módulo de restablecimiento
                                    de contraseñas.

                                </div>


                                <!-- BOTONES -->

                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2">

                                    <a
                                        href="<?= escapar(urlSistema('admin/usuarios.php')) ?>"
                                        class="btn btn-outline-secondary px-4"
                                    >

                                        <i class="bi bi-arrow-left me-1"></i>

                                        Volver

                                    </a>


                                    <button
                                        type="submit"
                                        class="btn btn-success px-4"
                                    >

                                        <i class="bi bi-save me-1"></i>

                                        Guardar modificaciones

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


<?php

// ==========================================
// 11. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
