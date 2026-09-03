"""Pruebas del parser del protocolo UBNT Discovery.

Es una función pura sobre `bytes`, lo que la hace barata de probar y peligrosa
de no probar: se ejecuta contra equipos ajenos, de firmwares variados, y un
fallo aquí no se manifiesta como un error sino como equipos que no aparecen —o
que aparecen con los datos cambiados de sitio.

Las tramas se construyen a mano en vez de capturarse porque así se puede
ejercitar lo que no se ve en una captura normal: paquetes truncados, TLV
desconocidos, longitudes que mienten.
"""

import struct
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ispgestor_agent.detect.ubnt import cidr_is_allowed, parse


def tlv(tipo: int, valor: bytes) -> bytes:
    return bytes([tipo]) + struct.pack(">H", len(valor)) + valor


def trama(*tlvs: bytes, version: int = 0x01) -> bytes:
    cuerpo = b"".join(tlvs)
    return bytes([version, 0x00]) + struct.pack(">H", len(cuerpo)) + cuerpo


MAC = bytes([0x24, 0xA4, 0x3C, 0x11, 0x22, 0x33])


class TestParse:
    def test_lee_una_respuesta_completa(self):
        payload = trama(
            tlv(0x01, MAC),
            tlv(0x02, MAC + bytes([10, 9, 0, 5])),
            tlv(0x03, b"XW.v6.3.11"),
            tlv(0x0B, b"Torre-Norte"),
            tlv(0x0C, b"NanoStation M5"),
            tlv(0x0D, b"ENLACE-NORTE"),
            tlv(0x14, b"NanoStation M5"),
        )

        d = parse(payload, source_ip="10.9.0.5")

        assert d is not None
        assert d.mac_address == "24:A4:3C:11:22:33"
        assert d.ip_address == "10.9.0.5"
        assert d.firmware == "XW.v6.3.11"
        assert d.hostname == "Torre-Norte"
        assert d.model == "NanoStation M5"
        assert d.essid == "ENLACE-NORTE"

    def test_se_queda_con_la_direccion_por_la_que_respondio(self):
        # Este test decía lo contrario y por eso el fallo pasó desapercibido:
        # daba por buena la dirección que declara el equipo. Pero la dirección
        # que sirve es aquella por la que se le acaba de alcanzar; la que él
        # declara puede ser de una interfaz a la que no llega nadie.
        payload = trama(tlv(0x02, MAC + bytes([192, 168, 1, 20])))

        d = parse(payload, source_ip="10.9.0.5")

        assert d.ip_address == "10.9.0.5"

    def test_varias_interfaces_elige_la_del_origen(self):
        # El caso real: airOS lista todas sus interfaces en TLV 0x02 y la de
        # gestión no es la última. Un barrido de 23 antenas devolvió 22
        # direcciones inservibles por quedarse con la que cerraba la lista.
        wlan = bytes([0xFC, 0xEC, 0xDA, 0x6C, 0x90, 0x50])
        lan = bytes([0xFC, 0xEC, 0xDA, 0x6C, 0x90, 0x51])

        payload = trama(
            tlv(0x02, lan + bytes([10, 10, 10, 250])),
            tlv(0x02, wlan + bytes([169, 254, 144, 81])),
            tlv(0x02, lan + bytes([192, 168, 90, 1])),
            tlv(0x0B, b"STATION DORADO"),
        )

        d = parse(payload, source_ip="10.10.10.250")

        assert d.ip_address == "10.10.10.250"
        # Y la MAC es la de ESA interfaz, no la de la última que se leyó.
        assert d.mac_address == "FC:EC:DA:6C:90:51"

    def test_sin_origen_se_acepta_lo_que_declara_el_equipo(self):
        # Una traza guardada o una prueba no traen dirección de origen: entonces
        # lo que el equipo declara es todo lo que hay.
        payload = trama(tlv(0x02, MAC + bytes([192, 168, 1, 20])))

        d = parse(payload)

        assert d.ip_address == "192.168.1.20"

    def test_origen_desconocido_por_el_equipo_conserva_el_origen(self):
        # Con NAT por medio el equipo no se reconoce en la dirección por la que
        # respondió. Se conserva esa, que es la única por la que se le alcanza.
        payload = trama(tlv(0x02, MAC + bytes([192, 168, 1, 20])))

        d = parse(payload, source_ip="10.0.0.99")

        assert d.ip_address == "10.0.0.99"
        assert d.mac_address == "24:A4:3C:11:22:33"

    def test_ignora_tlv_desconocidos(self):
        # Un firmware nuevo puede añadir campos: eso no puede invalidar el resto.
        payload = trama(
            tlv(0x01, MAC),
            tlv(0x7F, b"campo del futuro"),
            tlv(0x0B, b"Antena"),
        )

        d = parse(payload)

        assert d.mac_address == "24:A4:3C:11:22:33"
        assert d.hostname == "Antena"

    def test_trama_truncada_devuelve_lo_leido(self):
        # Un paquete cortado a mitad no puede tumbar el barrido: se aprovecha lo
        # que se alcanzó a leer.
        completa = trama(tlv(0x01, MAC), tlv(0x0B, b"Antena"))
        d = parse(completa[:-3])

        assert d is not None
        assert d.mac_address == "24:A4:3C:11:22:33"

    def test_longitud_que_miente_no_revienta(self):
        # TLV que declara 200 bytes y trae 4.
        payload = trama(tlv(0x01, MAC)) + bytes([0x03]) + struct.pack(">H", 200) + b"abcd"

        d = parse(payload)

        assert d is not None
        assert d.mac_address == "24:A4:3C:11:22:33"

    def test_paquete_demasiado_corto(self):
        assert parse(b"") is None
        assert parse(b"\x01\x00") is None

    def test_version_desconocida(self):
        # Cualquier otra cosa escuchando en el 10001 no es un Ubiquiti.
        assert parse(bytes([0x99, 0x00, 0x00, 0x00])) is None

    def test_sin_mac_ni_nombre_no_hay_candidato(self):
        # Nada que ofrecerle al operador en la lista.
        assert parse(trama(tlv(0x03, b"v1.0"))) is None

    def test_acepta_la_version_2_de_la_cabecera(self):
        d = parse(trama(tlv(0x01, MAC), version=0x02))
        assert d is not None

    def test_uptime(self):
        d = parse(trama(tlv(0x01, MAC), tlv(0x0A, struct.pack(">I", 86400))))
        assert d.uptime_seconds == 86400

    def test_texto_con_bytes_invalidos(self):
        # Un equipo con el nombre en una codificación rara no puede romper nada.
        d = parse(trama(tlv(0x01, MAC), tlv(0x0B, b"Torre\xff\xfeNorte")))
        assert d.hostname is not None


