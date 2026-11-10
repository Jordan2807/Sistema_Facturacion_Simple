<?php

/**
 * CAMBIAR ESTADO DE USUARIOS
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Permite activar y desactivar usuarios.
 * Protege al ultimo administrador activo.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

/** @var PDO $pdo */

// Verificar permisos administrativos.
requerirRol('administrador');


// ==========================================
// 2. OBTENER INFORMACION DEL ADMINISTRADOR
// ==========================================

$administradorActual = obtenerUsuarioActual();

$idAdministradorActual = (int) $administradorActual['id_usuario'];


// ==========================================
// 3. VERIFICAR METODO DE SOLICITUD
// ==========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    header('Allow: POST');

    exit('Metodo de solicitud no permitido.');

}


// ==========================================
// 4. VERIFICAR TOKEN CSRF
// ==========================================

verificarTokenCSRF();


// ==========================================
// 5. VALIDAR INFORMACION RECIBIDA
// ==========================================

$idEntrada = $_POST['id_usuario'] ?? '';

$accion = $_POST['accion'] ?? '';

$idUsuario = filter_var(
    $idEntrada,
    FILTER_VALIDATE_INT
);

$accionesPermitidas = [
    'activar' => 1,
    'desactivar' => 0
];


if (
    $idUsuario === false ||
    $idUsuario === null ||
    $idUsuario <= 0 ||
    !is_string($accion) ||
    !isset($accionesPermitidas[$accion])
) {

    establecerMensaje(
        'error',
        'Los datos enviados no son validos.'
    );

    redirigir('admin/usuarios.php');

}


// Estado que se desea establecer.
$nuevoEstado = $accionesPermitidas[$accion];


// ==========================================
// 6. PREPARAR BLOQUEO ADMINISTRATIVO
// ==========================================

$nombreBloqueo = 'pulperia_elena_admins_guard';

$bloqueoAdquirido = false;

$mensajeResultado = '';

$tipoMensaje = 'success';


// ==========================================
// 7. PROCESAR CAMBIO DE ESTADO
// ==========================================

try {

    // ======================================
    // 7.1 ADQUIRIR BLOQUEO
    // ======================================

    /**
     * Utilizamos el mismo bloqueo que editar_usuario.php.
     * Esto evita conflictos al modificar roles y estados
     * de administradores desde distintas operaciones.
     */

    $consultaBloqueo = $pdo->prepare(
        'SELECT GET_LOCK(:nombre, 5)'
    );

    $consultaBloqueo->execute([
        'nombre' => $nombreBloqueo
    ]);

    if ((int) $consultaBloqueo->fetchColumn() !== 1) {

        throw new RuntimeException(
            'No fue posible procesar la operacion. Intenta nuevamente.'
        );

    }

    $bloqueoAdquirido = true;


    // ======================================
    // 7.2 INICIAR TRANSACCION
    // ======================================

    $pdo->beginTransaction();


    // ======================================
    // 7.3 CONSULTAR USUARIO
    // ======================================

    $consultaUsuario = $pdo->prepare(
        "SELECT
            id_usuario,
            nombre,
            rol,
            estado
         FROM usuarios
         WHERE id_usuario = :id_usuario
         FOR UPDATE"
    );

    $consultaUsuario->execute([
        'id_usuario' => $idUsuario
    ]);

    $usuario = $consultaUsuario->fetch();


    if (!$usuario) {

        throw new DomainException(
            'El usuario seleccionado no existe.'
        );

    }


    $estadoActual = (int) $usuario['estado'];


    // ======================================
    // 7.4 IMPEDIR MODIFICAR LA PROPIA CUENTA
    // ======================================

    if ($idUsuario === $idAdministradorActual) {

        throw new DomainException(
            'No puedes modificar el estado de tu propia cuenta.'
        );

    }


    // ======================================
    // 7.5 COMPROBAR SI YA TIENE ESE ESTADO
    // ======================================

    if ($estadoActual === $nuevoEstado) {

        $pdo->commit();

        $mensajeResultado = $nuevoEstado === 1
            ? 'El usuario ya se encuentra activo.'
            : 'El usuario ya se encuentra desactivado.';

        $tipoMensaje = 'info';

    } else {


        // ======================================
        // 7.6 PROTEGER ULTIMO ADMINISTRADOR
        // ======================================

        if (
            $usuario['rol'] === 'administrador' &&
            $estadoActual === 1 &&
            $nuevoEstado === 0
        ) {

            $consultaAdministradores = $pdo->query(
                "SELECT COUNT(*)
                 FROM usuarios
                 WHERE rol = 'administrador'
                 AND estado = 1"
            );

            $cantidadAdministradoresActivos = (int)
                $consultaAdministradores->fetchColumn();


            if ($cantidadAdministradoresActivos <= 1) {

                throw new DomainException(
                    'No puedes desactivar al ultimo administrador activo. Primero debes contar con otro administrador activo.'
                );

            }

        }


        // ======================================
        // 7.7 ACTUALIZAR ESTADO DEL USUARIO
        // ======================================

        $consultaActualizar = $pdo->prepare(
            "UPDATE usuarios
             SET estado = :estado
             WHERE id_usuario = :id_usuario"
        );

        $consultaActualizar->execute([

            'estado' => $nuevoEstado,

            'id_usuario' => $idUsuario

        ]);


        if ($consultaActualizar->rowCount() !== 1) {

            throw new RuntimeException(
                'No fue posible actualizar el estado del usuario.'
            );

        }


        // ======================================
        // 7.8 CONFIRMAR TRANSACCION
        // ======================================

        $pdo->commit();


        $mensajeResultado = $nuevoEstado === 1
            ? 'El usuario fue activado correctamente.'
            : 'El usuario fue desactivado correctamente.';

    }


} catch (DomainException $e) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }

    $mensajeResultado = $e->getMessage();

    $tipoMensaje = 'error';


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }

    error_log(
        'Error al cambiar estado del usuario: ' .
        $e->getMessage()
    );

    $mensajeResultado = 'Ocurrio un error al modificar el estado del usuario.';

    $tipoMensaje = 'error';


} finally {

    // ======================================
    // 7.9 LIBERAR BLOQUEO
    // ======================================

    if ($bloqueoAdquirido) {

        try {

            $consultaLiberar = $pdo->prepare(
                'SELECT RELEASE_LOCK(:nombre)'
            );

            $consultaLiberar->execute([
                'nombre' => $nombreBloqueo
            ]);

        } catch (Throwable $e) {

            error_log(
                'Error al liberar bloqueo administrativo: ' .
                $e->getMessage()
            );

        }

    }

}


// ==========================================
// 8. MOSTRAR RESULTADO
// ==========================================

establecerMensaje(
    $tipoMensaje,
    $mensajeResultado
);


// ==========================================
// 9. REGRESAR AL LISTADO
// ==========================================

redirigir('admin/usuarios.php');

