"""Pruebas de la capa que cambia según el sistema operativo.

Se prueba parseando salidas reales de cada herramienta en vez de ejecutándolas,
porque el fallo que importa no es «no se pudo lanzar el comando» sino «se
interpretó mal lo que devolvió». Y eso solo se vería en la máquina del cliente,
que corre otro sistema y en otro idioma que el de quien programó esto.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ispgestor_agent.detect.link import _mac_de_la_linea_de


class TestArpEnLinux:
    SALIDA = "10.9.0.5 dev eth1 lladdr 24:a4:3c:11:22:33 REACHABLE\n"

    def test_lee_la_mac(self):
        assert _mac_de_la_linea_de("10.9.0.5", self.SALIDA) == "24:A4:3C:11:22:33"

    def test_sin_entrada_no_inventa_nada(self):
        assert _mac_de_la_linea_de("10.9.0.5", "10.9.0.5 dev eth1 FAILED\n") is None


class TestArpEnMacos:
    SALIDA = "? (10.9.0.5) at 24:a4:3c:11:22:33 on en0 ifscope [ethernet]\n"

    def test_lee_la_mac(self):
        assert _mac_de_la_linea_de("10.9.0.5", self.SALIDA) == "24:A4:3C:11:22:33"

    def test_entrada_incompleta(self):
        # macOS imprime esto mientras la resolución está en curso.
        salida = "? (10.9.0.5) at (incomplete) on en0 ifscope [ethernet]\n"
        assert _mac_de_la_linea_de("10.9.0.5", salida) is None


class TestArpEnWindows:
    # Salida de un Windows en español: cabeceras y tipo traducidos, MAC con
    # guiones. Nada de esto se puede parsear por posición ni por palabra.
    SALIDA = (
        "\nInterfaz: 10.9.0.1 --- 0xb\n"
        "  Dirección de Internet     Dirección física      Tipo\n"
        "  10.9.0.5              24-a4-3c-11-22-33     dinámico\n"
    )

    def test_lee_la_mac_y_normaliza_los_guiones(self):
        assert _mac_de_la_linea_de("10.9.0.5", self.SALIDA) == "24:A4:3C:11:22:33"

    def test_no_devuelve_la_mac_de_otro_equipo(self):
        # `arp -a <ip>` de Windows ignora el filtro cuando la entrada no está en
        # caché y devuelve la tabla completa. Coger la primera MAC que aparezca
        # ataría el alta al equipo equivocado, que es peor que no dar ninguna.
        tabla = (
            "\nInterfaz: 10.9.0.1 --- 0xb\n"
            "  Dirección de Internet     Dirección física      Tipo\n"
            "  10.9.0.1              aa-bb-cc-dd-ee-01     dinámico\n"
            "  10.9.0.9              aa-bb-cc-dd-ee-09     dinámico\n"
        )

        assert _mac_de_la_linea_de("10.9.0.5", tabla) is None

    def test_elige_la_linea_correcta_entre_varias(self):
        tabla = (
            "  10.9.0.1              aa-bb-cc-dd-ee-01     dinámico\n"
            "  10.9.0.5              24-a4-3c-11-22-33     dinámico\n"
            "  10.9.0.9              aa-bb-cc-dd-ee-09     dinámico\n"
        )

        assert _mac_de_la_linea_de("10.9.0.5", tabla) == "24:A4:3C:11:22:33"


class TestSalidasDegeneradas:
    def test_salida_vacia(self):
        assert _mac_de_la_linea_de("10.9.0.5", "") is None

    def test_una_ip_que_es_prefijo_de_otra_no_confunde(self):
        # «10.9.0.5» aparece dentro de «10.9.0.55». Si se emparejara por
        # subcadena se devolvería la MAC del vecino equivocado.
        tabla = "  10.9.0.55             aa-bb-cc-dd-ee-55     dinámico\n"

        assert _mac_de_la_linea_de("10.9.0.5", tabla) is None
