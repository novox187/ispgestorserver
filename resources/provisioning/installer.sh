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
SRC_DIR=/tmp/ispgestor-agent-src.$$
BIN=/usr/local/bin/ispgestor-agent

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
    else
        morir "Falta ensurepip y no hay apt-get. Instala el paquete de venv de tu distribución."
    fi
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

# Si ya había un agente corriendo, se para antes de reemplazar sus ficheros.
if systemctl is-active --quiet ispgestor-agent 2>/dev/null; then
    info "Deteniendo el agente que ya estaba instalado."
    systemctl stop ispgestor-agent
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
    salida="$(ip route show default 2>/dev/null | awk '{print $5}' | head -1)"
    mapfile -t candidatas < <(
        ls /sys/class/net \
        | grep -vE '^(lo|docker|veth|br-|wg|tun|tap|virbr)' \
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
            estado="$(cat "/sys/class/net/$n/operstate" 2>/dev/null || echo '?')"
            cable="$(cat "/sys/class/net/$n/carrier" 2>/dev/null || echo '0')"
            [[ "$cable" == "1" ]] && cable="con cable" || cable="sin cable"
            printf '     · %-12s %s, %s\n' "$n" "$estado" "$cable"
        done
        elegida="$(preguntar "   ¿En cuál se enchufan los routers? [${candidatas[0]}]: ")"
        elegida="${elegida:-${candidatas[0]}}"
    fi

    [[ -n "$elegida" ]] || morir "Sin NIC de aprovisionamiento el agente no detectaría ningún equipo."
    ARGS+=(--interfaces "$elegida")
fi

# ── 5. Enrolar ───────────────────────────────────────────────────────────────

info "Enrolando contra ${API_URL}."
"$BIN" enroll --url "$API_URL" --token "$ENROLLMENT_TOKEN" --role "$ROLE" "${ARGS[@]}" \
    || morir "Falló el enrolamiento. Si el enlace tiene más de 30 minutos, genera otro desde el panel."

# ── 6. Comprobar y arrancar ──────────────────────────────────────────────────

info "Comprobando el entorno."
"$BIN" selftest || morir "El agente quedó instalado pero el selftest falló. Revisa lo anterior."

info "Arrancando el servicio."
systemctl enable --now ispgestor-agent >/dev/null 2>&1 || morir "No se pudo arrancar el servicio."

sleep 3
if systemctl is-active --quiet ispgestor-agent; then
    echo
    verde "✓ Listo. El agente '${AGENT_NAME}' está instalado, enrolado y corriendo."
    echo "   Ya debería aparecer en línea en el panel, en Red → Agentes."
    echo
    echo "   Ver el registro:  journalctl -u ispgestor-agent -f"
else
    echo
    rojo "El servicio no quedó activo. Mira qué pasó con:"
    echo "   journalctl -u ispgestor-agent -n 50 --no-pager"
    exit 1
fi
