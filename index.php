<?php

require 'vendor/autoload.php';

use Spatie\SimpleExcel\SimpleExcelReader;

$url = 'https://www.bde.es/webbe/es/estadisticas/compartido/datos/xlsx/tc_1_1.xlsx';

$fileContent = file_get_contents($url);

if ($fileContent === false) {
    die('Error al descargar el archivo');
}

$projectFolder = __DIR__;
$fileName = 'tc_1_1.xlsx';
$filePath = $projectFolder . '/' . $fileName;
if (file_exists($filePath)) {
    unlink($filePath);
}

if (file_put_contents($filePath, $fileContent) === false) {
    die('Error al guardar el archivo');
}

$rows = SimpleExcelReader::create($filePath)->getRows();

$data = $rows->toArray();

$monedas = $data[0];

$descripcionesSeries = $data[2];

$mapeoMonedas = [];
foreach ($monedas as $indice => $moneda) {
    if ($indice === 'NOMBRE DE LA SERIE') continue;
    if (preg_match('/\(([A-Z]{3})\/[A-Z]{3}\)/', $descripcionesSeries[$indice], $matches)) {
        $identificadorMoneda = $matches[1];
        $mapeoMonedas[$moneda] = $identificadorMoneda;
    }
}

$datosFechasValores = array_slice($data, 6);

$jsonData = [];

$mesesEspanolIngles = [
    'ENE' => 'Jan',
    'FEB' => 'Feb',
    'MAR' => 'Mar',
    'ABR' => 'Apr',
    'MAY' => 'May',
    'JUN' => 'Jun',
    'JUL' => 'Jul',
    'AGO' => 'Aug',
    'SEP' => 'Sep',
    'OCT' => 'Oct',
    'NOV' => 'Nov',
    'DIC' => 'Dec'
];

foreach ($datosFechasValores as $fila) {
    $fechaOriginal = $fila['NOMBRE DE LA SERIE'];
    $partesFecha = explode(' ', $fechaOriginal);
    if (count($partesFecha) === 3) {
        $dia = $partesFecha[0];
        $mesEspanol = strtoupper($partesFecha[1]);
        $anio = $partesFecha[2];

        $mesIngles = $mesesEspanolIngles[$mesEspanol] ?? 'Desconocido';

        $fechaIngles = "$dia $mesIngles $anio";

        $fechaFormateada = DateTime::createFromFormat('d M Y', $fechaIngles);
        if ($fechaFormateada) {
            $fechaFormateada = $fechaFormateada->format('d-m-Y');
        } else {
            $fechaFormateada = 'Fecha inválida';
        }
    } else {
        $fechaFormateada = 'Fecha inválida';
    }

    foreach ($monedas as $indice => $moneda) {
        if ($indice === 'NOMBRE DE LA SERIE') continue;
        $identificadorMoneda = $mapeoMonedas[$moneda] ?? 'Desconocido';

        $valor = $fila[$indice];

        $jsonData[$identificadorMoneda][] = [
            'fecha' => $fechaFormateada,
            'valor' => $valor
        ];
    }
}

$json = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$jsonFilePath = $projectFolder . '/tc_1_1.json';
if (file_put_contents($jsonFilePath, $json) === false) {
    die('Error al guardar el archivo JSON');
}

echo "El archivo JSON se ha guardado en: " . $jsonFilePath . "\n";
