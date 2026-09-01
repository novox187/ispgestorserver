# Agente de aprovisionamiento — ISP Gestor

Demonio que corre **fuera** del contenedor Docker y permite que la aplicación
dé de alta routers automáticamente.

## Por qué existe

La aplicación vive aislada en un contenedor gestionado por Coolify. Desde ahí
no puede alcanzar ninguna de las dos cosas que hacen falta para dar de alta un
equipo:

- la tarjeta de red de la oficina donde alguien enchufa el router,
- la interfaz WireGuard del sistema operativo del hosting.

En lugar de intentar salir del contenedor —abriendo puertos, montando sockets o
metiendo credenciales SSH dentro de la imagen—, **se invierte la dirección**:
el agente sale a buscar trabajo por HTTPS y reporta lo que ha hecho. Nadie
escucha en ningún puerto y el NAT de la oficina deja de ser un problema. Es el
mismo patrón de los *runners* de CI.

```
   OFICINA                        INTERNET                  HOSTING (VPS)
┌──────────────┐                                     ┌────────────────────┐
│ MikroTik ────┼─── cable ──┐                        │ wg0  10.77.0.1/24  │
└──────────────┘            │                        │         ▲          │
                            │                        │         │          │
┌───────────────────────────▼──┐   HTTPS saliente    │ ┌───────┴────────┐ │
│ agente rol `provisioner`     │────────────┐        │ │ agente `vpn_host│ │
│  · escucha MNDP :5678        │            ▼        │ └───────┬────────┘ │
│  · vigila el carrier de la NIC│   ┌──────────────┐  │         │ HTTPS    │
│  · habla la API de RouterOS  │   │ api.ironlink │◀─┼─────────┘ saliente │
└──────────────────────────────┘   │ cola de tareas│  │                    │
                                   └──────────────┘  │  contenedor Laravel │
                                                     └────────────────────┘
```

## Roles

| Rol | Dónde corre | Qué hace |
|---|---|---|
| `provisioner` | Oficina, donde se enchufan los routers | Detecta el equipo y le aplica la configuración WireGuard por la API de RouterOS |
| `vpn_host` | Servidor del hosting, junto al WireGuard | Añade y quita peers de la interfaz del túnel |

Un agente solo puede ejecutar las tareas de su rol. El `provisioner` nunca
recibe claves del servidor WireGuard; el `vpn_host` nunca recibe credenciales de
un router.

## Cómo detecta un equipo

Tres señales combinadas, porque ninguna basta por sí sola:

1. **MNDP** (UDP 5678) — el protocolo de descubrimiento de MikroTik. Es la señal
   más rica: trae MAC, modelo, versión e identidad sin necesitar credenciales ni
   que el equipo tenga una IP alcanzable. La pega es que RouterOS solo anuncia
   cada 60 segundos.
2. **Carrier de la NIC** (`/sys/class/net/<iface>/carrier`) — reacciona en el
   instante en que se conecta el cable, y cubre el hueco de esos 60 segundos.
3. **Sonda directa** — al subir el enlace se prueban los puertos típicos de
   RouterOS en la IP de fábrica. Es la red de seguridad para los equipos que
   traen el descubrimiento deshabilitado.

Solo se admiten equipos vistos por las NIC declaradas en `provisioning_interfaces`.
Ese es el límite físico de seguridad: enchufar algo en otro puerto de la oficina
no dispara nada.

## Instalación

```bash
sudo ./install.sh
```

Deja el agente en `/opt/ispgestor-agent` con su propio entorno virtual, crea
`/etc/ispgestor-agent` en modo `0700` e instala la unidad de systemd.

### Enrolamiento

El panel (**MikroTik → Agentes → Registrar agente**) genera un token de un solo
uso con 30 minutos de validez. Se canjea por las credenciales permanentes:

```bash
# En la oficina
ispgestor-agent enroll --url https://api.ironlink.uk --token <TOKEN> \
    --role provisioner --interfaces eth1

