<?php

/**
 * ADMINISTRACION DE USUARIOS
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite consultar y filtrar los usuarios registrados.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

// Conexion PDO.
/** @var PDO $pdo */

// Verificar acceso administrativo.
requerirRol('administrador');

// Obtener información del administrador actual.
$usuarioActual = obtenerUsuarioActual();

$tituloPagina = 'Administracion de usuarios';


// ==========================================
// 2. VARIABLES DE BUSQUEDA Y FILTROS
// ==========================================

$buscarEntrada = $_GET['buscar'] ?? '';

$rolEntrada = $_GET['rol'] ?? '';

$estadoEntrada = $_GET['estado'] ?? '';

$buscar = is_string($buscarEntrada)
    ? trim($buscarEntrada)
    : '';

$rolFiltro = is_string($rolEntrada)
    ? $rolEntrada
    : '';

$estadoFiltro = is_string($estadoEntrada)
    ? $estadoEntrada
    : '';


// Roles permitidos para filtrar.
$rolesPermitidos = [
    'administrador',
    'cajero',
    'almacen'
];

if (!in_array($rolFiltro, $rolesPermitidos, true)) {
    $rolFiltro = '';
}

// Estados permitidos.
if (!in_array($estadoFiltro, ['0', '1'], true)) {
    $estadoFiltro = '';
}

// Limitar longitud de busqueda.
$buscar = substr($buscar, 0, 150);


// ==========================================
// 3. CONSULTAR RESUMEN GENERAL
// ==========================================

