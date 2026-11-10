<?php

/**
 * SOLICITUD DE RECUPERACION DE CONTRASENA
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * El usuario solicita ayuda para recuperar su cuenta.
 * La solicitud queda pendiente de autorizacion administrativa.
 *
 * No se envian correos ni se modifican contrasenas.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validators.php';
require_once __DIR__ . '/includes/csrf.php';


// ==========================================
// 2. VARIABLES INICIALES
// ==========================================

$tituloPagina = 'Recuperar contraseña';

$errores = [];

$mensajeExito = false;

$correo = '';


// ==========================================
// 3. PROCESAR SOLICITUD
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar proteccion CSRF.
    verificarTokenCSRF();

    // Obtener correo enviado.
    $correoEntrada = $_POST['correo'] ?? '';

    $correo = is_string($correoEntrada)
        ? strtolower(trim($correoEntrada))
        : '';


    // ==========================================
    // 4. VALIDAR CORREO
    // ==========================================

    if (!validarRequerido($correo)) {

        $errores[] = 'Debes ingresar tu correo electronico.';

    } elseif (
        !validarCorreo($correo) ||
        strlen($correo) > 150
    ) {

        $errores[] = 'El correo electronico no tiene un formato valido.';

    }


    // ==========================================
    // 5. BUSCAR USUARIO EN MYSQL
    // ==========================================

    if (empty($errores)) {

        try {

            $consultaUsuario = $pdo->prepare(
                "SELECT id_usuario
                 FROM usuarios
                 WHERE correo = :correo
                 AND estado = 1
                 LIMIT 1"
            );

            $consultaUsuario->execute([
                'correo' => $correo
            ]);

            $idUsuario = $consultaUsuario->fetchColumn();


            // ==========================================
            // 6. REGISTRAR SOLICITUD SI EXISTE EL USUARIO
            // ==========================================

            if ($idUsuario !== false) {

                $idUsuario = (int) $idUsuario;

                $directorioStorage = __DIR__ . '/storage';

                $rutaArchivo = $directorioStorage .
                    '/solicitudes_recuperacion.json';


                // Comprobar que exista el almacenamiento protegido.

                if (
                    !is_dir($directorioStorage) ||
                    !is_file($directorioStorage . '/.htaccess')
                ) {

                    throw new RuntimeException(
                        'El almacenamiento privado no esta disponible.'
                    );

                }


                // ==========================================
                // 7. ABRIR ARCHIVO JSON
                // ==========================================

                $archivo = fopen($rutaArchivo, 'c+');

                if ($archivo === false) {

                    throw new RuntimeException(
                        'No fue posible abrir el almacenamiento de solicitudes.'
                    );

                }


                $bloqueoAdquirido = false;


                try {

                    // Evitar modificaciones simultaneas.

                    if (!flock($archivo, LOCK_EX)) {

                        throw new RuntimeException(
                            'No fue posible bloquear el almacenamiento.'
                        );

                    }

                    $bloqueoAdquirido = true;


                    // Leer contenido actual.

                    rewind($archivo);

                    $contenido = stream_get_contents($archivo);

                    if ($contenido === false) {

                        throw new RuntimeException(
                            'No fue posible leer las solicitudes.'
                        );

                    }


                    // Decodificar JSON.

                    if (trim($contenido) === '') {

                        $solicitudes = [];

                    } else {

                        $solicitudes = json_decode(
                            $contenido,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );

                    }


                    // Comprobar estructura.

                    if (!is_array($solicitudes) || !array_is_list($solicitudes)) {

                        throw new RuntimeException(
                            'El archivo de solicitudes tiene un formato incorrecto.'
                        );

                    }


                    // ==========================================
                    // 8. COMPROBAR SOLICITUD PENDIENTE
                    // ==========================================

                    $solicitudPendiente = false;

                    $ultimoId = 0;

                    foreach ($solicitudes as $solicitud) {

                        if (!is_array($solicitud)) {
                            continue;
                        }

                        $idActual = (int) (
                            $solicitud['id_solicitud'] ?? 0
                        );

                        if ($idActual > $ultimoId) {

                            $ultimoId = $idActual;

                        }


                        // Evitar solicitudes pendientes duplicadas.

                        if (
                            (int) ($solicitud['id_usuario'] ?? 0) === $idUsuario &&
                            ($solicitud['estado'] ?? '') === 'pendiente'
                        ) {

                            $solicitudPendiente = true;

                        }

                    }


                    // ==========================================
                    // 9. CREAR NUEVA SOLICITUD
                    // ==========================================

                    if (!$solicitudPendiente) {

                        $nuevaSolicitud = [

                            'id_solicitud' => $ultimoId + 1,

                            'id_usuario' => $idUsuario,

                            'fecha_solicitud' => date('Y-m-d H:i:s'),

                            'estado' => 'pendiente',

                            'id_administrador' => null,

                            'fecha_revision' => null

                        ];


                        $solicitudes[] = $nuevaSolicitud;


                        // Convertir nuevamente a JSON.

                        $nuevoContenido = json_encode(
                            $solicitudes,
                            JSON_PRETTY_PRINT |
                            JSON_UNESCAPED_UNICODE |
                            JSON_THROW_ON_ERROR
                        ) . PHP_EOL;


                        // Reemplazar contenido del archivo.

                        rewind($archivo);

                        if (!ftruncate($archivo, 0)) {

                            throw new RuntimeException(
                                'No fue posible actualizar las solicitudes.'
                            );

                        }


                        // Escribir todos los datos.

                        $longitud = strlen($nuevoContenido);

                        $escritos = 0;

                        while ($escritos < $longitud) {

                            $resultado = fwrite(
                                $archivo,
                                substr($nuevoContenido, $escritos)
                            );

                            if ($resultado === false || $resultado === 0) {

                                throw new RuntimeException(
                                    'No fue posible guardar la solicitud.'
                                );

                            }

                            $escritos += $resultado;

                        }


                        if (!fflush($archivo)) {

                            throw new RuntimeException(
                                'No fue posible completar el almacenamiento.'
                            );

                        }

                    }

                } finally {

                    // Liberar bloqueo.

                    if ($bloqueoAdquirido) {

                        flock($archivo, LOCK_UN);

                    }

                    // Cerrar archivo.

                    fclose($archivo);

                }

            }


            // ==========================================
            // 10. MOSTRAR RESPUESTA GENERAL
            // ==========================================

            /**
             * No indicamos si el correo existe.
             * Esto evita revelar informacion de usuarios.
             */

            $mensajeExito = true;

            $correo = '';

        } catch (Throwable $e) {

            // Registrar error interno.

            error_log(
                'Error en solicitud de recuperacion: ' .
                $e->getMessage()
            );

            $errores[] = 'No fue posible procesar la solicitud. Intenta nuevamente.';

        }

    }

}