# En el hosting
ispgestor-agent enroll --url https://api.ironlink.uk --token <TOKEN> \
    --role vpn_host --wg-interface wg0 --endpoint-host vpn.ironlink.uk
```

En el rol `vpn_host` la clave pública del servidor **se lee de la propia
interfaz**, no se teclea: escribirla mal produce un túnel que nunca completa el
handshake y cuesta mucho diagnosticar.

### Comprobar antes de arrancar

```bash
ispgestor-agent selftest
```

Verifica el entorno sin tocar ningún equipo: dependencias, existencia de las
NIC, disponibilidad del puerto MNDP, presencia de la interfaz WireGuard,
coincidencia de la clave pública y conexión con la API.

### Arrancar

```bash
systemctl enable --now ispgestor-agent
journalctl -u ispgestor-agent -f
```

## Seguridad

- **Autenticación HMAC-SHA256.** Cada petición se firma sobre método, ruta,
  marca de tiempo, nonce y hash del cuerpo. El secreto nunca viaja: viaja la
  firma. Firmar el cuerpo impide alterar la instrucción en tránsito.
- **Antirrepetición.** El servidor rechaza un nonce ya visto y una marca de
  tiempo desviada más de 5 minutos, así que capturar una petición legítima y
  reenviarla no sirve de nada.
- **Lista blanca de operaciones.** El servidor nunca manda comandos crudos, sino
  operaciones tipadas (`wireguard.create_interface`, `ip.add_address`, ...). El
  agente rechaza cualquier `op` que no tenga manejador, de modo que ni siquiera
  un servidor comprometido puede hacerle ejecutar algo arbitrario en la red del
  cliente. Añadir una capacidad exige escribir su manejador — esa fricción es
  deliberada.
- **Ninguna clave privada circula.** La del router la genera RouterOS al crear la
  interfaz y no sale del equipo; la del servidor vive en el sistema de ficheros
  del hosting. Por la API solo viajan claves públicas.
- **Credenciales en `0600`.** `/etc/ispgestor-agent/agent.conf` guarda el secreto
  HMAC; el agente avisa si detecta permisos más laxos.
- **Revocación inmediata.** Desactivar un agente en el panel lo deja fuera en la
  siguiente petición.

## Ficheros

| Ruta | Contenido |
|---|---|
| `ispgestor_agent/client.py` | Cliente HTTP y firma HMAC (solo biblioteca estándar) |
| `ispgestor_agent/config.py` | Configuración persistente en `0600` |
| `ispgestor_agent/detect/mndp.py` | Escucha y parser del protocolo de descubrimiento |
| `ispgestor_agent/detect/link.py` | Vigilancia del cable y sonda del segmento |
| `ispgestor_agent/routeros.py` | Lista blanca de operaciones sobre RouterOS |
| `ispgestor_agent/wireguard.py` | Lista blanca de operaciones sobre `wg` |
| `ispgestor_agent/roles/` | Bucles de cada rol |

## Dependencias

Solo `librouteros` (puro Python), y únicamente para el rol `provisioner`. El
cliente HTTP y la firma usan la biblioteca estándar; el rol `vpn_host` no
necesita nada más allá de `wg`, que viene con `wireguard-tools`.

## Diagnóstico

| Síntoma | Dónde mirar |
|---|---|
| No se detecta un equipo al enchufarlo | `ispgestor-agent selftest`; comprobar que la NIC está en `provisioning_interfaces` y que la MAC del equipo cae en los prefijos admitidos (panel → Workers) |
| `AGENT_CLOCK_SKEW` | El reloj de la máquina se ha desviado más de 5 min: sincronizar con NTP |
| `AGENT_REVOKED` | El agente fue desactivado en el panel |
| `NO_HANDSHAKE` | El router no alcanza el endpoint: revisar su cable de WAN y que el puerto UDP del servidor sea accesible desde la oficina |
| El alta llega al final y falla | Ver `CONTAINER_CANNOT_REACH_ROUTER` en `docs/dispositivos-aprovisionamiento.md`: casi siempre es el enrutado entre el bridge de Docker y `wg0`, no la VPN |