class TestToFinding:
    def test_acota_los_textos_para_no_perder_el_barrido(self):
        # El informe va en una sola petición: un campo demasiado largo la
        # rechaza entera y se van al traste los demás equipos. Pasó con cuatro
        # antenas cuyo firmware traía «-licensed».
        d = parse(
            trama(
                tlv(0x01, MAC),
                tlv(0x03, b"X" * 200),
                tlv(0x0B, b"N" * 200),
                tlv(0x14, b"M" * 200),
                tlv(0x0D, b"E" * 200),
            ),
            source_ip="10.10.10.5",
        )

        finding = d.to_finding()

        assert len(finding["firmware"]) == 80
        assert len(finding["hostname"]) == 100
        assert len(finding["model"]) == 60
        assert len(finding["essid"]) == 64

    def test_el_firmware_real_mas_largo_cabe_entero(self):
        # 43 caracteres: es el que rompía el límite anterior de 40.
        firmware = b"XW.ar934x.v6.1.7-licensed.32555.180523.1625"

        d = parse(trama(tlv(0x01, MAC), tlv(0x03, firmware)), source_ip="10.10.10.5")

        assert d.to_finding()["firmware"] == firmware.decode()

    def test_los_campos_ausentes_siguen_siendo_nulos(self):
        d = parse(trama(tlv(0x01, MAC)), source_ip="10.10.10.5")

        finding = d.to_finding()

        assert finding["firmware"] is None
        assert finding["hostname"] is None


class TestListaBlanca:
    def test_permite_un_rango_contenido(self):
        assert cidr_is_allowed("192.168.1.0/24", ["192.168.0.0/16"])

    def test_permite_el_rango_exacto(self):
        assert cidr_is_allowed("10.9.0.0/24", ["10.9.0.0/24"])

    def test_rechaza_un_rango_fuera(self):
        assert not cidr_is_allowed("8.8.8.0/24", ["192.168.0.0/16"])

    def test_rechaza_un_rango_mas_amplio(self):
        # Pedir el /8 que contiene al permitido no vale: sería barrer 16 millones
        # de direcciones amparándose en un permiso de 256.
        assert not cidr_is_allowed("10.0.0.0/8", ["10.9.0.0/24"])

    def test_lista_vacia_no_permite_nada(self):
        # Al revés que la lista de MAC admitidas, donde vacío significa
        # «cualquiera». Aquí una lista sin rellenar por descuido no puede dejar
        # al agente barriendo lo que le manden.
        assert not cidr_is_allowed("192.168.1.0/24", [])

    def test_cidr_invalido(self):
        assert not cidr_is_allowed("no-es-un-cidr", ["192.168.0.0/16"])
