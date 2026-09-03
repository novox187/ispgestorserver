#!/usr/bin/env bash
#
# Instalador del agente de aprovisionamiento de ISP Gestor.
#
# Deja el agente en /opt/ispgestor-agent con su propio entorno virtual, para no
# mezclar sus dependencias con las del sistema anfitrión — que en el caso del
# hosting es la misma máquina que corre todo lo demás.
#
#   sudo ./install.sh
#
# Después, enrolar con el token que genera el panel:
#
#   /opt/ispgestor-agent/venv/bin/python -m ispgestor_agent enroll \
#       --url https://api.ironlink.uk --token <TOKEN> \
#       --role provisioner --interfaces eth1
#
set -euo pipefail

PREFIX=/opt/ispgestor-agent
CONFIG_DIR=/etc/ispgestor-agent
SERVICE=/etc/systemd/system/ispgestor-agent.service
SERVICE_TEMPLATE=/etc/systemd/system/ispgestor-agent@.service
LAUNCH_DAEMONS=/Library/LaunchDaemons
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# macOS es Unix y comparte casi todo —rutas, permisos, el entorno virtual—,
# pero no tiene systemd: los demonios los gobierna launchd, con un plist por
# servicio en lugar de unidades con plantilla.
if [[ "$(uname -s)" == "Darwin" ]]; then
    SISTEMA=macos
else
    SISTEMA=linux
fi

if [[ $EUID -ne 0 ]]; then
    echo "Este instalador necesita privilegios de root." >&2
    exit 1
fi

command -v python3 >/dev/null || { echo "Falta python3." >&2; exit 1; }

# `import venv` puede funcionar y aun así fallar la creación del entorno: en
# Debian y Ubuntu `ensurepip` viaja en un paquete aparte, y sin él `python3 -m
# venv` aborta con un mensaje que no dice qué instalar.
if ! python3 -c 'import ensurepip' >/dev/null 2>&1; then
    if command -v apt-get >/dev/null; then
        PYVER="$(python3 -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')"
        echo "==> Instalando python${PYVER}-venv (falta ensurepip)"
        DEBIAN_FRONTEND=noninteractive apt-get update -qq || true
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "python${PYVER}-venv" \
            || { echo "No se pudo instalar python${PYVER}-venv." >&2; exit 1; }
    elif [[ "$SISTEMA" == "macos" ]]; then
        # El python3 de las Herramientas de Línea de Comandos de Apple sí trae
        # ensurepip. Si falta, lo habitual es que python3 sea un enlace roto o
        # una instalación a medias, y eso no lo puede arreglar el instalador.
        echo "El python3 de este Mac no puede crear entornos virtuales." >&2
        echo "Instala las herramientas de desarrollo con: xcode-select --install" >&2
        echo "o Python desde python.org, y vuelve a ejecutar esto." >&2
        exit 1
    else
        echo "Falta ensurepip y no hay apt-get; instala el paquete venv de tu distribución." >&2
        exit 1
    fi
fi

echo "==> Instalando en ${PREFIX}"
mkdir -p "${PREFIX}"
# Se borra antes de copiar: `cp -r` fusiona sobre lo que ya hay, así que al
# reinstalar una versión con menos ficheros los sobrantes de la anterior
# seguirían ahí y se importarían.
rm -rf "${PREFIX}/ispgestor_agent"
cp -r "${SOURCE_DIR}/ispgestor_agent" "${PREFIX}/"
cp "${SOURCE_DIR}/requirements.txt" "${PREFIX}/"

echo "==> Preparando el entorno virtual"
python3 -m venv "${PREFIX}/venv"
"${PREFIX}/venv/bin/pip" install --quiet --upgrade pip
"${PREFIX}/venv/bin/pip" install --quiet -r "${PREFIX}/requirements.txt"

echo "==> Creando ${CONFIG_DIR}"
# 0700: dentro vivirá el secreto HMAC con el que el agente firma sus peticiones.
mkdir -p "${CONFIG_DIR}"
chmod 700 "${CONFIG_DIR}"

