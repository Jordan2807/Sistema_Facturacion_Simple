<?php

/**
 * Sistema de alertas y notificaciones.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar los archivos necesarios.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/functions.php';

// Obtener el mensaje temporal almacenado en la sesion.
$mensajeFlash = obtenerMensaje();

// Valores predeterminados.
$tipoMensaje = 'info';
$textoMensaje = '';

// Verificar que exista un mensaje valido.
if (is_array($mensajeFlash)) {

    $tipoRecibido = $mensajeFlash['tipo'] ?? 'info';

    $tiposPermitidos = [
        'success',
        'error',
        'warning',
        'info'
    ];

    if (in_array($tipoRecibido, $tiposPermitidos, true)) {
        $tipoMensaje = $tipoRecibido;
    }

    $textoMensaje = (string) (
        $mensajeFlash['mensaje'] ?? ''
    );

}

// Titulos segun el tipo de notificacion.
$titulos = [
    'success' => 'Operacion exitosa',
    'error' => 'Ha ocurrido un error',
    'warning' => 'Atencion',
    'info' => 'Informacion'
];

$tituloMensaje = $titulos[$tipoMensaje];

?>

<!-- ====================================== -->
<!-- LIBRERIA SWEETALERT2 -->
<!-- ====================================== -->

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js"></script>


<!-- ====================================== -->
<!-- MOSTRAR NOTIFICACIONES -->
<!-- ====================================== -->

<?php if ($textoMensaje !== ''): ?>

<script>

    document.addEventListener('DOMContentLoaded', function () {

        Swal.fire({

            toast: true,

            position: 'top-end',

            icon: <?= json_encode($tipoMensaje, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,

            title: <?= json_encode($tituloMensaje, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,

            text: <?= json_encode($textoMensaje, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,

            showConfirmButton: false,

            timer: 3500,

            timerProgressBar: true

        });

    });

</script>

<?php endif; ?>
