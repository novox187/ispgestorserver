"""Pruebas de los ayudantes de la línea de órdenes.

Son funciones pequeñas pero mandan sobre cosas que fallan tarde y mal: la lista
de rangos que el agente aceptará barrer —su límite de seguridad— y la unidad de
systemd que se le dice al operador que arranque.
"""

import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ispgestor_agent.__main__ import _parse_cidrs, _unit_name


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
