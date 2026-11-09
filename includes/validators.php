<?php

/**
 * Validaciones generales del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */


// ==========================================
// 1. VALIDAR CAMPOS OBLIGATORIOS
// ==========================================

function validarRequerido(mixed $valor): bool
{
    if (!is_scalar($valor) || $valor === null) {
        return false;
    }

    return trim((string) $valor) !== '';
}


// ==========================================
// 2. VALIDAR CORREOS ELECTRONICOS
// ==========================================

function validarCorreo(string $correo): bool
{
    return filter_var(
        trim($correo),
        FILTER_VALIDATE_EMAIL
    ) !== false;
}


// ==========================================
// 3. VALIDAR LONGITUD DE TEXTO
// ==========================================

function validarLongitud(
    string $texto,
    int $minimo,
    int $maximo
): bool {

    if ($minimo < 0 || $maximo < $minimo) {
        return false;
    }

    $longitud = function_exists('mb_strlen')
        ? mb_strlen(trim($texto), 'UTF-8')
        : strlen(trim($texto));

    return $longitud >= $minimo && $longitud <= $maximo;
}


// ==========================================
// 4. VALIDAR NUMEROS ENTEROS
// ==========================================

function validarEntero(
    mixed $valor,
    int $minimo = 0,
    ?int $maximo = null
): bool {

    if (!is_scalar($valor) || $valor === '') {
        return false;
    }

    $numero = filter_var(
        $valor,
        FILTER_VALIDATE_INT
    );

    if ($numero === false) {
        return false;
    }

    if ($numero < $minimo) {
        return false;
    }

    if ($maximo !== null && $numero > $maximo) {
        return false;
    }

    return true;
}


// ==========================================
// 5. VALIDAR PRECIOS Y MONTOS
// ==========================================

function validarMonto(
    mixed $valor,
    bool $permitirCero = true
): bool {

    if (!is_scalar($valor)) {
        return false;
    }

    $valor = trim((string) $valor);

    // Aceptar numeros con maximo dos decimales.
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $valor)) {
        return false;
    }

    $monto = (float) $valor;

    // Limite de DECIMAL(10,2) en MySQL.
    if ($monto > 99999999.99) {
        return false;
    }

    if ($permitirCero) {
        return $monto >= 0;
    }

    return $monto > 0;
}


// ==========================================
// 6. VALIDAR CODIGO DE BARRAS
// ==========================================

function validarCodigoBarras(string $codigo): bool
{
    $codigo = trim($codigo);

    if ($codigo === '') {
        return false;
    }

    return (bool) preg_match(
        '/^[a-zA-Z0-9_-]{1,50}$/',
        $codigo
    );
}


// ==========================================
// 7. VALIDAR CANTIDADES DE INVENTARIO
// ==========================================

function validarCantidad(mixed $cantidad): bool
{
    return validarEntero($cantidad, 1, 2147483647);
}


// ==========================================
// 8. VALIDAR EXISTENCIAS
// ==========================================

function validarExistencias(mixed $existencias): bool
{
    return validarEntero($existencias, 0, 2147483647);
}


// ==========================================
// 9. VALIDAR ROLES DE USUARIOS
// ==========================================

function validarRolUsuario(string $rol): bool
{
    $rolesPermitidos = [
        'administrador',
        'cajero',
        'almacen'
    ];

    return in_array(
        $rol,
        $rolesPermitidos,
        true
    );
}


// ==========================================
// 10. VALIDAR METODOS DE PAGO
// ==========================================

function validarMetodoPago(string $metodo): bool
{
    $metodosPermitidos = [
        'efectivo',
        'tarjeta',
        'sinpe',
        'otro'
    ];

    return in_array(
        $metodo,
        $metodosPermitidos,
        true
    );
}
