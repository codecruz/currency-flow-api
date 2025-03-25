# Cargar el ensamblado necesario para mostrar MessageBox
Add-Type -AssemblyName System.Windows.Forms

# Ruta al directorio donde está index.php
$directory = "C:\xamppweb\htdocs\currencyflow"

# Cambiar al directorio correcto
Set-Location -Path $directory

# Verificar si ya hay un servidor PHP en ejecución
$serverProcess = Get-Process php -ErrorAction SilentlyContinue

if ($serverProcess) {
    Write-Host "Servidor PHP ya está en ejecución. Continuando con la solicitud HTTP..."
} else {
    Write-Host "Iniciando servidor PHP..."
    # Iniciar el servidor PHP y capturar la salida
    $serverProcess = Start-Process php -ArgumentList "-S 0.0.0.0:0" -NoNewWindow -PassThru

    # Obtener el puerto en el que el servidor está escuchando
    Start-Sleep -Seconds 2  # Esperar un momento para que el servidor se inicie
}

# Esperar 5 segundos
Start-Sleep -Seconds 5

# Construir la URL completa
$url = "http://127.0.0.1/index.php"
Write-Host "URL completa a la que se está llamando: $url"

# Realizar la solicitud HTTP usando la URL capturada
try {
    Invoke-WebRequest -Uri "http://127.0.0.1/currencyflow/index.php" -Method Get
    $response = Invoke-WebRequest -Uri $url -Method Get
    Write-Host "Solicitud HTTP realizada correctamente."
    Write-Host "Respuesta del servidor: $($response.StatusCode)"
} catch {
    Write-Host "Error al realizar la solicitud HTTP: $_"
    Write-Host $url
        # Mostrar un MessageBox en caso de error
    [System.Windows.Forms.MessageBox]::Show("Se ha producido un error al actualizar datos desde BCE `n`n$_", "CurrencyFlow: No se ha actualizado el tipo de cambio actual", [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Error)
}



