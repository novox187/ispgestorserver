#!/usr/bin/env bash
#
# Instalador desatendido del agente de ISP Gestor.
#
# Este fichero NO se edita a mano: lo genera el panel por cada agente, con su
# token de enrolamiento y el código del propio agente ya incrustados. Por eso
# basta con ejecutarlo; no hay nada que teclear.
#
#   curl -fsSL "<enlace del panel>" | sudo bash
#
set -euo pipefail

API_URL="{{API_URL}}"
ENROLLMENT_TOKEN="{{TOKEN}}"
ROLE="{{ROLE}}"
AGENT_NAME="{{AGENT_NAME}}"

PREFIX=/opt/ispgestor-agent
CONFIG_DIR=/etc/ispgestor-agent
SRC_DIR=/tmp/ispgestor-agent-src.$$
BIN=/usr/local/bin/ispgestor-agent

# Instancia y fichero de configuración que usará ESTE agente. Se deciden más
# abajo, una vez se sabe si la máquina ya tiene otro agente con un rol distinto.
#
# «agent» es la instalación de siempre. El nombre lo traduce a unidad de systemd
# o a demonio de launchd `ispgestor-agent-service`, que es quien sabe en cuál de
# los dos está.
INSTANCIA=agent
CONFIG="${CONFIG_DIR}/agent.conf"

rojo()  { printf '\033[0;31m%s\033[0m\n' "$*" >&2; }
verde() { printf '\033[0;32m%s\033[0m\n' "$*"; }
info()  { printf '\033[0;36m▸\033[0m %s\n' "$*"; }
aviso() { printf '\033[0;33m!\033[0m %s\n' "$*"; }

morir() { rojo "✗ $*"; exit 1; }

limpiar() { rm -rf "$SRC_DIR"; }
trap limpiar EXIT

# El script se suele ejecutar por tubería (`curl | bash`), y entonces la entrada
# estándar es la tubería, no el teclado. Cualquier pregunta tiene que leer del
# terminal de control o se quedaría colgada leyendo el propio script.
preguntar() {
    local mensaje="$1" respuesta=""
    if [[ -r /dev/tty ]]; then
        read -r -p "$mensaje" respuesta < /dev/tty || true
    fi
    printf '%s' "$respuesta"
}

echo
verde "── Instalador del agente de ISP Gestor ──"
echo "   Agente: ${AGENT_NAME}"
echo "   Rol:    ${ROLE}"
echo "   API:    ${API_URL}"
echo

# ── 1. Requisitos ────────────────────────────────────────────────────────────

[[ $EUID -eq 0 ]] || morir "Hay que ejecutarlo como root (usa sudo)."

# macOS comparte con Linux las rutas, los permisos y el entorno virtual, pero
# no systemd. Lo que cambia se resuelve en `ispgestor-agent-service`.
if [[ "$(uname -s)" == "Darwin" ]]; then
    SISTEMA=macos
else
    SISTEMA=linux
fi

command -v python3 >/dev/null || morir "Falta python3. Instálalo y vuelve a ejecutar."

# `import venv` puede funcionar y aun así fallar la creación del entorno: en
# Debian y Ubuntu `ensurepip` viaja en un paquete aparte.
if ! python3 -c 'import ensurepip' >/dev/null 2>&1; then
    info "Falta el soporte de entornos virtuales; instalándolo."
    if command -v apt-get >/dev/null; then
        version="$(python3 -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')"
        DEBIAN_FRONTEND=noninteractive apt-get update -qq || true
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "python${version}-venv" \
            || morir "No se pudo instalar python${version}-venv. Instálalo a mano y reintenta."
    elif [[ "$SISTEMA" == "macos" ]]; then
        morir "El python3 de este Mac no puede crear entornos virtuales. Instala las herramientas de desarrollo con 'xcode-select --install', o Python desde python.org, y reintenta."
    else
        morir "Falta ensurepip y no hay apt-get. Instala el paquete de venv de tu distribución."
    fi
