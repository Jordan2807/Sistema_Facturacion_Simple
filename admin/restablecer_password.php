<?php

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';

require_once __DIR__ . '/../includes/functions.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/csrf.php';

// Conexion PDO disponible desde bootstrap.php.
/** @var PDO $pdo */

// Verificar permisos.
requerirRol('administrador');

$usuarioActual = obtenerUsuarioActual();

$idAdministrador = (int) $usuarioActual['id_usuario'];

$tituloPagina = 'Restablecer contrasenas';

$rutaArchivo = __DIR__ .
    '/../storage/solicitudes_recuperacion.json';

$solicitudesAprobadas = [];

$usuarios = [];

$errorCarga = '';


// ==========================================
// 2. PROCESAR RESTABLECIMIENTO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $idSolicitud = filter_var(
        $_POST['id_solicitud'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $idSolicitud === false ||
        $idSolicitud === null ||
        $idSolicitud <= 0
    ) {

        establecerMensaje(
            'error',
            'El identificador de la solicitud no es valido.'
        );

        redirigir('admin/restablecer_password.php');

    }


    $archivo = fopen($rutaArchivo, 'c+');

    if ($archivo === false) {

        establecerMensaje(
            'error',
            'No fue posible acceder al almacenamiento de solicitudes.'
        );

        redirigir('admin/restablecer_password.php');

    }

    $bloqueoAdquirido = false;

    $restablecimientoExitoso = false;

    try {

        // ==================================
        // 2.1 BLOQUEAR ARCHIVO JSON
        // ==================================

        if (!flock($archivo, LOCK_EX)) {

            throw new RuntimeException(
                'No fue posible bloquear el almacenamiento.'
            );

        }

        $bloqueoAdquirido = true;


        // Leer solicitudes.

        rewind($archivo);

        $contenido = stream_get_contents($archivo);

        if ($contenido === false) {

            throw new RuntimeException(
                'No fue posible leer las solicitudes.'
            );

        }


        $solicitudes = trim($contenido) === ''
            ? []
            : json_decode(
                $contenido,
                true,
                512,
                JSON_THROW_ON_ERROR
            );


        if (
            !is_array($solicitudes) ||
            !array_is_list($solicitudes)
        ) {

            throw new RuntimeException(
                'El archivo de solicitudes tiene un formato incorrecto.'
            );

        }


        // ==================================
        // 2.2 BUSCAR SOLICITUD APROBADA
        // ==================================

        $indiceSolicitud = null;

        foreach ($solicitudes as $indice => $solicitud) {

            if (
                is_array($solicitud) &&
                (int) ($solicitud['id_solicitud'] ?? 0) === $idSolicitud
            ) {

                $indiceSolicitud = $indice;

                break;

            }

        }


        if ($indiceSolicitud === null) {

            throw new RuntimeException(
                'La solicitud seleccionada no existe.'
            );

        }


        // Comprobar que este aprobada.

        if (
            ($solicitudes[$indiceSolicitud]['estado'] ?? '') !==
            'aprobada'
        ) {

            throw new RuntimeException(
                'La solicitud no esta aprobada o ya fue procesada.'
            );

        }


        $idUsuario = (int) (
            $solicitudes[$indiceSolicitud]['id_usuario'] ?? 0
        );


        if ($idUsuario <= 0) {

            throw new RuntimeException(
                'La solicitud no contiene un usuario valido.'
            );

        }


        // ==================================
        // 2.3 CONSULTAR USUARIO EN MYSQL
        // ==================================

        $consultaUsuario = $pdo->prepare(
            "SELECT
                id_usuario,
                nombre,
                correo,
                estado
             FROM usuarios
             WHERE id_usuario = :id_usuario
             LIMIT 1"
        );

        $consultaUsuario->execute([

            'id_usuario' => $idUsuario

        ]);

        $usuario = $consultaUsuario->fetch();


        if (!$usuario) {

            throw new RuntimeException(
                'El usuario asociado a la solicitud no existe.'
            );

        }


        if ((int) $usuario['estado'] !== 1) {

            throw new RuntimeException(
                'La cuenta del usuario esta desactivada. Debe activarse antes de restablecer su contraseña.'
            );

        }


        // ==================================
        // 2.4 GENERAR CONTRASENA TEMPORAL
        // ==================================

        /**
         * Generamos una contrasena aleatoria de 32 caracteres
         * hexadecimales utilizando random_bytes().
         *
         * Esta contrasena no se almacena en texto plano
         * en MySQL ni en el archivo JSON.
         */

        $contrasenaTemporal = bin2hex(
            random_bytes(16)
        );


        // ==================================
        // 2.5 PROTEGER LA CONTRASENA
        // ==================================

        $contrasenaHash = password_hash(
            $contrasenaTemporal,
            PASSWORD_DEFAULT
        );


        if ($contrasenaHash === false) {

            throw new RuntimeException(
                'No fue posible proteger la nueva contraseña.'
            );

        }


        // ==================================
        // 2.6 ACTUALIZAR CONTRASENA EN MYSQL
        // ==================================

        $consultaActualizar = $pdo->prepare(
            "UPDATE usuarios
             SET contrasena_hash = :contrasena_hash
             WHERE id_usuario = :id_usuario
             AND estado = 1"
        );

        $consultaActualizar->execute([

            'contrasena_hash' => $contrasenaHash,

            'id_usuario' => $idUsuario

        ]);


        if ($consultaActualizar->rowCount() !== 1) {

            throw new RuntimeException(
                'No fue posible actualizar la contraseña del usuario.'
            );

        }


        // ==================================
        // 2.7 MARCAR SOLICITUD COMO COMPLETADA
        // ==================================

        $solicitudes[$indiceSolicitud]['estado'] = 'completada';

        $solicitudes[$indiceSolicitud]['id_administrador'] =
            $idAdministrador;

        $solicitudes[$indiceSolicitud]['fecha_restauracion'] =
            date('Y-m-d H:i:s');


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
                'No fue posible actualizar el almacenamiento.'
            );

        }


        $longitud = strlen($nuevoContenido);

        $escritos = 0;


        while ($escritos < $longitud) {

            $resultado = fwrite(
                $archivo,
                substr($nuevoContenido, $escritos)
            );

            if ($resultado === false || $resultado === 0) {

                throw new RuntimeException(
                    'No fue posible guardar los cambios de la solicitud.'
                );

            }

            $escritos += $resultado;

        }


        if (!fflush($archivo)) {

            throw new RuntimeException(
                'No fue posible completar el almacenamiento.'
            );

        }


        // ==================================
        // 2.8 GUARDAR RESULTADO TEMPORAL
        // ==================================

        /**
         * La contrasena se conserva unicamente en la sesion
         * del administrador para mostrarla una sola vez.
         */

        $_SESSION['resultado_restauracion'] = [

            'nombre' => $usuario['nombre'],

            'correo' => $usuario['correo'],

            'contrasena' => $contrasenaTemporal,

            'id_solicitud' => $idSolicitud

        ];

        $restablecimientoExitoso = true;


    } catch (Throwable $e) {

        error_log(
            'Error al restablecer contraseña: ' .
            $e->getMessage()
        );

        establecerMensaje(
            'error',
            'No fue posible completar el restablecimiento. ' .
            'Verifica el estado de la solicitud e intenta nuevamente.'
        );


    } finally {

        // Liberar bloqueo del archivo.

        if ($bloqueoAdquirido) {

            flock($archivo, LOCK_UN);

        }

        fclose($archivo);

    }


    // ==================================
    // 2.9 REDIRIGIR DESPUES DEL PROCESO
    // ==================================

    if ($restablecimientoExitoso) {

        redirigir('admin/restablecer_password.php');

    }

    redirigir('admin/restablecer_password.php');

}