try {

    $consultaResumen = $pdo->query(
        "SELECT
            COUNT(*) AS total_usuarios,

            COALESCE(
                SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END),
                0
            ) AS usuarios_activos,

            COALESCE(
                SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END),
                0
            ) AS usuarios_inactivos,

            COALESCE(
                SUM(
                    CASE
                        WHEN rol = 'administrador'
                        AND estado = 1
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS administradores_activos

        FROM usuarios"
    );

    $resumen = $consultaResumen->fetch();


    // ==========================================
    // 4. CONSTRUIR CONSULTA DE USUARIOS
    // ==========================================

    $sql = "
        SELECT
            id_usuario,
            nombre,
            correo,
            rol,
            estado,
            fecha_creacion

        FROM usuarios

        WHERE 1 = 1
    ";

    $parametros = [];


    // Buscar por nombre o correo.
    if ($buscar !== '') {

        $sql .= "
            AND (
                nombre LIKE :buscar_nombre
                OR correo LIKE :buscar_correo
            )
        ";

        $parametros['buscar_nombre'] = '%' . $buscar . '%';

        $parametros['buscar_correo'] = '%' . $buscar . '%';

    }


    // Filtrar por rol.
    if ($rolFiltro !== '') {

        $sql .= " AND rol = :rol ";

        $parametros['rol'] = $rolFiltro;

    }


    // Filtrar por estado.
    if ($estadoFiltro !== '') {

        $sql .= " AND estado = :estado ";

        $parametros['estado'] = (int) $estadoFiltro;

    }


    // Ordenar usuarios.
    $sql .= "
        ORDER BY

            CASE
                WHEN rol = 'administrador' THEN 1
                WHEN rol = 'cajero' THEN 2
                WHEN rol = 'almacen' THEN 3
                ELSE 4
            END,

            nombre ASC
    ";


    // ==========================================
    // 5. EJECUTAR CONSULTA
    // ==========================================

    $consultaUsuarios = $pdo->prepare($sql);

    $consultaUsuarios->execute($parametros);

    $usuarios = $consultaUsuarios->fetchAll();

    $totalResultados = count($usuarios);


} catch (Throwable $e) {

    error_log(
        'Error al consultar usuarios: ' . $e->getMessage()
    );

    $usuarios = [];

    $totalResultados = 0;

    $resumen = [
        'total_usuarios' => 0,
        'usuarios_activos' => 0,
        'usuarios_inactivos' => 0,
        'administradores_activos' => 0
    ];

    $errorCarga = true;

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

            <div class="d-flex flex-column flex-md-row
                        justify-content-between align-items-md-center
                        gap-3 mb-4">

                <div>

                    <h2
                        class="fw-bold mb-1"
                        style="color: #145c46;"
                    >

                        Administracion de usuarios

                    </h2>

                    <p class="text-muted mb-0">

                        Consulta y supervisa las cuentas registradas
                        en el sistema.

                    </p>

                </div>

            </div>


            <?php if (!empty($errorCarga)): ?>

                <div class="alert alert-danger">

                    No fue posible cargar la informacion de los usuarios.

                </div>

            <?php endif; ?>


            <!-- ================================== -->
            <!-- RESUMEN DE USUARIOS -->
            <!-- ================================== -->

            <div class="row g-3 mb-4">


                <!-- TOTAL -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Total de usuarios

                                    </span>

                                    <h3 class="fw-bold mb-0">

                                        <?= (int) $resumen['total_usuarios'] ?>

                                    </h3>

                                </div>

                                <div style="font-size: 30px;">
                                    👥
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- ACTIVOS -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Usuarios activos

                                    </span>

                                    <h3 class="fw-bold text-success mb-0">

                                        <?= (int) $resumen['usuarios_activos'] ?>

                                    </h3>

                                </div>

                                <div style="font-size: 30px;">
                                    ✅
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- INACTIVOS -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Usuarios inactivos

                                    </span>

                                    <h3 class="fw-bold text-danger mb-0">

                                        <?= (int) $resumen['usuarios_inactivos'] ?>

                                    </h3>

                                </div>

                                <div style="font-size: 30px;">
                                    ⛔
                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- ADMINISTRADORES -->

                <div class="col-12 col-sm-6 col-xl-3">

                    <div class="card border-0 shadow-sm rounded-3 h-100">

                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-center">

                                <div>

                                    <span class="text-muted small">

                                        Administradores activos

                                    </span>

                                    <h3 class="fw-bold mb-0" style="color: #145c46;">

                                        <?= (int) $resumen['administradores_activos'] ?>

                                    </h3>

                                </div>

                                <div style="font-size: 30px;">
                                    🛡️
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ================================== -->
            <!-- FILTROS -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3 mb-4">

                <div class="card-body p-4">

                    <h5 class="fw-bold mb-3">

                        Buscar usuarios

                    </h5>

                    <form method="GET" action="">

                        <div class="row g-3">

                            <!-- BUSCAR -->

                            <div class="col-12 col-md-5">

                                <label class="form-label">

                                    Nombre o correo

                                </label>

                                <input
                                    type="search"
                                    name="buscar"
                                    class="form-control"
                                    placeholder="Escribe para buscar..."
                                    value="<?= escapar($buscar) ?>"
                                    maxlength="150"
                                >

                            </div>


                            <!-- ROL -->

                            <div class="col-12 col-md-3">

                                <label class="form-label">

                                    Rol

                                </label>

                                <select
                                    name="rol"
                                    class="form-select"
                                >

                                    <option value="">

                                        Todos los roles

                                    </option>

                                    <option
                                        value="administrador"
                                        <?= $rolFiltro === 'administrador' ? 'selected' : '' ?>
                                    >

                                        Administrador

                                    </option>

                                    <option
                                        value="cajero"
                                        <?= $rolFiltro === 'cajero' ? 'selected' : '' ?>
                                    >

                                        Cajero

                                    </option>

                                    <option
                                        value="almacen"
                                        <?= $rolFiltro === 'almacen' ? 'selected' : '' ?>
                                    >

                                        Empleado de almacen

                                    </option>

                                </select>

                            </div>


                            <!-- ESTADO -->

                            <div class="col-12 col-md-2">

                                <label class="form-label">

                                    Estado

                                </label>

                                <select
                                    name="estado"
                                    class="form-select"
                                >

                                    <option value="">

                                        Todos

                                    </option>

                                    <option
                                        value="1"
                                        <?= $estadoFiltro === '1' ? 'selected' : '' ?>
                                    >

                                        Activos

                                    </option>

                                    <option
                                        value="0"
                                        <?= $estadoFiltro === '0' ? 'selected' : '' ?>
                                    >

                                        Inactivos

                                    </option>

                                </select>

                            </div>


                            <!-- BOTONES -->

                            <div class="col-12 col-md-2 d-flex align-items-end">

                                <div class="d-flex gap-2 w-100">

                                    <button
                                        type="submit"
                                        class="btn btn-success flex-grow-1"
                                    >

                                        Buscar

                                    </button>

                                    <a
                                        href="<?= escapar(urlSistema('admin/usuarios.php')) ?>"
                                        class="btn btn-outline-secondary"
                                        title="Limpiar filtros"
                                    >

                                        Limpiar

                                    </a>

                                </div>

                            </div>

                        </div>

                    </form>

                </div>

            </div>


            <!-- ================================== -->
            <!-- TABLA DE USUARIOS -->
            <!-- ================================== -->

            <div class="card border-0 shadow-sm rounded-3">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center">

                        <h5 class="mb-0 fw-bold">

                            Usuarios registrados

                        </h5>

                        <span class="badge text-bg-success">

                            <?= $totalResultados ?> resultados

                        </span>

                    </div>

                </div>


                <div class="card-body">

                    <?php if (empty($usuarios)): ?>

                        <div class="text-center py-5">

                            <div style="font-size: 45px;">

                                👥

                            </div>

                            <h5 class="mt-3">

                                No se encontraron usuarios.

                            </h5>

                            <p class="text-muted mb-0">

                                Intenta modificar los filtros de búsqueda.

                            </p>

                        </div>

                    <?php else: ?>


                        <div class="table-responsive">

                            <table class="table table-hover align-middle">

                                <thead class="table-light">

                                    <tr>

                                        <th>ID</th>

                                        <th>Nombre</th>

                                        <th>Correo electronico</th>

                                        <th>Rol</th>

                                        <th>Estado</th>

                                        <th>Fecha de registro</th>

                                        <th class="text-end">Acciones</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($usuarios as $usuario): ?>

                                        <?php

                                        $idUsuarioTabla = (int) $usuario['id_usuario'];

                                        $esUsuarioActual = (
                                            $idUsuarioTabla === (int) ($usuarioActual['id_usuario'] ?? 0)
                                        );

                                        $activo = (int) $usuario['estado'] === 1;

                                        ?>

                                        <tr>

                                            <!-- ID -->

                                            <td>

                                                #<?= $idUsuarioTabla ?>

                                                <?php if ($esUsuarioActual): ?>

                                                    <br>

                                                    <span class="badge text-bg-info">

                                                        Tu cuenta

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <!-- NOMBRE -->

                                            <td>

                                                <strong>

                                                    <?= escapar($usuario['nombre']) ?>

                                                </strong>

                                            </td>


                                            <!-- CORREO -->

                                            <td>

                                                <?= escapar($usuario['correo']) ?>

                                            </td>


                                            <!-- ROL -->

                                            <td>

                                                <?php

                                                $colorRol = match ($usuario['rol']) {

                                                    'administrador' => 'primary',

                                                    'cajero' => 'success',

                                                    'almacen' => 'info',

                                                    default => 'secondary'

                                                };

                                                ?>

                                                <span class="badge text-bg-<?= $colorRol ?>">

                                                    <?= escapar(obtenerNombreRol($usuario['rol'])) ?>

                                                </span>

                                            </td>


                                            <!-- ESTADO -->

                                            <td>

                                                <?php if ($activo): ?>

                                                    <span class="badge text-bg-success">

                                                        Activo

                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge text-bg-danger">

                                                        Inactivo

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <!-- FECHA -->

                                            <td>

                                                <?= escapar(
                                                    formatearFechaHora(
                                                        $usuario['fecha_creacion']
                                                    )
                                                ) ?>

                                            </td>

                                            <td class="text-end">

                                                <a
                                                    href="<?= escapar(
                                                        urlSistema('admin/editar_usuario.php?id=' . $idUsuarioTabla)
                                                    ) ?>"
                                                    class="btn btn-outline-success btn-sm"
                                                    title="Editar usuario"
                                                >

                                                    <i class="bi bi-pencil-square me-1"></i>

                                                    Editar

                                                </a>



                                                <!-- FORMULARIO PARA ACTIVAR O DESACTIVAR -->

                                                <?php if (!$esUsuarioActual): ?>

                                                    <form
                                                        action="<?= escapar(urlSistema('admin/cambiar_estado_usuario.php')) ?>"
                                                        method="POST"
                                                        class="d-inline form-cambiar-estado-usuario"
                                                    >

                                                        <?= campoTokenCSRF() ?>

                                                        <input
                                                            type="hidden"
                                                            name="id_usuario"
                                                            value="<?= $idUsuarioTabla ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="accion"
                                                            value="<?= $activo ? 'desactivar' : 'activar' ?>"
                                                        >

                                                        <?php if ($activo): ?>

                                                            <button
                                                                type="submit"
                                                                class="btn btn-outline-danger btn-sm"
                                                                data-accion="desactivar"
                                                                data-nombre="<?= escapar($usuario['nombre']) ?>"
                                                            >
                                                                <i class="bi bi-person-x me-1"></i>
                                                                Desactivar
                                                            </button>

                                                        <?php else: ?>

                                                            <button
                                                                type="submit"
                                                                class="btn btn-outline-success btn-sm"
                                                                data-accion="activar"
                                                                data-nombre="<?= escapar($usuario['nombre']) ?>"
                                                            >
                                                                <i class="bi bi-person-check me-1"></i>
                                                                Activar
                                                            </button>

                                                        <?php endif; ?>

                                                    </form>

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


