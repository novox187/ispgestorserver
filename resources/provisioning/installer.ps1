# Instalador desatendido del agente de ISP Gestor para Windows.
#
# Este fichero NO se edita a mano: lo genera el panel por cada agente, con su
# token de enrolamiento y el código del propio agente ya incrustados.
#
#   irm "<enlace del panel>" | iex
#
# Tiene que correr en una consola de PowerShell ABIERTA COMO ADMINISTRADOR: crea
# servicios, escribe en Program Files y abre puertos.

$ErrorActionPreference = 'Stop'

$ApiUrl          = '{{API_URL}}'
$EnrollmentToken = '{{TOKEN}}'
$Role            = '{{ROLE}}'
$AgentName       = '{{AGENT_NAME}}'

$Prefix    = Join-Path $env:ProgramFiles 'ispgestor-agent'
$ConfigDir = Join-Path $env:ProgramData 'ispgestor-agent'
$SrcDir    = Join-Path $env:TEMP ("ispgestor-agent-src-" + [guid]::NewGuid().ToString('N'))

# Nombre de la tarea programada. Se separa por instancia igual que en Linux, para
# que una máquina pueda tener dos roles sin que el segundo pise al primero.
$Instancia = 'agent'

function Rojo  { param($m) Write-Host $m -ForegroundColor Red }
function Verde { param($m) Write-Host $m -ForegroundColor Green }
function Info  { param($m) Write-Host "> $m" -ForegroundColor Cyan }
function Aviso { param($m) Write-Host "! $m" -ForegroundColor Yellow }

function Morir {
    param($m)
    Rojo "x $m"
    if (Test-Path $SrcDir) { Remove-Item -Recurse -Force $SrcDir -ErrorAction SilentlyContinue }
    exit 1
}

Write-Host ''
Verde '-- Instalador del agente de ISP Gestor --'
Write-Host "   Agente: $AgentName"
Write-Host "   Rol:    $Role"
Write-Host "   API:    $ApiUrl"
Write-Host ''

# ── 1. Requisitos ────────────────────────────────────────────────────────────

$identidad = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $identidad.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Morir 'Hay que ejecutarlo desde PowerShell abierto como administrador.'
}

if ($Role -eq 'vpn_host') {
    Morir 'El rol vpn_host administra el WireGuard del hosting y solo tiene sentido en el servidor Linux.'
}

# Se busca el lanzador `py` antes que `python`: en Windows `python` puede ser el
# alias de la Microsoft Store, que abre la tienda en vez de ejecutar nada.
$python = $null
foreach ($candidato in @('py', 'python3', 'python')) {
    $cmd = Get-Command $candidato -ErrorAction SilentlyContinue
    if ($null -eq $cmd) { continue }

    # El alias de la tienda es un fichero de cero bytes en WindowsApps.
    if ($cmd.Source -like '*WindowsApps*' -and (Get-Item $cmd.Source).Length -eq 0) { continue }

    try {
        $version = & $cmd.Source -c 'import sys; print("%d.%d" % sys.version_info[:2])' 2>$null
        if ($version -match '^3\.(9|1[0-9])') { $python = $cmd.Source; break }
    } catch { continue }
}

if ($null -eq $python) {
    Rojo 'x No se encontró Python 3.9 o superior.'
    Write-Host ''
    Write-Host '   Instálalo con una de estas dos:'
    Write-Host '     winget install -e --id Python.Python.3.12'
    Write-Host '     o descárgalo de https://www.python.org/downloads/'
    Write-Host ''
    Write-Host '   Marca "Add python.exe to PATH" en el instalador, cierra esta ventana,'
    Write-Host '   abre otra como administrador y vuelve a ejecutar esta orden.'
    exit 1
}

Info "Python encontrado: $python ($version)"

# ── 2. Desplegar el código incrustado ────────────────────────────────────────

Info 'Extrayendo el agente.'
New-Item -ItemType Directory -Force -Path $SrcDir | Out-Null

# El paquete viaja en base64 dentro de este script para que la instalación no
# dependa de una segunda descarga.
$payload = @'
{{PAYLOAD}}
'@

$zip = Join-Path $SrcDir 'agente.zip'

try {
    # Se quitan los saltos de línea con los que se partió el base64 para que el
    # script sea legible: FromBase64String no los admite.
    [IO.File]::WriteAllBytes($zip, [Convert]::FromBase64String(($payload -replace '\s', '')))
    Expand-Archive -Path $zip -DestinationPath $SrcDir -Force
} catch {
    Morir "El paquete incrustado está corrupto: $($_.Exception.Message)"
}

if (-not (Test-Path (Join-Path $SrcDir 'ispgestor_agent'))) {
    Morir 'El paquete no contiene el agente.'
}

# ── 3. Instalar ──────────────────────────────────────────────────────────────

