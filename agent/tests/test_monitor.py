"""Pruebas de las conversiones que alimentan la telemetría.

El agente no tenía ni una prueba. Se empieza por las funciones puras —las que
traducen lo que dice un equipo a lo que entiende el servidor— porque son las que
más se van a tocar al añadir fabricantes y las que fallan en silencio: un uptime
mal leído no rompe nada, solo puebla una gráfica con datos falsos.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ispgestor_agent.parsing import number as _number, uptime_seconds as _uptime_seconds


class TestUptime:
    def test_formato_completo(self):
        assert _uptime_seconds("1w2d3h4m5s") == 604800 + 172800 + 10800 + 240 + 5

    def test_omite_unidades_en_cero(self):
        # RouterOS no rellena con ceros: «3h20s» es una respuesta legal y es
        # justo donde se rompe un parser que asuma posiciones fijas.
        assert _uptime_seconds("3h20s") == 10800 + 20

    def test_solo_segundos(self):
        assert _uptime_seconds("45s") == 45

    def test_semanas_sueltas(self):
        assert _uptime_seconds("2w") == 1209600

    def test_vacio_o_ausente(self):
        assert _uptime_seconds(None) is None
        assert _uptime_seconds("") is None

    def test_basura_no_revienta(self):
        # Un firmware inesperado no puede tumbar la vuelta de sondeo.
        assert _uptime_seconds("no-es-un-uptime") is None

    def test_numero_sin_unidad_se_ignora(self):
        assert _uptime_seconds("123") is None


class TestNumber:
    def test_convierte_cadenas(self):
        assert _number("17") == 17.0

    def test_none_ante_valor_no_numerico(self):
        assert _number(None) is None
        assert _number("n/a") is None
        assert _number({}) is None
