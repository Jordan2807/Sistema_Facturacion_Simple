<?php

/**
 * Pie de pagina general del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Obtener el nombre del sistema.
$nombreSistema = $nombreSistema ?? 'Sistema de Facturacion Sencilla';

?>

<!-- ====================================== -->
<!-- PIE DE PAGINA -->
<!-- ====================================== -->

<footer class="footer-principal">

    <div class="container text-center">

        <p class="mb-1 footer-titulo">

            <?= escapar($nombreSistema) ?>

        </p>

        <p class="mb-0 footer-descripcion">

            Sistema de administracion de la pulperia de Elena.

        </p>

        <small class="footer-copyright">

            &copy; <?= date('Y') ?> Todos los derechos reservados.

        </small>

    </div>

</footer>


<!-- ====================================== -->
<!-- ESTILOS DEL PIE DE PAGINA -->
<!-- ====================================== -->

<style>

    .footer-principal {

        background-color: #145c46;

        color: #ffffff;

        padding: 25px 15px;

        margin-top: 60px;

    }

    .footer-titulo {

        font-size: 1rem;

        font-weight: bold;

    }

    .footer-descripcion {

        color: #d9f2e7;

        font-size: 0.9rem;

        margin-bottom: 8px;

    }

    .footer-copyright {

        color: #c5dfd4;

        font-size: 0.8rem;

    }

</style>


<!-- ====================================== -->
<!-- JAVASCRIPT DE BOOTSTRAP -->
<!-- ====================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>


</body>

</html>
