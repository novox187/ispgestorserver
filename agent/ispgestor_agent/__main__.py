"""Punto de entrada del agente.

    ispgestor-agent enroll --url https://api.ironlink.uk --token <TOKEN>
    ispgestor-agent run
    ispgestor-agent selftest
    ispgestor-agent status
"""

from __future__ import annotations

import argparse
import logging
import platform
import socket
import sys
from pathlib import Path

from . import __version__
from .client import ApiClient, ApiError, TransportError
from .config import AgentConfig, ConfigError, DEFAULT_PATH, check_permissions, load, save

log = logging.getLogger("ispgestor")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="ispgestor-agent",
        description="Agente de aprovisionamiento automático de dispositivos de ISP Gestor.",
    )
    parser.add_argument("--config", type=Path, default=DEFAULT_PATH, help="Ruta del fichero de configuración.")
    parser.add_argument("--verbose", "-v", action="store_true", help="Traza detallada.")
    parser.add_argument("--version", action="version", version=f"ispgestor-agent {__version__}")

    commands = parser.add_subparsers(dest="command", required=True)

    enroll = commands.add_parser("enroll", help="Canjea el token de enrolamiento por credenciales.")
    enroll.add_argument("--url", required=True, help="URL base de la API (ej: https://api.ironlink.uk)")
    enroll.add_argument("--token", required=True, help="Token de un solo uso generado en el panel.")
    enroll.add_argument("--role", choices=["provisioner", "vpn_host"], help="Rol; se toma del servidor si se omite.")
    enroll.add_argument("--interfaces", help="NIC de aprovisionamiento separadas por coma (rol provisioner).")
    enroll.add_argument("--probe", default="192.168.88.1", help="IP de fábrica a sondear (rol provisioner).")
    enroll.add_argument("--wg-interface", default="wg0", help="Interfaz WireGuard del host (rol vpn_host).")
    enroll.add_argument("--wg-config", default="/etc/wireguard/wg0.conf", help="Fichero de configuración (rol vpn_host).")
    enroll.add_argument("--endpoint-host", help="Host público al que marcarán los routers (rol vpn_host).")
    enroll.add_argument("--endpoint-port", type=int, default=51820, help="Puerto UDP del servidor (rol vpn_host).")
    enroll.add_argument("--subnet", default="10.77.0.0/24", help="Subred del túnel (rol vpn_host).")
    enroll.add_argument("--insecure", action="store_true", help="No verificar el certificado TLS (solo pruebas).")

    commands.add_parser("run", help="Arranca el bucle del agente.")
    commands.add_parser("status", help="Muestra la configuración vigente y comprueba la conexión.")
    commands.add_parser("selftest", help="Comprueba el entorno sin tocar ningún equipo.")

    args = parser.parse_args(argv)

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)-7s %(name)s: %(message)s",
    )

    try:
        if args.command == "enroll":
            return _enroll(args)
        if args.command == "run":
            return _run(args)
        if args.command == "status":
            return _status(args)
        if args.command == "selftest":
            return _selftest(args)
    except ConfigError as exc:
        log.error("%s", exc)
        return 2

    return 1


# ── Comandos ────────────────────────────────────────────────────────────────


def _enroll(args) -> int:
    config = AgentConfig(
        base_url=args.url.rstrip("/"),
        role=args.role or "",
        provisioning_interfaces=[i.strip() for i in (args.interfaces or "").split(",") if i.strip()],
        probe_addresses=[args.probe] if args.probe else [],
        wg_interface=args.wg_interface,
        wg_config_path=args.wg_config,
        endpoint_host=args.endpoint_host or "",
        endpoint_port=args.endpoint_port,
        subnet=args.subnet,
        verify_tls=not args.insecure,
    )

    # El rol vpn_host debe publicar la clave pública de su interfaz. Se lee del
    # propio sistema en vez de pedírsela al operador: teclearla mal produciría
    # un túnel que nunca completa el handshake y cuesta mucho diagnosticar.
    if args.role == "vpn_host":
        from .wireguard import WireGuardHost

        host = WireGuardHost(config.wg_interface, config.wg_config_path)
        public_key = host.server_public_key()

        if not public_key:
            log.error(
                "No se pudo leer la clave pública de '%s'. Levanta el túnel del "
                "servidor antes de enrolar el agente.",
                config.wg_interface,
            )
            return 2

        config.server_public_key = public_key
        log.info("Clave pública del servidor leída de %s.", config.wg_interface)

        if not config.endpoint_host:
            log.error("Falta --endpoint-host: es la dirección a la que marcarán los routers.")
            return 2

    client = ApiClient(config.base_url, timeout=config.request_timeout, verify_tls=config.verify_tls)

    try:
        data = client.enroll(
            enrollment_token=args.token,
            hostname=socket.gethostname(),
            version=__version__,
            capabilities=config.capabilities(),
        )
    except ApiError as exc:
        log.error("El enrolamiento fue rechazado: %s", exc.message)
        return 2
    except TransportError as exc:
        log.error("%s", exc)
        return 2

    config.agent_id = int(data["agent_id"])
    config.token = data["token"]
    config.secret = data["secret"]
    config.role = data["role"]
    config.name = data.get("name", "")
    config.poll_interval = float(data.get("poll_interval") or config.poll_interval)

    save(config, args.config)

    log.info("Agente '%s' enrolado con el rol '%s'.", config.name, config.role)
    log.info("Credenciales guardadas en %s (modo 0600).", args.config)
    log.info("Arráncalo con: systemctl enable --now ispgestor-agent")

    return 0


