<?php

/**
 * Menu lateral del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar autenticacion y funciones.
require_once __DIR__ . '/auth.php';

// Verificar que exista una sesion iniciada.
requerirAutenticacion();

// Obtener informacion del usuario.
$usuarioActual = obtenerUsuarioActual();

$rolUsuario = $usuarioActual['rol'] ?? '';


// ==========================================
// 1. DEFINIR OPCIONES SEGUN EL ROL
// ==========================================

$menusPorRol = [

    // MENU DEL ADMINISTRADOR
    
    'administrador' => [

        [
            'nombre' => 'Panel principal',
            'icono' => '🏠',
            'ruta' => 'admin/index.php'
        ],

        [
            'nombre' => 'Usuarios',
            'icono' => '👥',
            'ruta' => 'admin/usuarios.php'
        ],

        [
            'nombre' => 'Crear usuario',
            'icono' => '➕',
            'ruta' => 'admin/crear_usuario.php'
        ],

        [
            'nombre' => 'Solicitudes de recuperación',
            'icono' => '🔔',
            'ruta' => 'admin/solicitudes.php'
        ],

        [
            'nombre' => 'Restablecer contraseñas',
            'icono' => '🔐',
            'ruta' => 'admin/restablecer_password.php'
        ],

        [
            'nombre' => 'Inventario',
            'icono' => '📦',
            'ruta' => 'inventario/index.php'
        ],

        [
            'nombre' => 'Movimientos',
            'icono' => '🔄',
            'ruta' => 'inventario/movimientos.php'
        ],

        [
            'nombre' => 'Ventas',
            'icono' => '🧾',
            'ruta' => 'admin/ventas.php'
        ]

    ],



    // MENU DEL CAJERO
    'cajero' => [

        [
            'nombre' => 'Nueva venta',
            'icono' => '🛒',
            'ruta' => 'caja/index.php'
        ],

        [
            'nombre' => 'Historial de ventas',
            'icono' => '📋',
            'ruta' => 'caja/historial.php'
        ]

    ],


    // MENU DEL EMPLEADO DE ALMACEN
    'almacen' => [

        [
            'nombre' => 'Inventario',
            'icono' => '📦',
            'ruta' => 'inventario/index.php'
        ],

        [
            'nombre' => 'Entradas de productos',
            'icono' => '📥',
            'ruta' => 'inventario/entradas.php'
        ],

        [
            'nombre' => 'Movimientos',
            'icono' => '🔄',
            'ruta' => 'inventario/movimientos.php'
        ]

    ]

];


// Obtener las opciones correspondientes al usuario.
$opcionesMenu = $menusPorRol[$rolUsuario] ?? [];


// Obtener la direccion de la pagina actual.
$paginaActual = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

?>

<!-- ====================================== -->
<!-- MENU LATERAL -->
<!-- ====================================== -->

<aside class="col-12 col-lg-3 col-xl-2 mb-4">

    <div class="sidebar-principal">

        <!-- Informacion del usuario -->

        <div class="sidebar-usuario">

            <div class="sidebar-avatar">
                <?= strtoupper(substr($rolUsuario, 0, 1)) ?>
            </div>

            <div>

                <strong>
                    <?= escapar($usuarioActual['nombre'] ?? 'Usuario') ?>
                </strong>

                <small>
                    <?= escapar(ucfirst($rolUsuario)) ?>
                </small>

            </div>

        </div>


        <!-- Separador -->

        <div class="sidebar-separador"></div>


        <!-- Opciones del menu -->

        <span class="sidebar-titulo">
            MENU PRINCIPAL
        </span>


        <nav class="sidebar-navegacion">

            <?php foreach ($opcionesMenu as $opcion): ?>

                <?php

                $urlDestino = urlSistema($opcion['ruta']);

                $rutaDestino = parse_url(
                    $urlDestino,
                    PHP_URL_PATH
                );

                $activo = (
                    rtrim($paginaActual, '/') ===
                    rtrim($rutaDestino, '/')
                );

                ?>

                <a
                    href="<?= escapar($urlDestino) ?>"
                    class="sidebar-link <?= $activo ? 'activo' : '' ?>"
                    <?= $activo ? 'aria-current="page"' : '' ?>
                >

                    <span class="sidebar-icono">
                        <?= $opcion['icono'] ?>
                    </span>

                    <span>
                        <?= escapar($opcion['nombre']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </nav>

    </div>

</aside>


<!-- ====================================== -->
<!-- ESTILOS DEL MENU LATERAL -->
<!-- ====================================== -->

<style>

    .sidebar-principal {

        background-color: #ffffff;

        border-radius: 14px;

        padding: 22px 15px;

        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);

        min-height: 350px;

    }


    /* Informacion del usuario */

    .sidebar-usuario {

        display: flex;

        align-items: center;

        gap: 12px;

        padding: 5px 8px 15px;

    }

    .sidebar-avatar {

        width: 42px;

        height: 42px;

        border-radius: 50%;

        background-color: #e0f2e9;

        color: #145c46;

        display: flex;

        align-items: center;

        justify-content: center;

        font-weight: bold;

        font-size: 18px;

    }

    .sidebar-usuario strong {

        display: block;

        color: #145c46;

        font-size: 0.9rem;

    }

    .sidebar-usuario small {

        display: block;

        color: #777777;

        font-size: 0.8rem;

        margin-top: 3px;

    }


    /* Separador */

    .sidebar-separador {

        border-top: 1px solid #eeeeee;

        margin: 10px 0 20px;

    }


    /* Titulo del menu */

    .sidebar-titulo {

        display: block;

        color: #888888;

        font-size: 0.7rem;

        font-weight: bold;

        letter-spacing: 1px;

        padding: 0 10px 12px;

    }


    /* Navegacion */

    .sidebar-navegacion {

        display: flex;

        flex-direction: column;

        gap: 6px;

    }

    .sidebar-link {

        display: flex;

        align-items: center;

        gap: 12px;

        padding: 12px 13px;

        color: #555555;

        text-decoration: none;

        border-radius: 8px;

        font-size: 0.9rem;

        transition: all 0.2s ease;

    }

    .sidebar-link:hover {

        background-color: #edf6f1;

        color: #145c46;

    }

    .sidebar-link.activo {

        background-color: #145c46;

        color: #ffffff;

        font-weight: bold;

    }

    .sidebar-icono {

        font-size: 18px;

        width: 23px;

        text-align: center;

    }


    /* Adaptacion a pantallas pequenas */

    @media (max-width: 991px) {

        .sidebar-principal {

            min-height: auto;

        }

    }

</style>
