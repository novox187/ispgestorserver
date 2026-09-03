#!/usr/bin/env bash
#
# Desinstalador del agente de ISP Gestor. Linux y macOS.
#
#   sudo ispgestor-agent-uninstall
#
# Existe porque instalar limpiamente sin poder desinstalar limpiamente deja al
# operador teniendo que adivinar qué ficheros tocó el instalador —hay seis
# repartidos por /opt, /etc y /usr/local, más el enlace de arranque— y lo normal
# es que se deje alguno, con el secreto del agente dentro.
#
# Lo que NO hace: borrar el agente en el panel. Eso exige la contraseña del
# operador y se hace desde ahí; aquí solo se avisa.
set -euo pipefail

PREFIX=/opt/ispgestor-agent
CONFIG_DIR=/etc/ispgestor-agent
LAUNCH_DAEMONS=/Library/LaunchDaemons
ETIQUETA_BASE=uk.ironlink.ispgestor-agent

rojo()  { printf '\033[0;31m%s\033[0m\n' "$*" >&2; }
verde() { printf '\033[0;32m%s\033[0m\n' "$*"; }
info()  { printf '\033[0;36m▸\033[0m %s\n' "$*"; }
aviso() { printf '\033[0;33m!\033[0m %s\n' "$*"; }

[[ $EUID -eq 0 ]] || { rojo "Hay que ejecutarlo como root (usa sudo)."; exit 1; }

if [[ "$(uname -s)" == "Darwin" ]]; then
    SISTEMA=macos
else
    SISTEMA=linux
fi

# Las instancias instaladas se deducen de los ficheros de configuración: hay uno
# por agente, y su nombre ES el nombre de la instancia.
instancias=()
if [[ -d "$CONFIG_DIR" ]]; then
    for conf in "$CONFIG_DIR"/*.conf; do
        [[ -e "$conf" ]] || continue
        instancias+=("$(basename "$conf" .conf)")
    done
fi

echo
echo "Se va a desinstalar el agente de ISP Gestor de esta máquina."
if [[ ${#instancias[@]} -gt 0 ]]; then
    echo "Agentes instalados: ${instancias[*]}"
fi
echo
echo "Se borrará:"
echo "  · ${PREFIX} (código y entorno virtual)"
echo "  · ${CONFIG_DIR} (configuración y credenciales)"
echo "  · los atajos de /usr/local/bin"
if [[ "$SISTEMA" == "macos" ]]; then
    echo "  · los demonios de launchd de ${LAUNCH_DAEMONS}"
else
    echo "  · las unidades de systemd"
fi
echo

if [[ "${1:-}" != "--yes" ]]; then
    # Se lee de /dev/tty y no de la entrada estándar por si se llega aquí por
    # tubería, igual que en el instalador.
    respuesta=""
    if [[ -r /dev/tty ]]; then
        read -r -p "¿Seguir? Escribe «si» para continuar: " respuesta < /dev/tty || true
    fi

    if [[ "$respuesta" != "si" ]]; then
        echo "Cancelado. No se ha tocado nada."
        exit 0
    fi
fi

# ── Parar los servicios ──────────────────────────────────────────────────────

# Se recorren también las unidades que existan sin fichero de configuración: una
# instalación a medias, o una configuración ya borrada a mano, dejarían el
# servicio corriendo y reiniciándose para siempre.
for instancia in "${instancias[@]}" agent; do
    if [[ "$instancia" == "agent" ]]; then
        unidad=ispgestor-agent
    else
        unidad="ispgestor-agent@${instancia}"
    fi

    if [[ "$SISTEMA" == "macos" ]]; then
        etiqueta="${ETIQUETA_BASE}.${instancia}"
        if launchctl print "system/${etiqueta}" >/dev/null 2>&1; then
            info "Deteniendo ${etiqueta}."
            launchctl bootout "system/${etiqueta}" 2>/dev/null || true
        fi
        rm -f "${LAUNCH_DAEMONS}/${etiqueta}.plist"
    else
        if systemctl list-unit-files "${unidad}.service" >/dev/null 2>&1 \
            && systemctl is-enabled "${unidad}" >/dev/null 2>&1; then
            info "Deteniendo ${unidad}."
        fi
        systemctl disable --now "${unidad}" >/dev/null 2>&1 || true
        # Sin esto quedan los reinicios fallidos acumulados y `systemctl status`
        # sigue mostrando el servicio en rojo después de haberlo desinstalado.
        systemctl reset-failed "${unidad}" >/dev/null 2>&1 || true
    fi
done

if [[ "$SISTEMA" == "linux" ]]; then
    rm -f /etc/systemd/system/ispgestor-agent.service \
          /etc/systemd/system/ispgestor-agent@.service
    systemctl daemon-reload
fi

# ── Borrar los ficheros ──────────────────────────────────────────────────────

info "Borrando ${PREFIX} y ${CONFIG_DIR}."
rm -rf "$PREFIX" "$CONFIG_DIR"
rm -f /usr/local/bin/ispgestor-agent \
      /usr/local/bin/ispgestor-agent-service \
      /usr/local/bin/ispgestor-agent-uninstall

if [[ "$SISTEMA" == "macos" ]]; then
    rm -f /var/log/ispgestor-agent.*.log
fi

echo
verde "✓ Desinstalado. No queda nada del agente en esta máquina."
echo
aviso "Falta un paso, y no se puede hacer desde aquí:"
echo "   El agente sigue registrado en el panel, en Red → Agentes."
echo "   Bórralo o revócalo allí; si no, quedará como un agente que dejó de"
echo "   responder, y sus credenciales seguirían siendo válidas."
echo
