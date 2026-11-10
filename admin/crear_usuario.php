<?php

/**
 * CREACION DE USUARIOS
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite al administrador registrar nuevos usuarios.
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

// Conexion PDO.
// @var PDO $pdo

// Verificar permisos.
requerirRol('administrador');


// ==========================================
// 2. VARIABLES INICIALES
// ==========================================

$tituloPagina = 'Crear usuario';

$errores = [];

$nombre = '';

$correo = '';

$rol = '';


// ==========================================
// 3. PROCESAR FORMULARIO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar proteccion CSRF.
    verificarTokenCSRF();


    // ==========================================
    // 3.1 OBTENER INFORMACION DEL FORMULARIO
    // ==========================================

    $nombreEntrada = $_POST['nombre'] ?? '';

    $correoEntrada = $_POST['correo'] ?? '';

    $rolEntrada = $_POST['rol'] ?? '';

    $contrasenaEntrada = $_POST['contrasena'] ?? '';

    $confirmacionEntrada = $_POST['confirmar_contrasena'] ?? '';


    $nombre = is_string($nombreEntrada)
        ? trim($nombreEntrada)
        : '';

    $correo = is_string($correoEntrada)
        ? strtolower(trim($correoEntrada))
        : '';

    $rol = is_string($rolEntrada)
        ? $rolEntrada
        : '';

    $contrasena = is_string($contrasenaEntrada)
        ? $contrasenaEntrada
        : '';

    $confirmacion = is_string($confirmacionEntrada)
        ? $confirmacionEntrada
        : '';


    // ==========================================
    // 3.2 VALIDAR NOMBRE
    // ==========================================

    if (!validarRequerido($nombre)) {

        $errores[] = 'El nombre completo es obligatorio.';

    } elseif (!validarLongitud($nombre, 2, 100)) {

        $errores[] = 'El nombre debe tener entre 2 y 100 caracteres.';

    }


    // ==========================================
    // 3.3 VALIDAR CORREO
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
    // 3.4 VALIDAR ROL
    // ==========================================

    if (!validarRolUsuario($rol)) {

        $errores[] = 'Debes seleccionar un rol valido.';

    }


    // ==========================================
    // 3.5 VALIDAR CONTRASENA
    // ==========================================

    if ($contrasena === '') {

        $errores[] = 'La contraseña inicial es obligatoria.';

    } elseif (
        strlen($contrasena) < 10 ||
        strlen($contrasena) > 72
    ) {

        $errores[] = 'La contraseña debe tener entre 10 y 72 bytes.';

    }


    // ==========================================
    // 3.6 CONFIRMAR CONTRASENA
    // ==========================================

    if ($contrasena !== $confirmacion) {

        $errores[] = 'Las contraseñas no coinciden.';

    }


    // ==========================================
    // 4. COMPROBAR CORREO DUPLICADO
    // ==========================================

    if (empty($errores)) {

        try {

            $consultaCorreo = $pdo->prepare(
                "SELECT id_usuario
                 FROM usuarios
                 WHERE correo = :correo
                 LIMIT 1"
            );

            $consultaCorreo->execute([

                'correo' => $correo

            ]);

            if ($consultaCorreo->fetch()) {

                $errores[] = 'Ya existe un usuario registrado con ese correo electronico.';

            }


        } catch (PDOException $e) {

            error_log(
                'Error al comprobar correo de usuario: ' .
                $e->getMessage()
            );

            $errores[] = 'No fue posible validar el correo electronico.';

        }

    }


    // ==========================================
    // 5. REGISTRAR USUARIO EN MYSQL
    // ==========================================

    if (empty($errores)) {

        try {

            // Generar hash seguro de contraseña.

            $contrasenaHash = password_hash(
                $contrasena,
                PASSWORD_DEFAULT
            );

            if ($contrasenaHash === false) {

                throw new RuntimeException(
                    'No fue posible proteger la contraseña.'
                );

            }


            // Preparar consulta de insercion.

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
                    :rol,
                    1
                )"
            );


            // Ejecutar insercion.

            $consultaInsertar->execute([

                'nombre' => $nombre,

                'correo' => $correo,

                'contrasena_hash' => $contrasenaHash,

                'rol' => $rol

            ]);


            // ==================================
            // 6. CONFIRMAR REGISTRO
            // ==================================

            establecerMensaje(
                'success',
                'El usuario fue registrado correctamente.'
            );

            redirigir('admin/usuarios.php');


        } catch (PDOException $e) {

            error_log(
                'Error al registrar usuario: ' .
                $e->getMessage()
            );


            // Comprobar si el correo fue registrado
            // por otra operacion simultanea.

            if (
                isset($e->errorInfo[1]) &&
                (int) $e->errorInfo[1] === 1062
            ) {

                $errores[] = 'El correo electronico ya se encuentra registrado.';

            } else {

                $errores[] = 'No fue posible registrar el usuario.';

            }

        } catch (Throwable $e) {

            error_log(
                'Error general al crear usuario: ' .
                $e->getMessage()
            );

            $errores[] = 'Ocurrio un error al crear la cuenta.';

        }

    }

}


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

            <!-- ENCABEZADO -->

            <div class="mb-4">

                <h2
                    class="fw-bold"
                    style="color: #145c46;"
                >

                    Crear usuario

                </h2>

                <p class="text-muted">

                    Registra nuevas cuentas para los empleados
                    y administradores de la pulperia.

                </p>

            </div>


            <!-- ================================== -->
            <!-- FORMULARIO -->
            <!-- ================================== -->

            <div class="row justify-content-center">

                <div class="col-12 col-xl-9">

                    <div class="card border-0 shadow-sm rounded-4">

                        <div class="card-body p-4 p-md-5">

                            <div class="d-flex align-items-center gap-3 mb-4">

                                <div
                                    class="rounded-circle d-flex align-items-center justify-content-center"
                                    style="background: #e0f2e9; width: 55px; height: 55px;"
                                >

                                    <i
                                        class="bi bi-person-plus fs-3"
                                        style="color: #145c46;"
                                    ></i>

                                </div>

                                <div>

                                    <h5 class="fw-bold mb-1">

                                        Informacion del usuario

                                    </h5>

                                    <small class="text-muted">

                                        Completa todos los campos requeridos.

                                    </small>

                                </div>

                            </div>


                            <!-- ERRORES -->

                            <?php if (!empty($errores)): ?>

                                <div class="alert alert-danger">

                                    <strong>

                                        No fue posible crear el usuario.

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
                                        class="form-control form-control-lg"
                                        maxlength="100"
                                        value="<?= escapar($nombre) ?>"
                                        placeholder="Ingrese el nombre completo"
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
                                        class="form-control form-control-lg"
                                        maxlength="150"
                                        value="<?= escapar($correo) ?>"
                                        placeholder="ejemplo@correo.com"
                                        autocomplete="email"
                                        required
                                    >

                                </div>


                                <!-- ROL -->

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">

                                        Rol del usuario

                                    </label>

                                    <select
                                        name="rol"
                                        class="form-select form-select-lg"
                                        required
                                    >

                                        <option value="">

                                            Selecciona un rol

                                        </option>

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

                                    <small class="text-muted">

                                        El rol determina las funcionalidades
                                        a las que tendra acceso el usuario.

                                    </small>

                                </div>


                                <!-- CONTRASEÑA -->

                                <div class="mb-3">

                                    <label class="form-label fw-semibold">

                                        Contraseña inicial

                                    </label>

                                    <input
                                        type="password"
                                        name="contrasena"
                                        class="form-control form-control-lg"
                                        minlength="10"
                                        maxlength="72"
                                        placeholder="Minimo 10 caracteres"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <small class="text-muted">

                                        El administrador debe entregar
                                        esta contraseña al nuevo usuario
                                        por un medio seguro.

                                    </small>

                                </div>


                                <!-- CONFIRMACION -->

                                <div class="mb-4">

                                    <label class="form-label fw-semibold">

                                        Confirmar contraseña

                                    </label>

                                    <input
                                        type="password"
                                        name="confirmar_contrasena"
                                        class="form-control form-control-lg"
                                        minlength="10"
                                        maxlength="72"
                                        placeholder="Repita la contrasena"
                                        autocomplete="new-password"
                                        required
                                    >

                                </div>


                                <!-- INFORMACION DE SEGURIDAD -->

                                <div class="alert alert-light border">

                                    <i class="bi bi-shield-lock me-2"></i>

                                    Las contraseñas se almacenan mediante
                                    un hash seguro. El sistema no guarda
                                    contraseñas en texto plano.

                                </div>


                                <!-- BOTONES -->

                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mt-4">

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

                                        <i class="bi bi-person-check me-1"></i>

                                        Registrar usuario

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
// 8. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
