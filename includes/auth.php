<?php

/**
 * Control de autenticacion y permisos.
 * Sistema de facturacion sencilla y control de inventario.
 */

// Cargar configuracion, sesion y conexion.
require_once __DIR__ . '/bootstrap.php';

// Cargar funciones generales.
require_once __DIR__ . '/functions.php';


// ==========================================
// 1. VERIFICAR QUE EL USUARIO ESTE AUTENTICADO
// ==========================================

function requerirAutenticacion(): void
{
    $usuario = obtenerUsuarioActual();

    // Comprobar que exista un usuario con informacion valida.
    if (
        $usuario === null ||
        !isset($usuario['id_usuario'], $usuario['rol']) ||
        !is_numeric($usuario['id_usuario']) ||
        (int) $usuario['id_usuario'] <= 0
    ) {

        // Eliminar los datos de usuario invalidos.
        unset($_SESSION['usuario']);

        // Informar que necesita iniciar sesion.
        establecerMensaje(
            'warning',
            'Debes iniciar sesion para acceder a esta pagina.'
        );

        // Enviar al inicio de sesion.
        redirigir('login.php');
    }
}


// ==========================================
// 2. VERIFICAR LOS PERMISOS SEGUN EL ROL
// ==========================================

function requerirRol(string ...$rolesPermitidos): void
{
    // Evitar llamar la funcion sin especificar roles.
    if (empty($rolesPermitidos)) {
        throw new InvalidArgumentException(
            'Debes especificar al menos un rol permitido.'
        );
    }

    // Comprobar que el usuario haya iniciado sesion.
    requerirAutenticacion();

    // Verificar si tiene alguno de los roles autorizados.
    if (!tieneRol(...$rolesPermitidos)) {

        establecerMensaje(
            'error',
            'No tienes permisos para acceder a esta seccion.'
        );

        // Redirigir a la pagina principal.
        redirigir('index.php');
    }
}
