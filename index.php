<?php

/**
 * Pagina principal del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar configuraciones y conexion.
require_once __DIR__ . '/includes/bootstrap.php';

// Cargar funciones generales.
require_once __DIR__ . '/includes/functions.php';

// Cargar la cabecera del sistema.
require_once __DIR__ . '/includes/header.php';
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= escapar($appConfig['nombre_sistema']) ?>
    </title>

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background-color: #f4f7f6;
            font-family: Arial, sans-serif;
        }

        .navbar {
            background-color: #145c46;
        }

        .navbar-brand {
            font-weight: bold;
            color: white !important;
        }

        .hero {
            background: linear-gradient(
                135deg,
                #145c46 0%,
                #18785b 55%,
                #218c68 100%
            );

            color: white;

            padding: 65px 20px 75px;

            border-radius: 0 0 35px 35px;

            margin-bottom: 15px;

            box-shadow: 0 8px 25px rgba(20, 92, 70, 0.12);
        }

        main.container.py-5 {
            padding-top: 40px !important;
            padding-bottom: 60px !important;
        }

        .hero h1 {
            font-size: 2.5rem;
            font-weight: bold;
        }

        .hero p {
            font-size: 1.1rem;
            max-width: 650px;
            margin: 20px auto;
            color: #e5f5ee;
        }

        .btn-login {
            background-color: white;
            color: #145c46;
            font-weight: bold;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-login:hover {
            background-color: #e5f5ee;
            color: #145c46;
        }

        .section-title {
            color: #145c46;
            font-weight: bold;
        }

        .feature-card {
            border: none;
            border-radius: 16px;

            padding: 30px;

            height: 100%;

            background-color: #ffffff;

            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);

            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-6px);

            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.09);
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 35px;
            margin-bottom: 15px;
        }

        .feature-card h5 {
            color: #145c46;
            font-weight: bold;
        }

        footer {
            background-color: #145c46;
            color: white;
            padding: 20px;
            margin-top: 60px;
        }

    </style>

</head>

<body>

    <!-- ====================================== -->
    <!-- SECCION PRINCIPAL -->
    <!-- ====================================== -->

    <section class="hero text-center">

        <div class="container">

            <h1>
                Bienvenido al sistema
            </h1>

            <p>
                Una herramienta diseñada para facilitar la administración
                de la pulpería de Elena mediante el control de productos,
                inventario y registro de ventas.
            </p>

            <a
                href="<?= escapar(urlSistema('acceso.php')) ?>"
                class="btn-login"
            >
                Acceder al sistema
            </a>

        </div>

    </section>


    <!-- ====================================== -->
    <!-- MODULOS DEL SISTEMA -->
    <!-- ====================================== -->

    <main class="container py-5">

        <div class="text-center mb-5">

            <h2 class="section-title">
                Funciones principales
            </h2>

            <p class="text-muted">
                Conoce las principales funcionalidades de la aplicación.
            </p>

        </div>


        <div class="row g-4">


            <!-- MODULO DE VENTAS -->

            <div class="col-md-4">

                <div class="card feature-card">

                    <div class="feature-icon">
                        🧾
                    </div>

                    <h5>
                        Registro de ventas
                    </h5>

                    <p class="text-muted">

                        Permite registrar los productos vendidos,
                        calcular los importes y generar comprobantes
                        internos sencillos para las operaciones realizadas.

                    </p>

                </div>

            </div>


            <!-- MODULO DE INVENTARIO -->

            <div class="col-md-4">

                <div class="card feature-card">

                    <div class="feature-icon">
                        📦
                    </div>

                    <h5>
                        Control de inventario
                    </h5>

                    <p class="text-muted">

                        Facilita el registro de productos, las entradas
                        de mercancía, las salidas y la consulta de las
                        existencias disponibles.

                    </p>

                </div>

            </div>


            <!-- MODULO ADMINISTRATIVO -->

            <div class="col-md-4">

                <div class="card feature-card">

                    <div class="feature-icon">
                        📊
                    </div>

                    <h5>
                        Administración
                    </h5>

                    <p class="text-muted">

                        Permite supervisar las ventas, consultar los
                        movimientos de inventario y administrar los
                        usuarios del sistema según sus permisos.

                    </p>

                </div>

            </div>

        </div>

    </main>



    <!-- Bootstrap JavaScript -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>

</html>


<?php
//  Cargar el pie de pagina del sistema.
require_once __DIR__ . '/includes/footer.php';
?>  