<script>

document.addEventListener('DOMContentLoaded', function () {

    const formularios = document.querySelectorAll(
        '.form-cambiar-estado-usuario'
    );

    formularios.forEach(function (formulario) {

        let cambioConfirmado = false;
        let confirmacionEnCurso = false;

        formulario.addEventListener('submit', function (evento) {

            if (cambioConfirmado) {
                return;
            }

            if (typeof Swal === 'undefined') {
                return;
            }

            evento.preventDefault();

            if (confirmacionEnCurso) {
                return;
            }

            confirmacionEnCurso = true;

            const boton = formulario.querySelector(
                'button[type="submit"]'
            );

            const accion = boton.dataset.accion;
            const nombre = boton.dataset.nombre;

            const desactivar = accion === 'desactivar';

            Swal.fire({

                icon: desactivar ? 'warning' : 'question',

                title: desactivar
                    ? '¿Desactivar esta cuenta?'
                    : '¿Activar esta cuenta?',

                text: desactivar
                    ? 'La cuenta de ' + nombre +
                      ' perderá el acceso al sistema. Sus registros históricos se conservarán.'
                    : 'La cuenta de ' + nombre +
                      ' podrá volver a ingresar al sistema.',

                showCancelButton: true,

                confirmButtonText: desactivar
                    ? 'Sí, desactivar'
                    : 'Sí, activar',

                cancelButtonText: 'Cancelar',

                confirmButtonColor: desactivar
                    ? '#dc3545'
                    : '#198754',

                cancelButtonColor: '#6c757d',

                reverseButtons: true,

                allowOutsideClick: false

            }).then(function (respuesta) {

                confirmacionEnCurso = false;

                if (!respuesta.isConfirmed) {
                    return;
                }

                cambioConfirmado = true;

                boton.disabled = true;

                boton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span> Procesando...';

                formulario.requestSubmit();

            });

        });

    });

});

</script>

<?php

// ==========================================
// 7. CARGAR PIE DE PAGINA
// ==========================================

require_once __DIR__ . '/../includes/footer.php';

?>
