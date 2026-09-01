"""Configuración persistente del agente.

El fichero vive en /etc/ispgestor-agent/agent.conf con permisos 0600 porque
guarda el secreto HMAC con el que se firma cada petición. Quien lo lea puede
suplantar al agente y, con él, pedir cambios en la infraestructura de red.
"""

from __future__ import annotations

import json
import os
import stat
from dataclasses import dataclass, field, asdict
from pathlib import Path

DEFAULT_PATH = Path("/etc/ispgestor-agent/agent.conf")


class ConfigError(RuntimeError):
    """La configuración falta, está incompleta o es ilegible."""


@dataclass
class AgentConfig:
    """Estado persistente del agente."""

    base_url: str = ""
    agent_id: int = 0
    token: str = ""
    secret: str = ""
    role: str = ""
    name: str = ""

    # ── Rol `provisioner` ───────────────────────────────────────────────────
    # NIC por las que se admite un equipo. Es el límite físico de seguridad:
    # enchufar algo en otro puerto de la oficina no dispara ningún alta.
    provisioning_interfaces: list[str] = field(default_factory=list)
    # IP de fábrica que se sondea al subir el enlace.
    probe_addresses: list[str] = field(default_factory=lambda: ["192.168.88.1"])

    # ── Rol `vpn_host` ──────────────────────────────────────────────────────
    wg_interface: str = "wg0"
    wg_config_path: str = "/etc/wireguard/wg0.conf"
    server_public_key: str = ""
    endpoint_host: str = ""
    endpoint_port: int = 51820
    subnet: str = "10.77.0.0/24"

    # ── Operación ───────────────────────────────────────────────────────────
    poll_interval: float = 3.0
    heartbeat_interval: float = 60.0
    request_timeout: float = 30.0
    verify_tls: bool = True

    @property
    def enrolled(self) -> bool:
        return bool(self.agent_id and self.token and self.secret)

    def capabilities(self) -> dict:
        """Lo que el agente publica de sí mismo al servidor.

        Para el rol `vpn_host` esto es lo que permite a la saga saber a dónde
        debe marcar el router y con qué clave pública. La clave PRIVADA del
        servidor nunca aparece aquí ni en ningún otro sitio que salga de esta
        máquina.
        """
        if self.role == "vpn_host":
            return {
                "server_public_key": self.server_public_key,
                "endpoint_host": self.endpoint_host,
                "endpoint_port": self.endpoint_port,
                "interface": self.wg_interface,
                "subnet": self.subnet,
            }

        return {
            "provisioning_interfaces": self.provisioning_interfaces,
            "probe_addresses": self.probe_addresses,
        }


def load(path: Path | None = None) -> AgentConfig:
    path = path or DEFAULT_PATH

    if not path.exists():
        raise ConfigError(
            f"No existe {path}. Ejecuta primero:\n"
            f"  ispgestor-agent enroll --url <API> --token <TOKEN>"
        )

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ConfigError(f"No se pudo leer {path}: {exc}") from exc

    known = {f for f in AgentConfig.__dataclass_fields__}
    return AgentConfig(**{k: v for k, v in raw.items() if k in known})


def save(config: AgentConfig, path: Path | None = None) -> None:
    path = path or DEFAULT_PATH
    path.parent.mkdir(parents=True, exist_ok=True)

    # Se crea con 0600 desde el principio y no se relaja después: escribir y
    # luego hacer chmod deja una ventana en la que el secreto es legible.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, stat.S_IRUSR | stat.S_IWUSR)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(asdict(config), handle, indent=2, ensure_ascii=False)
        handle.write("\n")


def check_permissions(path: Path | None = None) -> str | None:
    """Devuelve una advertencia si el fichero es legible por terceros."""
    path = path or DEFAULT_PATH

    if not path.exists():
        return None

    mode = path.stat().st_mode
    if mode & (stat.S_IRGRP | stat.S_IROTH | stat.S_IWGRP | stat.S_IWOTH):
        return (
            f"{path} es accesible por otros usuarios ({oct(stat.S_IMODE(mode))}). "
            f"Corrígelo con: chmod 600 {path}"
        )

    return None
