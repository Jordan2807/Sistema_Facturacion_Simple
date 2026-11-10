<?php

/**
 * INICIO DE SESION
 * Sistema de Facturacion Sencilla y Control de Inventario.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validators.php';
require_once __DIR__ . '/includes/csrf.php';


// ==========================================
// 2. COMPROBAR QUE EXISTA UN ADMINISTRADOR
// ==========================================

$consultaAdmin = $pdo->query(
    "SELECT COUNT(*)
     FROM usuarios
     WHERE rol = 'administrador'"
);

$cantidadAdministradores = (int) $consultaAdmin->fetchColumn();

if ($cantidadAdministradores === 0) {

    redirigir('setup_admin.php');

}


// ==========================================
// 3. DEFINIR RUTAS SEGUN EL ROL
// ==========================================

$rutasPorRol = [

    'administrador' => 'admin/index.php',

    'cajero' => 'caja/index.php',

    'almacen' => 'inventario/index.php'

];


// ==========================================
// 4. VERIFICAR SI YA EXISTE UNA SESION
// ==========================================

$usuarioActual = obtenerUsuarioActual();

if ($usuarioActual !== null) {

    $rolActual = $usuarioActual['rol'] ?? '';

    if (isset($rutasPorRol[$rolActual])) {

        redirigir($rutasPorRol[$rolActual]);

    }

}


// ==========================================
// 5. VARIABLES INICIALES
// ==========================================

$tituloPagina = 'Iniciar sesión';

$errores = [];

$correo = '';


// ==========================================
// 6. PROCESAR FORMULARIO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar proteccion CSRF.
    verificarTokenCSRF();


    // Obtener datos enviados.
    $correoEntrada = $_POST['correo'] ?? '';

    $contrasenaEntrada = $_POST['contrasena'] ?? '';


    // Comprobar tipos de datos.
    $correo = is_string($correoEntrada)
        ? strtolower(trim($correoEntrada))
        : '';

    $contrasena = is_string($contrasenaEntrada)
        ? $contrasenaEntrada
        : '';


    // ==================================
    // 7. VALIDAR INFORMACION
    // ==================================

    if (!validarRequerido($correo)) {

        $errores[] = 'Debes ingresar tu correo electronico.';

    } elseif (
        !validarCorreo($correo) ||
        strlen($correo) > 150
    ) {

        $errores[] = 'El correo electronico no tiene un formato valido.';

    }


    if ($contrasena === '') {

        $errores[] = 'Debes ingresar tu contrasena.';

    } elseif (strlen($contrasena) > 72) {

        $errores[] = 'Las credenciales ingresadas no son validas.';

    }


    // ==================================
    // 8. CONSULTAR USUARIO EN MYSQL
    // ==================================

    if (empty($errores)) {

        try {

            $consultaUsuario = $pdo->prepare(
                "SELECT
                    id_usuario,
                    nombre,
                    correo,
                    contrasena_hash,
                    rol,
                    estado
                 FROM usuarios
                 WHERE correo = :correo
                 LIMIT 1"
            );

            $consultaUsuario->execute([

                'correo' => $correo

            ]);

            $usuario = $consultaUsuario->fetch();


            // ==================================
            // 9. COMPROBAR CREDENCIALES
            // ==================================

            if (
                !$usuario ||
                !password_verify(
                    $contrasena,
                    $usuario['contrasena_hash']
                ) ||
                (int) $usuario['estado'] !== 1 ||
                !isset($rutasPorRol[$usuario['rol']])
            ) {

                $errores[] = 'Correo electronico o contrasena incorrectos.';

            } else {


                // ==================================
                // 10. REGENERAR IDENTIFICADOR DE SESION
                // ==================================

                /**
                 * Evita mantener el identificador
                 * de sesion anterior al autenticarse.
                 */

                if (!session_regenerate_id(true)) {

                    throw new RuntimeException(
                        'No fue posible iniciar la sesion.'
                    );

                }


                // ==================================
                // 11. ALMACENAR INFORMACION DEL USUARIO
                // ==================================

                $_SESSION['usuario'] = [

                    'id_usuario' => (int) $usuario['id_usuario'],

                    'nombre' => $usuario['nombre'],

                    'correo' => $usuario['correo'],

                    'rol' => $usuario['rol']

                ];


                // ==================================
                // 12. RENOVAR TOKEN CSRF
                // ==================================

                unset($_SESSION['token_csrf']);

                obtenerTokenCSRF();


                // ==================================
                // 13. REDIRIGIR SEGUN EL ROL
                // ==================================

                $destino = $rutasPorRol[$usuario['rol']];

                redirigir($destino);

            }

        } catch (Throwable $e) {

            // Guardar el detalle del error en el servidor.
            error_log(
                'Error durante el inicio de sesion: ' .
                $e->getMessage()
            );

            // Mostrar un mensaje general.
            $errores[] = 'No fue posible completar el inicio de sesion. Intenta nuevamente.';

        }

    }

}


