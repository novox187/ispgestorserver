"""Pruebas de los ayudantes de la línea de órdenes.

Son funciones pequeñas pero mandan sobre cosas que fallan tarde y mal: la lista
de rangos que el agente aceptará barrer —su límite de seguridad— y la unidad de
systemd que se le dice al operador que arranque.
"""

import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ispgestor_agent.__main__ import _parse_cidrs, _selftest, _unit_name


class _Args:
    """Lo mínimo que `_selftest` mira de los argumentos."""

    def __init__(self, config: Path):
        self.config = config


def _config(tmp_path: Path, **campos) -> _Args:
    """Deja un fichero de configuración sin enrolar, para no salir a la red."""
    ruta = tmp_path / "agent.conf"
    ruta.write_text(json.dumps({"base_url": "https://api.invalida.test", **campos}))
    ruta.chmod(0o600)

    return _Args(ruta)


class TestParseCidrs:
    def test_lista_vacia_sin_argumento(self):
        # Vacío significa «no barrer nada», no «barrer cualquier cosa».
        assert _parse_cidrs(None) == []
        assert _parse_cidrs("") == []

    def test_un_rango(self):
        assert _parse_cidrs("10.10.10.0/24") == ["10.10.10.0/24"]

    def test_varios_rangos_con_espacios(self):
        # El operador los pega de una lista y los espacios sobran en cualquier
        # sitio; no puede depender de teclearlo pegado.
        assert _parse_cidrs(" 10.10.10.0/24 , 192.168.1.0/24 ") == [
            "10.10.10.0/24",
            "192.168.1.0/24",
        ]

    def test_comas_de_sobra_no_generan_entradas_vacias(self):
        assert _parse_cidrs("10.10.10.0/24,,") == ["10.10.10.0/24"]

    def test_rango_invalido_se_rechaza_al_enrolar(self):
        # Validar aquí y no al barrer: un dedazo produciría un agente que se
        # instala sin quejarse y falla meses después, la primera vez que alguien
        # pide un barrido.
        with pytest.raises(ValueError, match="243"):
            _parse_cidrs("10.10.10.0/243")

    def test_lo_que_no_es_un_rango_se_rechaza(self):
        with pytest.raises(ValueError):
            _parse_cidrs("la red de la oficina")

    def test_una_direccion_suelta_vale_como_rango(self):
        # `ip_network` la acepta como /32 y es legítimo: barrer un solo equipo.
        assert _parse_cidrs("10.10.10.250") == ["10.10.10.250"]


class TestUnitName:
    def test_instalacion_heredada_usa_la_unidad_simple(self):
        assert _unit_name(Path("/etc/ispgestor-agent/agent.conf")) == "ispgestor-agent"

    def test_instancia_usa_la_unidad_plantilla(self):
        # Es lo que permite que el hosting corra vpn_host y monitor a la vez.
        assert _unit_name(Path("/etc/ispgestor-agent/monitor.conf")) == "ispgestor-agent@monitor"


class TestSelftestPorRol:
    """El selftest tiene que comprobar lo que ese rol necesita, y solo eso.

    Se descubrió instalando un `monitor` de verdad: la rama por descarte le
    exigía NIC de aprovisionamiento y puerto MNDP —cosas del `provisioner`—, así
    que daba rojo en una instalación perfecta. El instalador desatendido lo tomó
    por un fallo y dejó el agente instalado pero sin arrancar.
    """

    def test_el_monitor_no_exige_nic_de_aprovisionamiento(self, tmp_path, capsys):
        _selftest(_config(tmp_path, role="monitor", scannable_cidrs=["10.10.10.0/24"]))
        salida = capsys.readouterr().out

        assert "interfaces de aprovisionamiento" not in salida
        # Y sí informa de lo suyo: los rangos que tiene permitido barrer.
        assert "10.10.10.0/24" in salida

    def test_el_monitor_sin_rangos_avisa_pero_no_lo_da_por_roto(self, tmp_path, capsys):
        # El sondeo del parque funciona igual; lo que queda inutilizado es el
        # descubrimiento. Es un aviso, no un problema.
        _selftest(_config(tmp_path, role="monitor", scannable_cidrs=[]))
        salida = capsys.readouterr().out

        assert "Sin rangos permitidos" in salida
        assert "Sin rangos permitidos" not in salida.split("Problemas encontrados:")[-1]

    def test_el_provisioner_si_exige_nic(self, tmp_path, capsys):
        _selftest(_config(tmp_path, role="provisioner", provisioning_interfaces=[]))
        salida = capsys.readouterr().out

        assert "interfaces de aprovisionamiento" in salida

    def test_el_vpn_host_no_exige_nic_de_aprovisionamiento(self, tmp_path, capsys):
        _selftest(_config(tmp_path, role="vpn_host", wg_interface="noexiste0"))
        salida = capsys.readouterr().out

        assert "interfaces de aprovisionamiento" not in salida