if [[ "$SISTEMA" == "macos" ]]; then
    echo "==> Preparando el demonio de launchd"
    # launchd no tiene plantillas: se genera un plist por instancia al
    # arrancarla. Aquí solo se deja el modelo del que saldrán.
    mkdir -p "${PREFIX}/plantillas"
    cp "${SOURCE_DIR}/uk.ironlink.ispgestor-agent.plist" "${PREFIX}/plantillas/"
else
    echo "==> Instalando las unidades de systemd"
    cp "${SOURCE_DIR}/ispgestor-agent.service" "${SERVICE}"
    # La plantilla permite un agente por rol en la misma máquina
    # (`ispgestor-agent@monitor`, con /etc/ispgestor-agent/monitor.conf). Se
    # instala siempre aunque no se use: no arranca nada por sí sola y evita
    # tener que volver a copiar ficheros el día que a un host le haga falta un
    # segundo rol.
    cp "${SOURCE_DIR}/ispgestor-agent@.service" "${SERVICE_TEMPLATE}"
    systemctl daemon-reload
fi

echo "==> Atajo en /usr/local/bin/ispgestor-agent"
# PYTHONPATH explícito y no un `cd`: sin él, `python -m` solo encuentra el
# módulo cuando el directorio de trabajo es justo ${PREFIX}. El servicio no lo
# notaba porque su unidad fija WorkingDirectory, pero cualquier uso del CLI
# desde otro sitio —enrolar, selftest— moría con "No module named
# ispgestor_agent", y el instalador desatendido corre desde donde sea.
cat > /usr/local/bin/ispgestor-agent <<EOF
#!/usr/bin/env bash
exec env PYTHONPATH="${PREFIX}" ${PREFIX}/venv/bin/python -m ispgestor_agent "\$@"
EOF
chmod 755 /usr/local/bin/ispgestor-agent

# Capa que abstrae systemd y launchd, para que el instalador desatendido sea el
# mismo script en Linux y en macOS.
install -m 755 "${SOURCE_DIR}/ispgestor-agent-service" /usr/local/bin/ispgestor-agent-service

# El desinstalador se deja puesto desde el principio: son seis ficheros
# repartidos por /opt, /etc y /usr/local más el enlace de arranque, y quien
# quiera quitarlo no tiene por qué saber cuáles son.
install -m 755 "${SOURCE_DIR}/uninstall.sh" /usr/local/bin/ispgestor-agent-uninstall

cat <<'EOF'

Instalación completada.

Siguiente paso — enrolar el agente con el token que genera el panel
(MikroTik → Agentes → Registrar agente):

  # En la oficina, donde se enchufan los routers:
  ispgestor-agent enroll --url https://api.ironlink.uk --token <TOKEN> \
      --role provisioner --interfaces eth1

  # En el servidor del hosting, junto al WireGuard:
  ispgestor-agent enroll --url https://api.ironlink.uk --token <TOKEN> \
      --role vpn_host --wg-interface wg0 --endpoint-host vpn.ironlink.uk

  # Sondeo del parque y barridos de descubrimiento. Los rangos de --scannable
  # son el límite de lo que este agente aceptará barrer, y se comprueban aquí,
  # en la máquina, no en el servidor.
  ispgestor-agent --config /etc/ispgestor-agent/monitor.conf \
      enroll --url https://api.ironlink.uk --token <TOKEN> \
      --role monitor --scannable 10.10.10.0/24

Comprobar el entorno antes de arrancar:

  ispgestor-agent selftest

Arrancar:

  systemctl enable --now ispgestor-agent
  journalctl -u ispgestor-agent -f

Un segundo rol en la misma máquina usa la unidad plantilla, que toma la
configuración de /etc/ispgestor-agent/<instancia>.conf:

  systemctl enable --now ispgestor-agent@monitor
  journalctl -u ispgestor-agent@monitor -f

EOF
