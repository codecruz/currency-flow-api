<?php

// Ruta al archivo JSON generado
$jsonFilePath = '../tc_1_1.json';

// Cargar el archivo JSON
if (!file_exists($jsonFilePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'El archivo JSON no existe']);
    exit;
}

$jsonData = file_get_contents($jsonFilePath);
$data = json_decode($jsonData, true);

// Obtener los parámetros de la solicitud
$fecha = $_GET['fecha'] ?? null; // Ejemplo: ?fecha=05-01-1999
$moneda = $_GET['moneda'] ?? null; // Ejemplo: ?moneda=CZK

// Validar los parámetros
if (!$fecha || !$moneda) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan parámetros: fecha y moneda son obligatorios']);
    exit;
}

// Buscar el valor correspondiente
$valor = null;
if (isset($data[$moneda])) {
    foreach ($data[$moneda] as $registro) {
        if ($registro['fecha'] === $fecha) {
            $valor = $registro['valor'];
            break;
        }
    }
}

// Responder solo con el valor (o null si no existe)
echo json_encode($valor);