# ¿Hay ya un agente aquí, y con qué rol? Igual que en Linux: si es otro rol, este
# se instala al lado en vez de pisarle las credenciales.
$configPrevia = Join-Path $ConfigDir 'agent.conf'
$rolPrevio = ''

if (Test-Path $configPrevia) {
    try {
        $rolPrevio = (Get-Content $configPrevia -Raw | ConvertFrom-Json).role
    } catch { $rolPrevio = '' }
}

if ($rolPrevio -and $rolPrevio -ne $Role) {
    $Instancia = $Role
    Info "Esta máquina ya tiene un agente '$rolPrevio'. Este se instala aparte, como instancia '$Role'."
}

$tarea  = "ispgestor-agent-$Instancia"
$config = Join-Path $ConfigDir "$Instancia.conf"

# Si el que vamos a reemplazar estaba corriendo, se para antes de tocar sus
# ficheros. Solo ese: los de otros roles siguen trabajando.
if (Get-ScheduledTask -TaskName $tarea -ErrorAction SilentlyContinue) {
    Info 'Deteniendo el agente que ya estaba instalado.'
    Stop-ScheduledTask -TaskName $tarea -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $tarea -Confirm:$false -ErrorAction SilentlyContinue
}

Info "Instalando en $Prefix."

# Se borra antes de copiar: copiar encima fusiona, y al reinstalar una versión
# con menos ficheros los sobrantes de la anterior seguirían ahí y se importarían.
$destinoModulo = Join-Path $Prefix 'ispgestor_agent'
if (Test-Path $destinoModulo) { Remove-Item -Recurse -Force $destinoModulo }

New-Item -ItemType Directory -Force -Path $Prefix | Out-Null
Copy-Item -Recurse -Force (Join-Path $SrcDir 'ispgestor_agent') $Prefix
Copy-Item -Force (Join-Path $SrcDir 'requirements.txt') $Prefix
# El desinstalador se deja puesto desde el principio: quien quiera quitar el
# agente no tiene por qué saber qué tareas y carpetas se crearon.
Copy-Item -Force (Join-Path $SrcDir 'uninstall.ps1') $Prefix

Info 'Preparando el entorno virtual.'
$venv = Join-Path $Prefix 'venv'
& $python -m venv $venv
if ($LASTEXITCODE -ne 0) { Morir 'No se pudo crear el entorno virtual.' }

$venvPython = Join-Path $venv 'Scripts\python.exe'
& $venvPython -m pip install --quiet --upgrade pip
& $venvPython -m pip install --quiet -r (Join-Path $Prefix 'requirements.txt')
if ($LASTEXITCODE -ne 0) { Morir 'No se pudieron instalar las dependencias.' }

# El directorio de configuración guarda el secreto HMAC con el que el agente
# firma. Se le corta la herencia: %ProgramData% deja leer al grupo Usuarios, así
# que sin esto el secreto lo podría leer cualquier cuenta de la máquina.
#
# Se usan los SID y no los nombres porque en un Windows en español el grupo se
# llama «Administradores» y la orden fallaría justo en la máquina del cliente.
New-Item -ItemType Directory -Force -Path $ConfigDir | Out-Null
& icacls $ConfigDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null

# ── 4. Parámetros propios del rol ────────────────────────────────────────────

$argumentos = @()