fi

if [[ "$ROLE" == "vpn_host" && "$SISTEMA" == "macos" ]]; then
    morir "El rol vpn_host administra el WireGuard del hosting y solo tiene sentido en el servidor Linux."
fi

if [[ "$ROLE" == "vpn_host" ]]; then
    command -v wg >/dev/null || morir "Falta 'wg'. Instala wireguard-tools y vuelve a ejecutar."
fi

# ── 2. Desplegar el código incrustado ────────────────────────────────────────

info "Extrayendo el agente."
mkdir -p "$SRC_DIR"

# El paquete viaja en base64 dentro de este script para que la instalación no
# dependa de una segunda descarga. Se descomprime con Python, que ya es un
# requisito del agente, y así no hace falta tar ni unzip en esta máquina.
base64 -d <<'PAYLOAD_B64' > "$SRC_DIR/agente.zip"
{{PAYLOAD}}
PAYLOAD_B64

python3 -c "import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" \
    "$SRC_DIR/agente.zip" "$SRC_DIR" || morir "El paquete incrustado está corrupto."

[[ -f "$SRC_DIR/install.sh" ]] || morir "El paquete no contiene install.sh."

# ── 3. Instalar ──────────────────────────────────────────────────────────────

# ¿Hay ya un agente en esta máquina, y con qué rol? De la respuesta depende si
# este se instala encima o al lado.
#
# El caso que obliga a distinguir es el hosting: es a la vez `vpn_host` —
# administra los peers de WireGuard— y el mejor sitio para el `monitor`, porque
# alcanza todo el parque por el propio túnel. Con una sola unidad, instalar el
# segundo pisaba las credenciales del primero y lo dejaba fuera en silencio.
rol_previo=""
if [[ -f "${CONFIG_DIR}/agent.conf" ]]; then
    rol_previo="$(python3 -c 'import json;print(json.load(open("'"${CONFIG_DIR}"'/agent.conf")).get("role",""))' 2>/dev/null || true)"
fi

if [[ -n "$rol_previo" && "$rol_previo" != "$ROLE" ]]; then
    INSTANCIA="$ROLE"
    CONFIG="${CONFIG_DIR}/${ROLE}.conf"
    info "Esta máquina ya tiene un agente '${rol_previo}'. Este se instala aparte, como instancia '${ROLE}'."
fi

# Si el agente que vamos a reemplazar estaba corriendo, se para antes de tocar
# sus ficheros. Solo ese: los de otros roles siguen trabajando.
if [[ -x /usr/local/bin/ispgestor-agent-service ]] \
    && ispgestor-agent-service is-active "$INSTANCIA" 2>/dev/null; then
    info "Deteniendo el agente que ya estaba instalado."
    ispgestor-agent-service stop "$INSTANCIA"
fi

info "Instalando en ${PREFIX}."
bash "$SRC_DIR/install.sh" >/dev/null || morir "Falló la instalación."
[[ -x "$BIN" ]] || morir "El instalador no dejó el ejecutable en ${BIN}."

# ── 4. Parámetros propios del rol ────────────────────────────────────────────

ARGS=()