// ==========================================
// 14. CARGAR CABECERA
// ==========================================

require_once __DIR__ . '/includes/header.php';

?>

<!-- ====================================== -->
<!-- FORMULARIO DE INICIO DE SESION -->
<!-- ====================================== -->

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-12 col-md-7 col-lg-5">

            <div class="card border-0 shadow-sm rounded-4">

                <div class="card-body p-4 p-md-5">

                    <!-- ENCABEZADO -->

                    <div class="text-center mb-4">

                        <div style="font-size: 50px;">
                            🔐
                        </div>

                        <h2
                            class="fw-bold"
                            style="color: #145c46;"
                        >

                            Iniciar sesión

                        </h2>

                        <p class="text-muted">

                            Ingresa tus credenciales para acceder al sistema.

                        </p>

                    </div>


                    <!-- ================================== -->
                    <!-- ERRORES -->
                    <!-- ================================== -->

                    <?php if (!empty($errores)): ?>

                        <div class="alert alert-danger">

                            <ul class="mb-0">

                                <?php foreach ($errores as $error): ?>

                                    <li>
                                        <?= escapar($error) ?>
                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        </div>

                    <?php endif; ?>


                    <!-- ================================== -->
                    <!-- FORMULARIO -->
                    <!-- ================================== -->

                    <form method="POST" action="">

                        <?= campoTokenCSRF() ?>


                        <!-- CORREO ELECTRONICO -->

                        <div class="mb-3">

                            <label class="form-label fw-semibold">

                                Correo electronico

                            </label>

                            <input
                                type="email"
                                name="correo"
                                class="form-control form-control-lg"
                                value="<?= escapar($correo) ?>"
                                placeholder="ejemplo@correo.com"
                                maxlength="150"
                                autocomplete="username"
                                required
                            >

                        </div>


                        <!-- CONTRASENA -->

                        <div class="mb-4">

                            <label class="form-label fw-semibold">

                                Contrasena

                            </label>

                            <input
                                type="password"
                                name="contrasena"
                                class="form-control form-control-lg"
                                placeholder="Ingresa tu contrasena"
                                autocomplete="current-password"
                                required
                            >

                        </div>

                        <div class="text-end mb-4">

                            <a
                                href="<?= escapar(urlSistema('recuperar.php')) ?>"
                                class="text-decoration-none"
                                style="color: #145c46;"
                            >
                                ¿Olvidaste tu contraseña?
                            </a>

                        </div>
                        
                        <!-- BOTON -->

                        <div class="d-grid">

                            <button
                                type="submit"
                                class="btn btn-success btn-lg"
                            >

                                Ingresar al sistema

                            </button>

                        </div>

                    </form>


                    <!-- REGRESAR AL INICIO -->

                    <div class="text-center mt-4">

                        <a
                            href="<?= escapar(urlSistema()) ?>"
                            class="text-decoration-none"
                            style="color: #145c46;"
                        >

                            Volver al inicio

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>


<?php

// Cargar pie de pagina.
require_once __DIR__ . '/includes/footer.php';

?>
