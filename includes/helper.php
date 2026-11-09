<?php

/**
 * Funciones auxiliares del sistema.
 * Sistema de facturacion sencilla y control de inventario.
 */


// ==========================================
// 1. FORMATEAR FECHAS
// ==========================================

function formatearFecha(?string $fecha): string
{
    if ($fecha === null || trim($fecha) === '') {
        return '';
    }

    try {

        $fechaObjeto = new DateTimeImmutable($fecha);

        return $fechaObjeto->format('d/m/Y');

    } catch (Throwable $e) {

        return '';

    }
}


// ==========================================
// 2. FORMATEAR FECHA Y HORA
// ==========================================

function formatearFechaHora(?string $fecha): string
{
    if ($fecha === null || trim($fecha) === '') {
        return '';
    }

    try {

        $fechaObjeto = new DateTimeImmutable($fecha);

        return $fechaObjeto->format('d/m/Y H:i');

    } catch (Throwable $e) {

        return '';

    }
}


// ==========================================
// 3. GENERAR NUMERO DE COMPROBANTE INTERNO
// ==========================================

function generarNumeroComprobante(int $idVenta): string
{
    if ($idVenta <= 0) {

        throw new InvalidArgumentException(
            'El identificador de la venta debe ser mayor que cero.'
        );

    }

    return 'COMP-' . str_pad(
        (string) $idVenta,
        6,
        '0',
        STR_PAD_LEFT
    );
}


// ==========================================
// 4. DETERMINAR ESTADO DEL INVENTARIO
// ==========================================

function obtenerEstadoInventario(
    int $stockActual,
    int $stockMinimo
): string {

    if ($stockActual < 0 || $stockMinimo < 0) {

        throw new InvalidArgumentException(
            'Las existencias no pueden ser negativas.'
        );

    }

    if ($stockActual === 0) {

        return 'sin_stock';

    }

    if ($stockActual <= $stockMinimo) {

        return 'stock_bajo';

    }

    return 'disponible';
}


// ==========================================
// 5. CALCULAR SUBTOTAL DE UNA LINEA DE VENTA
// ==========================================

function calcularSubtotalLinea(
    float|int|string $precioUnitario,
    int $cantidad
): string {

    if (
        !is_numeric($precioUnitario) ||
        (float) $precioUnitario < 0
    ) {

        throw new InvalidArgumentException(
            'El precio unitario no es valido.'
        );

    }

    if ($cantidad <= 0) {

        throw new InvalidArgumentException(
            'La cantidad debe ser mayor que cero.'
        );

    }

    $subtotal = round(
        (float) $precioUnitario * $cantidad,
        2
    );

    return number_format(
        $subtotal,
        2,
        '.',
        ''
    );
}


// ==========================================
// 6. OBTENER EL NOMBRE LEGIBLE DE UN ROL
// ==========================================

function obtenerNombreRol(string $rol): string
{
    $roles = [

        'administrador' => 'Administrador',

        'cajero' => 'Cajero',

        'almacen' => 'Empleado de almacen'

    ];

    return $roles[$rol] ?? 'Usuario';
}


// ==========================================
// 7. VALIDAR IDENTIFICADOR NUMERICO
// ==========================================

function validarIdentificador(int|string $id): bool
{
    if (!is_numeric($id)) {
        return false;
    }

    return filter_var(
        $id,
        FILTER_VALIDATE_INT
    ) !== false && (int) $id > 0;
}
