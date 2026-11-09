<?php

/**
 * Cabecera general del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 *
 * Requiere que previamente se hayan cargado:
 * bootstrap.php y functions.php
 */

// Verificar que la configuracion general este cargada.
if (!isset($appConfig) || !is_array($appConfig)) {

    $appConfig = require __DIR__ . '/../config/app.php';

}

// Obtener el nombre del sistema.
$nombreSistema = $appConfig['nombre_sistema'];

// Obtener el titulo de la pagina.
$tituloPagina = $tituloPagina ?? $nombreSistema;

// Obtener el usuario autenticado.
$usuarioActual = obtenerUsuarioActual();

// Determinar si existe una sesion iniciada.
$usuarioAutenticado = $usuarioActual !== null;

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= escapar($tituloPagina) ?> | <?= escapar($nombreSistema) ?>
    </title>

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Estilos generales -->
    <style>

        :root {
            --color-principal: #145c46;
            --color-secundario: #218c68;
            --color-fondo: #f4f7f6;
            --color-blanco: #ffffff;
        }

        body {
            background-color: var(--color-fondo);
            font-family: Arial, sans-serif;
            min-height: 100vh;
        }

        .navbar-principal {
            background-color: var(--color-principal);
            padding: 14px 0;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
        }

        .navbar-principal .navbar-brand {
            color: var(--color-blanco);
            font-size: 1.15rem;
            font-weight: bold;
        }

        .navbar-principal .navbar-brand:hover {
            color: #d9f2e7;
        }

        .navbar-principal .nav-link {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
        }

        .navbar-principal .nav-link:hover {
            color: var(--color-blanco);
        }

        .navbar-principal .btn-acceso {
            background-color: var(--color-blanco);
            color: var(--color-principal);
            font-weight: bold;
            border-radius: 7px;
            padding: 8px 18px;
            text-decoration: none;
        }

        .navbar-principal .btn-acceso:hover {
            background-color: #e5f5ee;
        }

        .usuario-navbar {
            color: var(--color-blanco);
            font-size: 0.9rem;
        }

        .badge-rol {
            background-color: var(--color-secundario);
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 0.75rem;
            text-transform: capitalize;
        }

        .navbar-principal .navbar-toggler {
            border-color: rgba(255, 255, 255, 0.5);
        }

        .navbar-principal .navbar-toggler-icon {
            filter: invert(1);
        }

    </style>

</head>

<body>

    <!-- ====================================== -->
    <!-- BARRA DE NAVEGACION -->
    <!-- ====================================== -->

    <nav class="navbar navbar-expand-lg navbar-principal">

        <div class="container">

            <!-- Nombre del sistema -->

            <a
                class="navbar-brand"
                href="<?= escapar(urlSistema()) ?>"
            >

                <?= escapar($nombreSistema) ?>

            </a>


            <!-- Boton para dispositivos moviles -->

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#menuPrincipal"
                aria-controls="menuPrincipal"
                aria-expanded="false"
                aria-label="Mostrar navegacion"
            >

                <span class="navbar-toggler-icon"></span>

            </button>


            <!-- Opciones de navegacion -->

            <div
                class="collapse navbar-collapse"
                id="menuPrincipal"
            >

                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">

                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="<?= escapar(urlSistema()) ?>"
                        >
                            Inicio
                        </a>

                    </li>


                    <?php if (!$usuarioAutenticado): ?>

                        <!-- Opciones para visitantes -->

                        <li class="nav-item">

                            <a
                                class="btn-acceso"
                                href="<?= escapar(urlSistema('acceso.php')) ?>"
                            >
                                Iniciar sesión
                            </a>

                        </li>

                    <?php else: ?>

                        <!-- Informacion del usuario autenticado -->

                        <li class="nav-item">

                            <span class="usuario-navbar">

                                <?= escapar($usuarioActual['nombre'] ?? 'Usuario') ?>

                                &nbsp;

                                <span class="badge-rol">
                                    <?= escapar($usuarioActual['rol'] ?? 'usuario') ?>
                                </span>

                            </span>

                        </li>

                    <?php endif; ?>

                </ul>

            </div>

        </div>

    </nav>