// ==========================================
// 3. RECUPERAR CONTRASENA GENERADA UNA SOLA VEZ
// ==========================================

$resultadoRestauracion =
    $_SESSION['resultado_restauracion'] ?? null;

// Eliminar inmediatamente el dato de la sesion.
// Asi no volvera a aparecer al actualizar la pagina.

unset($_SESSION['resultado_restauracion']);


// ==========================================
// 4. RECUPERAR MENSAJES TEMPORALES
// ==========================================

$mensajeFlash = obtenerMensaje();


// ==========================================
// 5. CARGAR SOLICITUDES APROBADAS
// ==========================================

try {

    if (!is_file($rutaArchivo)) {

        throw new RuntimeException(
            'No existe el archivo de solicitudes.'
        );

    }


    $archivo = fopen($rutaArchivo, 'r');

    if ($archivo === false) {

        throw new RuntimeException(
            'No fue posible abrir el almacenamiento.'
        );

    }


    try {

        if (!flock($archivo, LOCK_SH)) {

            throw new RuntimeException(
                'No fue posible bloquear el almacenamiento.'
            );

        }

        $contenido = stream_get_contents($archivo);

        if ($contenido === false) {

            throw new RuntimeException(
                'No fue posible leer las solicitudes.'
            );

        }


        $solicitudes = trim($contenido) === ''
            ? []
            : json_decode(
                $contenido,
                true,
                512,
                JSON_THROW_ON_ERROR
            );


        if (
            !is_array($solicitudes) ||
            !array_is_list($solicitudes)
        ) {

            throw new RuntimeException(
                'El archivo JSON tiene un formato incorrecto.'
            );

        }


    } finally {

        flock($archivo, LOCK_UN);

        fclose($archivo);

    }


    // ==================================
    // 6. FILTRAR SOLICITUDES APROBADAS
    // ==================================

    $idsUsuarios = [];

    foreach ($solicitudes as $solicitud) {

        if (
            !is_array($solicitud) ||
            ($solicitud['estado'] ?? '') !== 'aprobada'
        ) {

            continue;

        }

        $idUsuario = (int) ($solicitud['id_usuario'] ?? 0);

        if ($idUsuario > 0) {

            $idsUsuarios[] = $idUsuario;

        }

        $solicitudesAprobadas[] = $solicitud;

    }


    // Ordenar por fecha de solicitud.
    usort(
        $solicitudesAprobadas,
        static function ($a, $b): int {

            return strcmp(
                (string) ($b['fecha_solicitud'] ?? ''),
                (string) ($a['fecha_solicitud'] ?? '')
            );

        }
    );


    // ==================================
    // 7. CONSULTAR INFORMACION DE USUARIOS
    // ==================================

    $idsUsuarios = array_values(
        array_unique($idsUsuarios)
    );


    if (!empty($idsUsuarios)) {

        $marcadores = [];

        $parametros = [];


        foreach ($idsUsuarios as $indice => $id) {

            $marcador = ':usuario' . $indice;

            $marcadores[] = $marcador;

            $parametros[$marcador] = $id;

        }


        $sql = "
            SELECT
                id_usuario,
                nombre,
                correo,
                rol,
                estado
            FROM usuarios
            WHERE id_usuario IN (
                " . implode(',', $marcadores) . "
            )
        ";


        $consultaUsuarios = $pdo->prepare($sql);

        $consultaUsuarios->execute($parametros);


        foreach ($consultaUsuarios->fetchAll() as $usuario) {

            $usuarios[(int) $usuario['id_usuario']] = $usuario;

        }

    }


} catch (Throwable $e) {

    error_log(
        'Error al cargar restablecimientos: ' .
        $e->getMessage()
    );

    $errorCarga = 'No fue posible cargar las solicitudes aprobadas.';

}


