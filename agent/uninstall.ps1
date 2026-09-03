# Desinstalador del agente de ISP Gestor para Windows.
#
#   & "$env:ProgramFiles\ispgestor-agent\uninstall.ps1"
#
# Hay que ejecutarlo en PowerShell ABIERTO COMO ADMINISTRADOR: borra tareas
# programadas y ficheros de Program Files.
#
# Lo que NO hace: borrar el agente en el panel. Eso exige la contraseña del
# operador y se hace desde ahí; aquí solo se avisa.

param([switch]$Yes)

$ErrorActionPreference = 'Stop'

$Prefix    = Join-Path $env:ProgramFiles 'ispgestor-agent'
$ConfigDir = Join-Path $env:ProgramData 'ispgestor-agent'

function Rojo  { param($m) Write-Host $m -ForegroundColor Red }
function Verde { param($m) Write-Host $m -ForegroundColor Green }
function Info  { param($m) Write-Host "> $m" -ForegroundColor Cyan }
function Aviso { param($m) Write-Host "! $m" -ForegroundColor Yellow }

$identidad = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $identidad.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Rojo 'x Hay que ejecutarlo desde PowerShell abierto como administrador.'
    exit 1
}

# Las tareas se buscan por nombre y no por los ficheros de configuración: si
# alguien borró la carpeta a mano, la tarea seguiría ahí reintentando para
# siempre, y es justo lo que hay que limpiar.
$tareas = @(Get-ScheduledTask -TaskName 'ispgestor-agent-*' -ErrorAction SilentlyContinue)

Write-Host ''
Write-Host 'Se va a desinstalar el agente de ISP Gestor de esta máquina.'
if ($tareas.Count -gt 0) {
    Write-Host "Agentes instalados: $(($tareas | ForEach-Object { $_.TaskName -replace '^ispgestor-agent-', '' }) -join ', ')"
}
Write-Host ''
Write-Host 'Se borrará:'
Write-Host "  - $Prefix (código y entorno virtual)"
Write-Host "  - $ConfigDir (configuración y credenciales)"
Write-Host '  - las tareas programadas del agente'
Write-Host ''

if (-not $Yes) {
    $respuesta = Read-Host 'Seguir? Escribe "si" para continuar'
    if ($respuesta -ne 'si') {
        Write-Host 'Cancelado. No se ha tocado nada.'
        exit 0
    }
}

foreach ($t in $tareas) {
    Info "Deteniendo $($t.TaskName)."
    Stop-ScheduledTask -TaskName $t.TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $t.TaskName -Confirm:$false -ErrorAction SilentlyContinue
}

# Se espera un momento: si el proceso todavía tiene abiertos los ficheros del
# entorno virtual, borrar la carpeta falla con «acceso denegado».
Start-Sleep -Seconds 2

Info "Borrando $Prefix y $ConfigDir."
foreach ($ruta in @($Prefix, $ConfigDir)) {
    if (Test-Path $ruta) {
        Remove-Item -Recurse -Force $ruta -ErrorAction SilentlyContinue
    }
}

if ((Test-Path $Prefix) -or (Test-Path $ConfigDir)) {
    Aviso 'Quedaron ficheros sin borrar; lo normal es que algún proceso los tenga abiertos.'
    Write-Host '   Reinicia la máquina y vuelve a ejecutar esto.'
    exit 1
}

Write-Host ''
Verde 'OK. No queda nada del agente en esta máquina.'
Write-Host ''
Aviso 'Falta un paso, y no se puede hacer desde aquí:'
Write-Host '   El agente sigue registrado en el panel, en Red -> Agentes.'
Write-Host '   Bórralo o revócalo allí; si no, quedará como un agente que dejó de'
Write-Host '   responder, y sus credenciales seguirían siendo válidas.'
Write-Host ''
