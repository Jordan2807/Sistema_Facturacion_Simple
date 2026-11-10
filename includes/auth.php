<?php

/**
 * CONTROL DE AUTENTICACION Y PERMISOS
 * Sistema de Facturacion Sencilla y Control de Inventario.
 *
 * Verifica las sesiones y los permisos directamente
 * contra la base de datos.
 */

// ==========================================
// 1. CARGAR ARCHIVOS NECESARIOS
// ==========================================

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/functions.php';


// ==========================================
// 2. INVALIDAR SESION
// ==========================================

function invalidarSesionPorCuenta(string $mensaje): void
{
    // Eliminar los datos de la sesion.
    $_SESSION = [];

    // Regenerar el identificador para invalidar
    // la sesion anterior.
    if (!session_regenerate_id(true)) {

        session_destroy();

        http_response_code(500);

        exit('No fue posible finalizar la sesion.');

    }

    // Guardar mensaje para el siguiente acceso.
    establecerMensaje('warning', $mensaje);

    // Redirigir al inicio de sesion.
    redirigir('login.php');
}


// ==========================================
// 3. VERIFICAR AUTENTICACION
// ==========================================

function requerirAutenticacion(): void
{
    global $pdo;

    // Obtener informacion de la sesion.
    $usuario = obtenerUsuarioActual();


    // ======================================
    // 3.1 COMPROBAR EXISTENCIA DE SESION
    // ======================================

    if ($usuario === null) {

        establecerMensaje(
            'warning',
            'Debes iniciar sesion para acceder a esta pagina.'
        );

        redirigir('login.php');

    }


    // ======================================
    // 3.2 VALIDAR IDENTIFICADOR
    // ======================================

    $idUsuario = filter_var(
        $usuario['id_usuario'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $idUsuario === false ||
        $idUsuario === null ||
        $idUsuario <= 0
    ) {

        invalidarSesionPorCuenta(
            'Tu sesion no es valida. Inicia sesion nuevamente.'
        );

    }


    // ======================================
    // 3.3 CONSULTAR USUARIO EN MYSQL
    // ======================================

    try {

        $consultaUsuario = $pdo->prepare(
            "SELECT
                id_usuario,
                nombre,
                correo,
                rol,
                estado
             FROM usuarios
             WHERE id_usuario = :id_usuario
             LIMIT 1"
        );

        $consultaUsuario->execute([

            'id_usuario' => $idUsuario

        ]);

        $usuarioBD = $consultaUsuario->fetch();


    } catch (Throwable $e) {

        // Registrar error internamente.
        error_log(
            'Error al verificar autenticacion: ' .
            $e->getMessage()
        );

        // No permitir continuar sin comprobar la cuenta.
        http_response_code(503);

        exit(
            'No fue posible verificar tu cuenta. Intenta nuevamente.'
        );

    }


    // ======================================
    // 3.4 COMPROBAR QUE EL USUARIO EXISTA
    // ======================================

    if (!$usuarioBD) {

        invalidarSesionPorCuenta(
            'Tu cuenta ya no esta disponible. Contacta al administrador.'
        );

    }


    // ======================================
    // 3.5 COMPROBAR ESTADO DE LA CUENTA
    // ======================================

    if ((int) $usuarioBD['estado'] !== 1) {

        invalidarSesionPorCuenta(
            'Tu cuenta ha sido desactivada. Contacta al administrador.'
        );

    }


    // ======================================
    // 3.6 VALIDAR ROL ACTUAL
    // ======================================

    $rolesPermitidos = [

        'administrador',

        'cajero',

        'almacen'

    ];

    if (
        !in_array(
            $usuarioBD['rol'],
            $rolesPermitidos,
            true
        )
    ) {

        invalidarSesionPorCuenta(
            'Tu cuenta tiene un rol no valido. Contacta al administrador.'
        );

    }


    // ======================================
    // 3.7 ACTUALIZAR INFORMACION DE SESION
    // ======================================

    /**
     * Actualizamos los datos de la sesion con los valores
     * actuales de MySQL.
     *
     * Si el administrador modifico el nombre, correo
     * o rol del usuario, los cambios se reflejaran
     * automaticamente en la siguiente solicitud.
     */

    $_SESSION['usuario'] = [

        'id_usuario' => (int) $usuarioBD['id_usuario'],

        'nombre' => $usuarioBD['nombre'],

        'correo' => $usuarioBD['correo'],

        'rol' => $usuarioBD['rol']

    ];

}


// ==========================================
// 4. VERIFICAR PERMISOS SEGUN EL ROL
// ==========================================

function requerirRol(string ...$rolesPermitidos): void
{
    // Evitar utilizar la funcion sin especificar roles.
    if (empty($rolesPermitidos)) {

        throw new InvalidArgumentException(
            'Debes especificar al menos un rol permitido.'
        );

    }


    // Comprobar autenticacion y actualizar
    // la informacion desde MySQL.
    requerirAutenticacion();


    // Comprobar si tiene alguno de los roles solicitados.
    if (!tieneRol(...$rolesPermitidos)) {

        establecerMensaje(
            'error',
            'No tienes permisos para acceder a esta seccion.'
        );

        redirigir('index.php');

    }

}
