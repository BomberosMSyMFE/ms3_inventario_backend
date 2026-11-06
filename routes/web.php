<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return response()->json([
        'servicio' => 'MS-03 Gestión de Inventario y Recursos',
        'semestre' => 'INF512 - Semestre 2/2026',
        'responsable' => 'Jael Sandoval',
        'graphql_endpoint' => '/graphql',
        'health_check' => '/health'
    ]);
});

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'CONECTADO';
    } catch (\Throwable $e) {
        $dbStatus = 'ERROR_CONEXION: ' . $e->getMessage();
    }

    return response()->json([
        'servicio' => 'MS-03 Inventario y Recursos',
        'estado' => 'OPERATIVO',
        'base_datos' => $dbStatus,
        'graphql_endpoint' => '/graphql',
        'timestamp' => now()->toIso8601String(),
    ]);
});