// ==========================================
// 11. CARGAR CABECERA
// ==========================================

require_once __DIR__ . '/includes/header.php';

?>

<!-- ====================================== -->
<!-- FORMULARIO DE RECUPERACION -->
<!-- ====================================== -->

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-12 col-md-8 col-lg-5">

            <div class="card border-0 shadow-sm rounded-4">

                <div class="card-body p-4 p-md-5">


                    <!-- ENCABEZADO -->

                    <div class="text-center mb-4">

                        <div style="font-size: 48px;">
                            🔑
                        </div>

                        <h2
                            class="fw-bold"
                            style="color: #145c46;"
                        >

                            Recuperar contraseña

                        </h2>

                        <p class="text-muted">

                            Solicita ayuda al administrador para recuperar
                            el acceso a tu cuenta.

                        </p>

                    </div>


                    <!-- ================================== -->
                    <!-- SOLICITUD PROCESADA -->
                    <!-- ================================== -->

                    <?php if ($mensajeExito): ?>

                        <div class="alert alert-success">

                            <h5 class="fw-bold">

                                Solicitud recibida

                            </h5>

                            <p class="mb-0">

                                Si el correo corresponde a una cuenta activa,
                                la solicitud será revisada por un administrador.

                                Una vez autorizada, el administrador podrá
                                restablecer tu contraseña.

                            </p>

                        </div>

                        <div class="text-center">

                            <a
                                href="<?= escapar(urlSistema('login.php')) ?>"
                                class="btn btn-success px-4"
                            >

                                Volver al inicio de sesión

                            </a>

                        </div>


                    <!-- ================================== -->
                    <!-- FORMULARIO -->
                    <!-- ================================== -->

                    <?php else: ?>


                        <!-- ERRORES -->

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


                        <form method="POST" action="">

                            <?= campoTokenCSRF() ?>


                            <!-- CORREO -->

                            <div class="mb-4">

                                <label class="form-label fw-semibold">

                                    Correo electrónico registrado

                                </label>

                                <input
                                    type="email"
                                    name="correo"
                                    class="form-control form-control-lg"
                                    placeholder="ejemplo@correo.com"
                                    maxlength="150"
                                    value="<?= escapar($correo) ?>"
                                    autocomplete="email"
                                    required
                                >

                                <small class="text-muted">

                                    Ingresa el correo asociado a tu cuenta
                                    del sistema.

                                </small>

                            </div>


                            <!-- BOTON -->

                            <div class="d-grid">

                                <button
                                    type="submit"
                                    class="btn btn-success btn-lg"
                                >

                                    Enviar solicitud

                                </button>

                            </div>

                        </form>


                        <!-- VOLVER -->

                        <div class="text-center mt-4">

                            <a
                                href="<?= escapar(urlSistema('login.php')) ?>"
                                class="text-decoration-none"
                                style="color: #145c46;"
                            >

                                Volver al inicio de sesión

                            </a>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<?php

// ==========================================
// 12. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/includes/footer.php';

?>
