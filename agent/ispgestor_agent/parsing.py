"""Traducciones puras de lo que dicen los equipos al vocabulario del servidor.

Módulo aparte y sin dependencias —ni siquiera de `librouteros`— por dos razones:
se puede probar sin tener instalado el cliente de RouterOS, y el driver de airOS
va a necesitar las mismas conversiones cuando llegue. Una función que solo
transforma datos no tiene por qué arrastrar un cliente de red.
"""

from __future__ import annotations

_UPTIME_FACTORS = {"w": 604800, "d": 86400, "h": 3600, "m": 60, "s": 1}


def uptime_seconds(value: str | None) -> int | None:
    """Convierte el uptime de RouterOS (`1w2d3h4m5s`) a segundos.

    El formato omite las unidades que valen cero, así que `3h20s` es legal: hay
    que leerlo por pares número/unidad y no por posiciones fijas.
    """
    if not value:
        return None

    total = 0
    digits = ""

    for char in value:
        if char.isdigit():
            digits += char
        elif char in _UPTIME_FACTORS and digits:
            total += int(digits) * _UPTIME_FACTORS[char]
            digits = ""

    return total or None


def number(value) -> float | None:
    """Convierte a float lo que se pueda, y devuelve None con lo que no.

    Devolver None y no cero es deliberado: «este equipo no informa de su CPU» y
    «su CPU está al 0%» son cosas distintas, y confundirlas pinta gráficas que
    mienten.
    """
    try:
        return float(value)
    except (TypeError, ValueError):
        return None