def _run(args) -> int:
    config = load(args.config)

    if not config.enrolled:
        log.error("El agente no está enrolado. Ejecuta primero 'ispgestor-agent enroll'.")
        return 2

    warning = check_permissions(args.config)
    if warning:
        log.warning("%s", warning)

    client = ApiClient(
        config.base_url,
        token=config.token,
        secret=config.secret,
        timeout=config.request_timeout,
        verify_tls=config.verify_tls,
    )

    log.info(
        "Agente '%s' (#%d) arrancando con el rol '%s' contra %s.",
        config.name or "sin nombre",
        config.agent_id,
        config.role,
        config.base_url,
    )

    if config.role == "vpn_host":
        from .roles.vpn_host import VpnHostRole

        VpnHostRole(config, client).run(__version__)
    else:
        from .roles.provisioner import ProvisionerRole

        ProvisionerRole(config, client).run(__version__)

    return 0


def _status(args) -> int:
    config = load(args.config)

    print(f"Agente:      {config.name or '(sin nombre)'} (#{config.agent_id})")
    print(f"Rol:         {config.role}")
    print(f"API:         {config.base_url}")
    print(f"Enrolado:    {'sí' if config.enrolled else 'NO'}")

    if config.role == "vpn_host":
        print(f"Interfaz:    {config.wg_interface} ({config.wg_config_path})")
        print(f"Endpoint:    {config.endpoint_host}:{config.endpoint_port}")
        print(f"Subred:      {config.subnet}")
    else:
        print(f"Interfaces:  {', '.join(config.provisioning_interfaces) or '(ninguna)'}")
        print(f"Sonda:       {', '.join(config.probe_addresses) or '(ninguna)'}")

    warning = check_permissions(args.config)
    if warning:
        print(f"\n⚠  {warning}")

    if not config.enrolled:
        return 2

    client = ApiClient(
        config.base_url,
        token=config.token,
        secret=config.secret,
        timeout=config.request_timeout,
        verify_tls=config.verify_tls,
    )

    try:
        data = client.heartbeat(__version__, config.capabilities())
        print(f"\nConexión:    correcta ({data.get('pending_tasks', 0)} tareas pendientes)")
        return 0
    except (ApiError, TransportError) as exc:
        print(f"\nConexión:    FALLIDA — {exc}")
        return 1


def _selftest(args) -> int:
    """Comprueba el entorno sin tocar ningún equipo ni abrir ninguna sesión."""
    problems: list[str] = []
    notes: list[str] = []

    print(f"ispgestor-agent {__version__} sobre {platform.platform()}")
    print(f"Python {platform.python_version()}\n")

    try:
        config = load(args.config)
    except ConfigError as exc:
        print(f"✗ Configuración: {exc}")
        return 2

    print(f"✓ Configuración leída de {args.config}")

    warning = check_permissions(args.config)
    if warning:
        problems.append(warning)

    if config.role == "vpn_host":
        from .wireguard import WireGuardHost

        host = WireGuardHost(config.wg_interface, config.wg_config_path)

        if host.interface_exists():
            print(f"✓ Interfaz {config.wg_interface} presente con {len(host.peers())} peers")
        else:
            problems.append(
                f"La interfaz '{config.wg_interface}' no existe. El túnel del servidor "
                f"debe estar levantado antes de dar de alta ningún equipo."
            )

        actual = host.server_public_key()
        if actual and config.server_public_key and actual != config.server_public_key:
            problems.append(
                f"La clave pública configurada no coincide con la de la interfaz. "
                f"La real es: {actual}"
            )
        elif actual:
            print("✓ Clave pública del servidor coincide con la configurada")

        if not Path(config.wg_config_path).exists():
            problems.append(f"No existe {config.wg_config_path}: los peers no podrían persistirse.")
        else:
            print(f"✓ Fichero de configuración {config.wg_config_path}")
    else:
        try:
            import librouteros  # noqa: F401

            print("✓ Dependencia 'librouteros' disponible")
        except ImportError:
            problems.append("Falta 'librouteros'. Instálala con: pip install -r requirements.txt")

        from .detect import link, mndp

        if not config.provisioning_interfaces:
            problems.append(
                "No hay interfaces de aprovisionamiento configuradas: no se detectará "
                "ningún equipo al conectarlo."
            )

        for interface in config.provisioning_interfaces:
            state = link.read_link(interface)
            if state is None:
                problems.append(f"La interfaz '{interface}' no existe en este sistema.")
            else:
                estado = "cable conectado" if state.carrier else "sin cable"
                print(f"✓ Interfaz {interface}: {estado} ({state.operstate})")

        try:
            listener = mndp.MndpListener()
            listener.open()
            listener.close()
            print(f"✓ Puerto UDP {mndp.MNDP_PORT} disponible para escuchar MNDP")
        except OSError as exc:
            notes.append(
                f"No se puede escuchar MNDP en el puerto {mndp.MNDP_PORT} ({exc}). "
                f"La detección seguirá funcionando por carrier y sonda, pero más despacio."
            )

    if config.enrolled:
        client = ApiClient(
            config.base_url,
            token=config.token,
            secret=config.secret,
            timeout=config.request_timeout,
            verify_tls=config.verify_tls,
        )
        try:
            client.heartbeat(__version__, config.capabilities())
            print(f"✓ Conexión con {config.base_url}")
        except (ApiError, TransportError) as exc:
            problems.append(f"No se pudo contactar con la API: {exc}")
    else:
        problems.append("El agente no está enrolado.")

    for note in notes:
        print(f"\n⚠  {note}")

    if problems:
        print("\nProblemas encontrados:")
        for problem in problems:
            print(f"  ✗ {problem}")
        return 1

    print("\nTodo correcto: el agente puede operar.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
