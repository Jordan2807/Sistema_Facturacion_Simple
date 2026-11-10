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

$tituloPagina = 'Solicitudes de recuperacion';

$rutaArchivo = __DIR__ .
    '/../storage/solicitudes_recuperacion.json';

$solicitudes = [];

$usuarios = [];

$errorCarga = '';


// ==========================================
// 2. PROCESAR APROBACION O RECHAZO
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarTokenCSRF();

    $accion = $_POST['accion'] ?? '';

    $idSolicitud = filter_var(
        $_POST['id_solicitud'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        !in_array($accion, ['aprobar', 'rechazar'], true) ||
        $idSolicitud === false ||
        $idSolicitud === null ||
        $idSolicitud <= 0
    ) {

        establecerMensaje(
            'error',
            'Los datos de la solicitud no son validos.'
        );

        redirigir('admin/solicitudes.php');

    }

    $archivo = fopen($rutaArchivo, 'c+');

    if ($archivo === false) {

        establecerMensaje(
            'error',
            'No fue posible acceder al almacenamiento de solicitudes.'
        );

        redirigir('admin/solicitudes.php');

    }

    $bloqueoAdquirido = false;

    try {

        // Bloquear el archivo durante la modificacion.

        if (!flock($archivo, LOCK_EX)) {

            throw new RuntimeException(
                'No fue posible bloquear el archivo.'
            );

        }

        $bloqueoAdquirido = true;

        rewind($archivo);

        $contenido = stream_get_contents($archivo);

        if ($contenido === false) {

            throw new RuntimeException(
                'No fue posible leer las solicitudes.'
            );

        }

        $solicitudesActuales = trim($contenido) === ''
            ? []
            : json_decode(
                $contenido,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (
            !is_array($solicitudesActuales) ||
            !array_is_list($solicitudesActuales)
        ) {

            throw new RuntimeException(
                'El archivo de solicitudes tiene un formato incorrecto.'
            );

        }

        // Buscar la solicitud.

        $indiceSolicitud = null;

        foreach ($solicitudesActuales as $indice => $solicitud) {

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

        // Comprobar que siga pendiente.

        if (
            ($solicitudesActuales[$indiceSolicitud]['estado'] ?? '') !==
            'pendiente'
        ) {

            throw new RuntimeException(
                'Esta solicitud ya fue revisada anteriormente.'
            );

        }

        // Actualizar el estado.

        $nuevoEstado = $accion === 'aprobar'
            ? 'aprobada'
            : 'rechazada';

        $solicitudesActuales[$indiceSolicitud]['estado'] = $nuevoEstado;

        $solicitudesActuales[$indiceSolicitud]['id_administrador'] =
            $idAdministrador;

        $solicitudesActuales[$indiceSolicitud]['fecha_revision'] =
            date('Y-m-d H:i:s');

        // Convertir a JSON.

        $nuevoContenido = json_encode(
            $solicitudesActuales,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        // Reemplazar contenido del archivo.

        rewind($archivo);

        if (!ftruncate($archivo, 0)) {

            throw new RuntimeException(
                'No fue posible actualizar el archivo.'
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
                    'No fue posible guardar los cambios.'
                );

            }

            $escritos += $resultado;

        }

        if (!fflush($archivo)) {

            throw new RuntimeException(
                'No fue posible completar la escritura.'
            );

        }

    } catch (Throwable $e) {

        error_log(
            'Error al gestionar solicitud de recuperacion: ' .
            $e->getMessage()
        );

        establecerMensaje(
            'error',
            'No fue posible procesar la solicitud.'
        );

    } finally {

        if ($bloqueoAdquirido) {

            flock($archivo, LOCK_UN);

        }

        fclose($archivo);

    }

    // Mostrar resultado segun la accion.

    if (!isset($_SESSION['mensaje_flash'])) {

        establecerMensaje(
            'success',
            $accion === 'aprobar'
                ? 'La solicitud fue aprobada correctamente.'
                : 'La solicitud fue rechazada correctamente.'
        );

    }

    redirigir('admin/solicitudes.php');

}


// ==========================================
// 3. LEER SOLICITUDES
// ==========================================

try {

    if (!is_file($rutaArchivo)) {

        throw new RuntimeException(
            'El almacenamiento de solicitudes no existe.'
        );

    }

    $archivo = fopen($rutaArchivo, 'r');

    if ($archivo === false) {

        throw new RuntimeException(
            'No fue posible abrir las solicitudes.'
        );

    }

    try {

        if (!flock($archivo, LOCK_SH)) {

            throw new RuntimeException(
                'No fue posible consultar las solicitudes.'
            );

        }

        $contenido = stream_get_contents($archivo);

        if ($contenido === false) {

            throw new RuntimeException(
                'No fue posible leer los datos.'
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
                'El formato del archivo JSON es incorrecto.'
            );

        }

    } finally {

        flock($archivo, LOCK_UN);

        fclose($archivo);

    }

    // Ordenar solicitudes de la mas reciente a la mas antigua.

    usort(
        $solicitudes,
        static function ($a, $b): int {

            $fechaA = is_array($a)
                ? ($a['fecha_solicitud'] ?? '')
                : '';

            $fechaB = is_array($b)
                ? ($b['fecha_solicitud'] ?? '')
                : '';

            return strcmp((string) $fechaB, (string) $fechaA);

        }
    );


    // ==========================================
    // 4. CONSULTAR INFORMACION DE LOS USUARIOS
    // ==========================================

    $idsUsuarios = [];

    foreach ($solicitudes as $solicitud) {

        if (!is_array($solicitud)) {
            continue;
        }

        $idUsuario = (int) ($solicitud['id_usuario'] ?? 0);

        $idRevisor = (int) ($solicitud['id_administrador'] ?? 0);

        if ($idUsuario > 0) {
            $idsUsuarios[] = $idUsuario;
        }

        if ($idRevisor > 0) {
            $idsUsuarios[] = $idRevisor;
        }

    }

    $idsUsuarios = array_values(array_unique($idsUsuarios));

    if (!empty($idsUsuarios)) {

        $marcadores = [];

        $parametros = [];

        foreach ($idsUsuarios as $indice => $id) {

            $marcador = ':id' . $indice;

            $marcadores[] = $marcador;

            $parametros[$marcador] = $id;

        }

        $sql = "
            SELECT id_usuario, nombre, correo, rol, estado
            FROM usuarios
            WHERE id_usuario IN (" .
            implode(',', $marcadores) .
            ")
        ";

        $consultaUsuarios = $pdo->prepare($sql);

        $consultaUsuarios->execute($parametros);

        foreach ($consultaUsuarios->fetchAll() as $usuario) {

            $usuarios[(int) $usuario['id_usuario']] = $usuario;

        }

    }

} catch (Throwable $e) {

    error_log(
        'Error al consultar solicitudes de recuperacion: ' .
        $e->getMessage()
    );

    $errorCarga = 'No fue posible cargar las solicitudes de recuperacion.';

}


// ==========================================
// 5. CALCULAR CONTADORES
// ==========================================

$totalPendientes = 0;

$totalAprobadas = 0;

$totalRechazadas = 0;

foreach ($solicitudes as $solicitud) {

    if (!is_array($solicitud)) {
        continue;
    }

    switch ($solicitud['estado'] ?? '') {

        case 'pendiente':
            $totalPendientes++;
            break;

        case 'aprobada':
            $totalAprobadas++;
            break;

        case 'rechazada':
            $totalRechazadas++;
            break;

    }

}


// ==========================================
// 6. OBTENER MENSAJE TEMPORAL
// ==========================================

$mensajeFlash = obtenerMensaje();


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

            <div class="mb-4">

                <h2 class="fw-bold" style="color: #145c46;">

                    Solicitudes de recuperacion

                </h2>

                <p class="text-muted">

                    Revisa las solicitudes de usuarios que necesitan
                    recuperar el acceso a su cuenta.

                </p>

            </div>


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
            <!-- CONTADORES -->
            <!-- ================================== -->

            <div class="row g-3 mb-4">

                <div class="col-12 col-md-4">

                    <div class="card border-0 shadow-sm rounded-3">

                        <div class="card-body">

                            <span class="text-muted">
                                Pendientes
                            </span>

                            <h3 class="fw-bold text-warning mb-0">

                                <?= $totalPendientes ?>

                            </h3>

                        </div>

                    </div>

                </div>


                <div class="col-12 col-md-4">

                    <div class="card border-0 shadow-sm rounded-3">

                        <div class="card-body">

                            <span class="text-muted">
                                Aprobadas
                            </span>

                            <h3 class="fw-bold text-success mb-0">

                                <?= $totalAprobadas ?>

                            </h3>

                        </div>

                    </div>

                </div>


                <div class="col-12 col-md-4">

                    <div class="card border-0 shadow-sm rounded-3">

                        <div class="card-body">

                            <span class="text-muted">
                                Rechazadas
                            </span>

                            <h3 class="fw-bold text-danger mb-0">

                                <?= $totalRechazadas ?>

                            </h3>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ================================== -->
            <!-- LISTADO DE SOLICITUDES -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white py-3">

                    <h5 class="mb-0 fw-bold">

                        Historial de solicitudes

                    </h5>

                </div>


                <div class="card-body">

                    <?php if (empty($solicitudes)): ?>

                        <div class="text-center py-5">

                            <div style="font-size: 45px;">
                                📭
                            </div>

                            <h5 class="mt-3">

                                No hay solicitudes registradas.

                            </h5>

                            <p class="text-muted mb-0">

                                Cuando un usuario solicite recuperar
                                su contraseña, aparecera aqui.

                            </p>

                        </div>

                    <?php else: ?>


                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead class="table-light">

                                    <tr>

                                        <th>Solicitud</th>

                                        <th>Usuario</th>

                                        <th>Fecha</th>

                                        <th>Estado</th>

                                        <th>Revision</th>

                                        <th class="text-end">Acciones</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($solicitudes as $solicitud): ?>

                                        <?php

                                        if (!is_array($solicitud)) {
                                            continue;
                                        }

                                        $id = (int) ($solicitud['id_solicitud'] ?? 0);

                                        $idUsuario = (int) ($solicitud['id_usuario'] ?? 0);

                                        $idRevisor = (int) ($solicitud['id_administrador'] ?? 0);

                                        $estado = $solicitud['estado'] ?? 'pendiente';

                                        $usuario = $usuarios[$idUsuario] ?? null;

                                        $revisor = $usuarios[$idRevisor] ?? null;

                                        $nombreUsuario = $usuario['nombre'] ?? 'Usuario no disponible';

                                        $correoUsuario = $usuario['correo'] ?? 'Sin informacion';

                                        $rolUsuario = $usuario['rol'] ?? '';

                                        $fecha = $solicitud['fecha_solicitud'] ?? '';

                                        $fechaRevision = $solicitud['fecha_revision'] ?? '';

                                        $estados = [

                                            'pendiente' => 'warning',

                                            'aprobada' => 'success',

                                            'rechazada' => 'danger',

                                            'completada' => 'primary',

                                            'expirada' => 'secondary'

                                        ];

                                        $colorEstado = $estados[$estado] ?? 'secondary';

                                        ?>

                                        <tr>

                                            <!-- IDENTIFICADOR -->

                                            <td>

                                                <strong>
                                                    #<?= $id ?>
                                                </strong>

                                            </td>


                                            <!-- USUARIO -->

                                            <td>

                                                <strong>

                                                    <?= escapar($nombreUsuario) ?>

                                                </strong>

                                                <br>

                                                <small class="text-muted">

                                                    <?= escapar($correoUsuario) ?>

                                                </small>

                                                <?php if ($rolUsuario !== ''): ?>

                                                    <br>

                                                    <small class="text-muted">

                                                        <?= escapar(ucfirst($rolUsuario)) ?>

                                                    </small>

                                                <?php endif; ?>

                                                <?php if ($usuario !== null && (int) $usuario['estado'] !== 1): ?>

                                                    <br>

                                                    <span class="badge text-bg-danger">

                                                        Cuenta inactiva

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <!-- FECHA -->

                                            <td>

                                                <?= escapar($fecha) ?>

                                            </td>


                                            <!-- ESTADO -->

                                            <td>

                                                <span class="badge text-bg-<?= $colorEstado ?>">

                                                    <?= escapar(ucfirst($estado)) ?>

                                                </span>

                                            </td>


                                            <!-- REVISION -->

                                            <td>

                                                <?php if ($idRevisor > 0): ?>

                                                    <small>

                                                        <?= escapar($revisor['nombre'] ?? 'Administrador') ?>

                                                    </small>

                                                    <br>

                                                    <small class="text-muted">

                                                        <?= escapar($fechaRevision) ?>

                                                    </small>

                                                <?php else: ?>

                                                    <span class="text-muted">

                                                        Sin revisar

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <!-- ACCIONES -->

                                            <td class="text-end">

                                                <?php if ($estado === 'pendiente'): ?>

                                                    <div class="d-flex justify-content-end flex-wrap gap-2">

                                                        <!-- APROBAR -->

                                                        <form
                                                            method="POST"
                                                            action=""
                                                        >

                                                            <?= campoTokenCSRF() ?>

                                                            <input
                                                                type="hidden"
                                                                name="id_solicitud"
                                                                value="<?= $id ?>"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="accion"
                                                                value="aprobar"
                                                            >

                                                            <button
                                                                type="submit"
                                                                class="btn btn-success btn-sm"
                                                                onclick="return confirm('¿Deseas aprobar esta solicitud?');"
                                                            >

                                                                Aprobar

                                                            </button>

                                                        </form>


                                                        <!-- RECHAZAR -->

                                                        <form
                                                            method="POST"
                                                            action=""
                                                        >

                                                            <?= campoTokenCSRF() ?>

                                                            <input
                                                                type="hidden"
                                                                name="id_solicitud"
                                                                value="<?= $id ?>"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="accion"
                                                                value="rechazar"
                                                            >

                                                            <button
                                                                type="submit"
                                                                class="btn btn-outline-danger btn-sm"
                                                                onclick="return confirm('¿Deseas rechazar esta solicitud?');"
                                                            >

                                                                Rechazar

                                                            </button>

                                                        </form>

                                                    </div>

                                                <?php else: ?>

                                                    <span class="text-muted small">

                                                        Sin acciones disponibles

                                                    </span>

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


<?php

// ==========================================
// 8. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
