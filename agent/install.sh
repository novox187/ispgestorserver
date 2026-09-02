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
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

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
    else
        echo "Falta ensurepip y no hay apt-get; instala el paquete venv de tu distribución." >&2
        exit 1
    fi
fi

echo "==> Instalando en ${PREFIX}"
mkdir -p "${PREFIX}"
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

echo "==> Instalando la unidad de systemd"
cp "${SOURCE_DIR}/ispgestor-agent.service" "${SERVICE}"
systemctl daemon-reload

echo "==> Atajo en /usr/local/bin/ispgestor-agent"
cat > /usr/local/bin/ispgestor-agent <<EOF
#!/usr/bin/env bash
exec ${PREFIX}/venv/bin/python -m ispgestor_agent "\$@"
EOF
chmod 755 /usr/local/bin/ispgestor-agent

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

Comprobar el entorno antes de arrancar:

  ispgestor-agent selftest

Arrancar:

  systemctl enable --now ispgestor-agent
  journalctl -u ispgestor-agent -f

EOF