if ($Role -eq 'provisioner') {
    # La NIC de aprovisionamiento es el límite físico de seguridad del sistema:
    # solo se dan de alta equipos vistos por ella. Por eso se descarta la que
    # lleva la salida a internet.
    $rutaDefecto = Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
        Sort-Object RouteMetric | Select-Object -First 1
    $nicSalida = if ($rutaDefecto) {
        (Get-NetAdapter -InterfaceIndex $rutaDefecto.InterfaceIndex -ErrorAction SilentlyContinue).Name
    } else { $null }

    # Solo adaptadores físicos: los virtuales de Hyper-V, VPN o loopback no
    # tienen cable que vigilar.
    $candidatas = @(Get-NetAdapter -Physical -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -ne $nicSalida })

    if ($candidatas.Count -eq 0) {
        Aviso "No se encontró ninguna tarjeta libre (la única salida es '$nicSalida')."
        $elegida = Read-Host '   Escribe el nombre de la tarjeta de aprovisionamiento'
    } elseif ($candidatas.Count -eq 1) {
        $elegida = $candidatas[0].Name
        Info "Tarjeta de aprovisionamiento detectada: $elegida"
    } else {
        Write-Host "   Tarjetas disponibles (excluyendo '$nicSalida', que da la salida a internet):"
        foreach ($n in $candidatas) {
            # 1 = Connected. Se lee el valor numérico del enum y no su texto,
            # que el sistema traduce.
            $cable = if ($n.MediaConnectionState.value__ -eq 1) { 'con cable' } else { 'sin cable' }
            Write-Host ("     - {0,-28} {1}" -f $n.Name, $cable)
        }
        $elegida = Read-Host "   ¿En cuál se enchufan los routers? [$($candidatas[0].Name)]"
        if (-not $elegida) { $elegida = $candidatas[0].Name }
    }

    if (-not $elegida) { Morir 'Sin tarjeta de aprovisionamiento el agente no detectaría ningún equipo.' }
    $argumentos += @('--interfaces', $elegida)

} elseif ($Role -eq 'monitor') {
    # Los rangos que este agente aceptará barrer. Se guardan en ESTA máquina: el
    # servidor puede pedir un barrido, pero no puede ampliar la lista.
    $rangos = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' -and $_.PrefixLength -le 24 } |
        ForEach-Object {
            $octetos = $_.IPAddress.Split('.')
            "$($octetos[0]).$($octetos[1]).$($octetos[2]).0/24"
        } | Sort-Object -Unique)

    if ($rangos.Count -gt 0) {
        $propuesta = $rangos -join ','
        Write-Host '   Redes privadas que esta máquina alcanza:'
        foreach ($r in $rangos) { Write-Host "     - $r" }
        $cidrs = Read-Host "   ¿Cuáles podrá barrer? (coma) [$propuesta]"
        if (-not $cidrs) { $cidrs = $propuesta }
    } else {
        Aviso 'No se detectó ninguna red privada alcanzable desde esta máquina.'
        $cidrs = Read-Host '   Rangos que podrá barrer, separados por coma (ej. 10.10.10.0/24)'
    }

    if ($cidrs) {
        $argumentos += @('--scannable', $cidrs)
    } else {
        Aviso 'Sin rangos, este agente sondeará el parque pero rechazará todos los barridos.'
    }
}

# ── 5. Enrolar ───────────────────────────────────────────────────────────────

Info "Enrolando contra $ApiUrl."

$env:PYTHONPATH = $Prefix
& $venvPython -m ispgestor_agent --config $config enroll `
    --url $ApiUrl --token $EnrollmentToken --role $Role @argumentos

if ($LASTEXITCODE -ne 0) {
    Morir 'Falló el enrolamiento. Si el enlace tiene más de 30 minutos, genera otro desde el panel.'
}

# ── 6. Comprobar y arrancar ──────────────────────────────────────────────────

Info 'Comprobando el entorno.'
& $venvPython -m ispgestor_agent --config $config selftest
if ($LASTEXITCODE -ne 0) { Morir 'El agente quedó instalado pero el selftest falló. Revisa lo anterior.' }

Info 'Registrando el servicio.'

# Windows no puede correr un script de Python como servicio: `sc.exe` espera un
# binario que dialogue con el gestor de servicios. La alternativa sin depender de
# NSSM —que exigiría otra descarga, y el punto de este instalador es no
# necesitarla— es una tarea programada al arranque como SYSTEM.
#
# Con RestartCount y RestartInterval se consigue el equivalente de Restart=always
# de systemd: un agente que muere deja de detectar equipos en silencio, y eso es
# lo más difícil de notar porque nada se rompe, simplemente no pasa nada nunca.
try {
    $accion = New-ScheduledTaskAction -Execute $venvPython `
        -Argument "-m ispgestor_agent --config `"$config`" run" `
        -WorkingDirectory $Prefix

    $disparador = New-ScheduledTaskTrigger -AtStartup

    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' `
        -LogonType ServiceAccount -RunLevel Highest

    $ajustes = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
        -ExecutionTimeLimit ([TimeSpan]::Zero)

    Register-ScheduledTask -TaskName $tarea -Action $accion -Trigger $disparador `
        -Principal $principal -Settings $ajustes -Force | Out-Null

    Start-ScheduledTask -TaskName $tarea
} catch {
    Morir "No se pudo registrar el servicio: $($_.Exception.Message)"
}

Start-Sleep -Seconds 5

$estado = (Get-ScheduledTask -TaskName $tarea -ErrorAction SilentlyContinue).State

Remove-Item -Recurse -Force $SrcDir -ErrorAction SilentlyContinue

if ($estado -eq 'Running') {
    Write-Host ''
    Verde "OK. El agente '$AgentName' está instalado, enrolado y corriendo."
    Write-Host '   Ya debería aparecer en línea en el panel, en Red -> Agentes.'
    Write-Host ''
    Write-Host "   Ver el estado:  Get-ScheduledTask -TaskName $tarea"
    Write-Host "   Detenerlo:      Stop-ScheduledTask -TaskName $tarea"
    Write-Host "   Desinstalar:    & '$Prefix\uninstall.ps1'"
} else {
    Write-Host ''
    Rojo "El servicio no quedó corriendo (estado: $estado). Mira qué pasó ejecutándolo a mano:"
    Write-Host "   `$env:PYTHONPATH='$Prefix'; & '$venvPython' -m ispgestor_agent --config '$config' run"
    exit 1
}