if [[ "$ROLE" == "vpn_host" ]]; then
    # La interfaz del túnel: si hay solo una, no hay nada que preguntar.
    mapfile -t wg_ifaces < <(wg show interfaces 2>/dev/null | tr ' ' '\n' | grep -v '^$' || true)

    if [[ ${#wg_ifaces[@]} -eq 1 ]]; then
        wg_iface="${wg_ifaces[0]}"
        info "Interfaz WireGuard detectada: ${wg_iface}"
    elif [[ ${#wg_ifaces[@]} -eq 0 ]]; then
        morir "No hay ninguna interfaz WireGuard levantada. El túnel del servidor debe existir antes de instalar este agente."
    else
        echo "   Hay varias interfaces WireGuard: ${wg_ifaces[*]}"
        wg_iface="$(preguntar "   ¿Cuál usa ISP Gestor? [${wg_ifaces[0]}]: ")"
        wg_iface="${wg_iface:-${wg_ifaces[0]}}"
    fi

    # El endpoint tiene que ser la IP pública REAL. Un nombre detrás de un proxy
    # tipo Cloudflare no vale: WireGuard es UDP y esos proxies no lo reenvían.
    ip_publica="$(curl -fsS -4 --max-time 10 https://api.ipify.org 2>/dev/null || true)"
    [[ -n "$ip_publica" ]] || ip_publica="$(curl -fsS -4 --max-time 10 https://ifconfig.me 2>/dev/null || true)"

    if [[ -n "$ip_publica" ]]; then
        info "IP pública detectada: ${ip_publica}"
        respuesta="$(preguntar "   ¿A qué host marcarán los routers? [${ip_publica}]: ")"
        endpoint="${respuesta:-$ip_publica}"
    else
        endpoint="$(preguntar "   No se pudo detectar la IP pública. Indícala: ")"
        [[ -n "$endpoint" ]] || morir "Hace falta un endpoint al que marquen los routers."
    fi

    ARGS+=(--wg-interface "$wg_iface" --endpoint-host "$endpoint")

elif [[ "$ROLE" == "provisioner" ]]; then
    # La NIC de aprovisionamiento es el límite físico de seguridad del sistema:
    # solo se dan de alta equipos vistos por ella. Por eso se elige con cuidado
    # y se descarta la que lleva la salida a internet.
    # Ni la lista de NIC ni la ruta por defecto se consultan igual en los dos
    # sistemas: macOS no tiene /sys ni el comando `ip`.
    if [[ "$SISTEMA" == "macos" ]]; then
        salida="$(route -n get default 2>/dev/null | awk '/interface:/{print $2}' | head -1)"
        listar_nics() { ifconfig -l | tr ' ' '\n'; }
    else
        salida="$(ip route show default 2>/dev/null | awk '{print $5}' | head -1)"
        listar_nics() { ls /sys/class/net; }
    fi

    # Se descartan las virtuales de los dos sistemas: en macOS las `en*` son
    # las de verdad y todo lo demás (bridge, utun, awdl, llw…) es interno.
    mapfile -t candidatas < <(
        listar_nics \
        | grep -vE '^(lo|docker|veth|br-|wg|tun|tap|virbr|bridge|utun|awdl|llw|gif|stf|ap[0-9]|anpi)' \
        | grep -v "^${salida}$" || true
    )

    if [[ ${#candidatas[@]} -eq 0 ]]; then
        aviso "No se encontró ninguna NIC libre (la única salida es '${salida}')."
        elegida="$(preguntar "   Escribe el nombre de la NIC de aprovisionamiento: ")"
    elif [[ ${#candidatas[@]} -eq 1 ]]; then
        elegida="${candidatas[0]}"
        info "NIC de aprovisionamiento detectada: ${elegida}"
    else
        echo "   NIC disponibles (excluyendo '${salida}', que da la salida a internet):"
        for n in "${candidatas[@]}"; do
            if [[ "$SISTEMA" == "macos" ]]; then
                # `status: active` es lo que imprime ifconfig cuando hay cable,
                # y esa palabra no la traduce el sistema.
                estado="$(ifconfig "$n" 2>/dev/null | awk '/status:/{print $2}' | head -1)"
                estado="${estado:-?}"
                [[ "$estado" == "active" ]] && cable="con cable" || cable="sin cable"
            else
                estado="$(cat "/sys/class/net/$n/operstate" 2>/dev/null || echo '?')"
                cable="$(cat "/sys/class/net/$n/carrier" 2>/dev/null || echo '0')"
                [[ "$cable" == "1" ]] && cable="con cable" || cable="sin cable"
            fi
            printf '     · %-12s %s, %s\n' "$n" "$estado" "$cable"
        done
        elegida="$(preguntar "   ¿En cuál se enchufan los routers? [${candidatas[0]}]: ")"
        elegida="${elegida:-${candidatas[0]}}"
    fi

    [[ -n "$elegida" ]] || morir "Sin NIC de aprovisionamiento el agente no detectaría ningún equipo."
    ARGS+=(--interfaces "$elegida")

elif [[ "$ROLE" == "monitor" ]]; then
    # Los rangos que este agente aceptará barrer. Se guardan AQUÍ, en la
    # máquina, y no en el panel: el servidor puede pedir un barrido, pero no
    # puede ampliar esta lista. Por eso hay que preguntarlos en vez de
    # heredarlos del alta, y por eso una lista vacía significa «ninguno».
    #
    # Se proponen las redes privadas que esta máquina ya sabe alcanzar, que en
    # el hosting son justo las del otro lado del túnel. Se excluyen los puentes
    # de Docker: son redes de contenedores, no parque que monitorizar.
    mapfile -t rangos < <(
        ip -4 route show 2>/dev/null \
        | awk '$1 ~ /\/[0-9]+$/ && $2 == "dev" && $3 !~ /^(docker|veth|br-)/ {print $1}' \
        | grep -E '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' \
        | sort -u || true
    )

    if [[ ${#rangos[@]} -gt 0 ]]; then
        propuesta="$(IFS=,; echo "${rangos[*]}")"
        echo "   Redes privadas que esta máquina alcanza:"
        for r in "${rangos[@]}"; do
            printf '     · %s\n' "$r"
        done
        respuesta="$(preguntar "   ¿Cuáles podrá barrer? (coma) [${propuesta}]: ")"
        cidrs="${respuesta:-$propuesta}"
    else
        aviso "No se detectó ninguna red privada alcanzable desde esta máquina."
        cidrs="$(preguntar "   Rangos que podrá barrer, separados por coma (ej. 10.10.10.0/24): ")"
    fi

    if [[ -n "$cidrs" ]]; then
        ARGS+=(--scannable "$cidrs")
    else
        # No es motivo para abortar: el sondeo de los equipos ya dados de alta
        # funciona igual. Lo que no funcionará es el descubrimiento.
        aviso "Sin rangos, este agente sondeará el parque pero rechazará todos los barridos."
    fi
fi

# ── 5. Enrolar ───────────────────────────────────────────────────────────────

info "Enrolando contra ${API_URL}."
"$BIN" --config "$CONFIG" enroll --url "$API_URL" --token "$ENROLLMENT_TOKEN" --role "$ROLE" "${ARGS[@]}" \
    || morir "Falló el enrolamiento. Si el enlace tiene más de 30 minutos, genera otro desde el panel."

# ── 6. Comprobar y arrancar ──────────────────────────────────────────────────

info "Comprobando el entorno."
"$BIN" --config "$CONFIG" selftest || morir "El agente quedó instalado pero el selftest falló. Revisa lo anterior."

info "Arrancando el servicio."
ispgestor-agent-service enable "$INSTANCIA" || morir "No se pudo arrancar el servicio."

sleep 3
if ispgestor-agent-service is-active "$INSTANCIA"; then
    echo
    verde "✓ Listo. El agente '${AGENT_NAME}' está instalado, enrolado y corriendo."
    echo "   Ya debería aparecer en línea en el panel, en Red → Agentes."
    echo
    echo "   Ver el registro:  $(ispgestor-agent-service log-hint "$INSTANCIA")"
    # Se dice al terminar y no solo en la documentación: quien instala esto para
    # probarlo va a querer quitarlo, y son seis ficheros repartidos por /opt,
    # /etc y /usr/local que no tiene por qué adivinar.
    echo "   Desinstalar:      sudo ispgestor-agent-uninstall"
else
    echo
    rojo "El servicio no quedó activo. Mira qué pasó con:"
    echo "   $(ispgestor-agent-service log-hint "$INSTANCIA")"
    exit 1
fi