// ==========================================
// 8. CARGAR CABECERA
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


        <!-- CONTENIDO PRINCIPAL -->

        <main class="col-12 col-lg-9 col-xl-10">

            <div class="mb-4">

                <h2 class="fw-bold" style="color: #145c46;">

                    Restablecer contraseñas

                </h2>

                <p class="text-muted">

                    Gestiona las solicitudes aprobadas y establece
                    contraseñas temporales para los usuarios.

                </p>

            </div>


            <!-- ================================== -->
            <!-- RESULTADO DEL RESTABLECIMIENTO -->
            <!-- ================================== -->

            <?php if ($resultadoRestauracion !== null): ?>

                <div class="alert alert-success border-0 shadow-sm">

                    <h5 class="fw-bold">

                        ¡Contraseña restablecida correctamente!

                    </h5>

                    <p>

                        Usuario:
                        <strong>
                            <?= escapar($resultadoRestauracion['nombre']) ?>
                        </strong>

                        <br>

                        Correo:
                        <strong>
                            <?= escapar($resultadoRestauracion['correo']) ?>
                        </strong>

                    </p>

                    <hr>

                    <p class="fw-semibold mb-2">

                        Contraseña temporal generada:

                    </p>

                    <div class="input-group">

                        <input
                            type="text"
                            class="form-control"
                            value="<?= escapar($resultadoRestauracion['contrasena']) ?>"
                            readonly
                            id="contrasenaTemporal"
                        >

                        <button
                            type="button"
                            class="btn btn-outline-success"
                            onclick="copiarContrasena()"
                        >

                            Copiar

                        </button>

                    </div>

                    <small class="d-block mt-3">

                        Entrega esta contraseña directamente al usuario
                        por un medio seguro.

                        No volvera a mostrarse despues de salir de esta pagina
                        o actualizarla.

                    </small>

                    <div class="alert alert-warning mt-3 mb-0">

                        <strong>Importante:</strong>

                        El usuario debe ingresar con esta contraseña
                        y cambiarla posteriormente desde la seccion
                        Mi cuenta.

                    </div>

                </div>

            <?php endif; ?>


            <!-- ================================== -->
            <!-- MENSAJES -->
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


            <?php if ($errorCarga !== ''): ?>

                <div class="alert alert-danger">

                    <?= escapar($errorCarga) ?>

                </div>

            <?php endif; ?>


            <!-- ================================== -->
            <!-- TABLA DE SOLICITUDES -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold">

                        Solicitudes aprobadas

                    </h5>

                </div>


                <div class="card-body">

                    <?php if (empty($solicitudesAprobadas)): ?>

                        <div class="text-center py-5">

                            <div style="font-size: 45px;">
                                🔐
                            </div>

                            <h5 class="mt-3">

                                No existen solicitudes pendientes de restablecimiento.

                            </h5>

                            <p class="text-muted mb-0">

                                Cuando apruebes una solicitud de recuperación,
                                aparecerá disponible en esta sección.

                            </p>

                        </div>

                    <?php else: ?>


                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead class="table-light">

                                    <tr>

                                        <th>Solicitud</th>

                                        <th>Usuario</th>

                                        <th>Fecha de solicitud</th>

                                        <th>Rol</th>

                                        <th>Estado</th>

                                        <th class="text-end">Acción</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($solicitudesAprobadas as $solicitud): ?>

                                        <?php

                                        $idSolicitud = (int) $solicitud['id_solicitud'];

                                        $idUsuario = (int) $solicitud['id_usuario'];

                                        $usuario = $usuarios[$idUsuario] ?? null;

                                        $nombreUsuario = $usuario['nombre'] ?? 'Usuario no disponible';

                                        $correoUsuario = $usuario['correo'] ?? 'Sin informacion';

                                        $rolUsuario = $usuario['rol'] ?? '';

                                        $usuarioActivo = (
                                            $usuario !== null &&
                                            (int) $usuario['estado'] === 1
                                        );

                                        ?>

                                        <tr>

                                            <td>

                                                <strong>

                                                    #<?= $idSolicitud ?>

                                                </strong>

                                            </td>


                                            <td>

                                                <strong>

                                                    <?= escapar($nombreUsuario) ?>

                                                </strong>

                                                <br>

                                                <small class="text-muted">

                                                    <?= escapar($correoUsuario) ?>

                                                </small>

                                            </td>


                                            <td>

                                                <?= escapar(
                                                    $solicitud['fecha_solicitud'] ?? ''
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= escapar(
                                                    ucfirst($rolUsuario)
                                                ) ?>

                                            </td>


                                            <td>

                                                <span class="badge text-bg-success">

                                                    Aprobada

                                                </span>

                                                <?php if (!$usuarioActivo): ?>

                                                    <br>

                                                    <span class="badge text-bg-danger mt-1">

                                                        Cuenta inactiva

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <td class="text-end">

                                                <?php if ($usuarioActivo): ?>

                                                    <form
                                                        method="POST"
                                                        action=""
                                                        onsubmit="return confirm('¿Deseas generar una nueva contraseña temporal para este usuario?');"
                                                    >

                                                        <?= campoTokenCSRF() ?>


                                                        <input
                                                            type="hidden"
                                                            name="id_solicitud"
                                                            value="<?= $idSolicitud ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-success btn-sm"
                                                        >

                                                            Restablecer contraseña

                                                        </button>

                                                    </form>

                                                <?php else: ?>

                                                    <button
                                                        class="btn btn-secondary btn-sm"
                                                        disabled
                                                    >

                                                        Usuario inactivo

                                                    </button>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </main>

    </div>

</div>


<!-- ====================================== -->
<!-- FUNCION PARA COPIAR CONTRASENA -->
<!-- ====================================== -->

<script>

    function copiarContrasena() {

        const campo = document.getElementById(
            'contrasenaTemporal'
        );

        campo.select();

        campo.setSelectionRange(0, 99999);

        if (navigator.clipboard && window.isSecureContext) {

            navigator.clipboard.writeText(campo.value)
                .then(() => {

                    alert('Contraseña copiada correctamente.');

                })
                .catch(() => {

                    alert('No fue posible copiar la contraseña.');

                });

        } else {

            const copiado = document.execCommand('copy');

            alert(
                copiado
                    ? 'Contraseña copiada correctamente.'
                    : 'No fue posible copiar la contraseña.'
            );

        }

    }

</script>


<?php

// ==========================================
// 9. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
