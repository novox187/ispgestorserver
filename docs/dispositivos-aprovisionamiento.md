# Alta automática de dispositivos — detección física y VPN WireGuard

## Problema que se corrige

Dar de alta un router era un proceso manual y ciego. Un `super_admin` tecleaba
`name`, `host`, `port`, `username`, `password`, `network_cidr` y `gateway` en un
modal, y `MikrotikRouterController::store()` guardaba la fila **sin probar la
conectividad, sin auditar nada y sin notificar**. En paralelo, alguien había
configurado a mano un túnel VPN en el sistema operativo del hosting para que el
contenedor pudiera alcanzar a los equipos.

Eso producía cuatro problemas concretos:

1. **Un error de tecleo bloqueaba el sistema.** Por la regla «el primer router
   creado es primary» (`MikrotikRouter::booted()`), una credencial mal escrita
   dejaba todas las rutas con middleware `primary_router` devolviendo **423
   Locked** hasta la siguiente pasada del monitor, cinco minutos después.
2. **El alta no dejaba rastro.** `MikrotikRouter` no usaba el trait `Auditable`,
   así que crear un equipo, cambiarle las credenciales, promoverlo a principal o
   borrarlo no generaba ninguna fila en `audits`.
3. **Los datos del equipo se tiraban.** Modelo, versión de RouterOS, serie y MAC
   estaban disponibles en `MikroTikService::getSystemInfo()` y no se guardaban en
   ningún sitio, de modo que no había forma de decidir compatibilidad ni de
   reconocer un equipo que volvía.
4. **La VPN era un paso manual fuera del sistema**, sin relación con el alta y
   sin nada que verificase que ambos extremos habían quedado coherentes.

## Resultado

El operador enchufa un MikroTik al puerto de aprovisionamiento y, sin tocar el
panel, el sistema lo detecta, lo identifica, comprueba que es compatible, monta
un túnel WireGuard en los dos extremos, verifica el enlace de punta a punta,
rota las credenciales de fábrica y lo registra. Cada paso queda en `audits`. Si
algo falla a mitad, revierte lo aplicado y avisa.

---

## Arquitectura

### El problema de conectividad, y cómo se resuelve

La aplicación vive aislada en un contenedor gestionado por Coolify. Desde ahí no
puede alcanzar ninguna de las dos cosas que hacen falta:

- la tarjeta de red de la oficina donde se enchufa el router,
- la interfaz WireGuard del sistema operativo del hosting.

Las salidas habituales —abrir un puerto en el host, montar un socket dentro del
contenedor, meter una clave SSH en la imagen— tienen todas el mismo defecto:
amplían la superficie de ataque del contenedor justo hacia la infraestructura de
red.

**La solución es invertir la dirección.** Dos demonios corren fuera del
contenedor y salen a buscar trabajo por HTTPS. Nadie escucha en ningún puerto,
no hace falta `host.docker.internal`, no hay que tocar la red de Coolify y el
NAT de la oficina deja de importar. Es el patrón de los *runners* de CI.

```
   OFICINA (on-prem)                  INTERNET              HOSTING (VPS Coolify)
┌──────────────────────┐                               ┌─────────────────────────┐
│  MikroTik nuevo      │                               │  SO del host            │
│  ether2 ─ LAN 88.1 ──┼─┐                             │  ┌───────────────────┐  │
│  ether1 ─ WAN ───────┼─┼──── túnel WireGuard ────────┼─▶│ wg0  10.77.0.1/24 │  │
└──────────────────────┘ │                             │  └─────────┬─────────┘  │
                         │                             │            │ ruteo      │
┌────────────────────────▼─┐   HTTPS (saliente)        │  ┌─────────▼─────────┐  │
│ agente `provisioner`     │──────────────┐            │  │ contenedor Laravel│  │
│  · escucha MNDP :5678    │              ▼            │  └─────────▲─────────┘  │
│  · vigila carrier de NIC │    ┌──────────────────┐   │            │            │
│  · habla RouterOS API    │    │  api.ironlink.uk │◀──┼────────────┘            │
└──────────────────────────┘    │  cola de tareas  │   │  ┌───────────────────┐  │
                                └──────────────────┘   │  │ agente `vpn_host` │  │
                                          ▲────────────┼──│  · wg set peer    │  │
                                   HTTPS (saliente)    │  └───────────────────┘  │
                                                       └─────────────────────────┘
```

### Detección física

Tres señales combinadas en el agente `provisioner`, porque ninguna basta sola:

| Señal | Qué aporta | Limitación |
|---|---|---|
| **MNDP** (UDP 5678) | MAC, identidad, modelo y versión sin credenciales ni IP alcanzable | RouterOS solo anuncia cada 60 s |
| **Carrier de la NIC** (`/sys/class/net/<i>/carrier`) | Reacciona en el instante en que se conecta el cable | No dice qué hay al otro lado |
| **Sonda directa** (TCP a 8728/8729/22/80) | Confirma que es un RouterOS; funciona con el descubrimiento apagado | Necesita conocer la IP de fábrica |

Solo se admiten equipos vistos por las NIC declaradas en el agente
(`provisioning_interfaces`). Ese es el límite físico de seguridad: enchufar algo
en otro puerto de la oficina no dispara nada. Además, el servidor filtra por
prefijo OUI de MikroTik (configurable en el panel).

---

## El flujo, paso a paso

Lo conduce `DeviceProvisioningOrchestrator` como una **saga con compensación**.
No es una transacción porque lo que se modifica vive fuera de la base de datos
—un router y el sistema operativo del hosting—, y ahí no hay rollback: hay que
deshacer explícitamente lo hecho. Cada paso que modifica algo apila su reversión.

| # | Paso | Quién | Qué hace |
|---|---|---|---|
| 1 | `identify_device` | provisioner | Entra por la LAN probando las credenciales de fábrica; lee identidad, modelo, versión y serie; comprueba salida a internet |
| 2 | *(servidor)* | — | `DeviceCompatibilityChecker`. Deduplica por serie/MAC para re-aprovisionar en vez de duplicar |
| 3 | `apply_router_vpn` | provisioner | Crea la interfaz WireGuard, lee su clave pública, añade el peer del servidor, asigna la IP, abre el firewall y habilita la API |
| 4 | `apply_host_peer` | vpn_host | `wg set` + persistencia en `/etc/wireguard/wg0.conf` |
| 5 | `verify_router_vpn` | provisioner | Handshake presente + ping al servidor **desde el equipo** |
| 6 | `verify_host_peer` | vpn_host | Handshake reciente + ping a la IP asignada |
| 7 | `harden_router` | provisioner | Crea el usuario `ispgestor-api` con contraseña generada, **verifica que sirve** y cierra la API a la subred del túnel |
| 8 | *(servidor)* | — | Crea la fila `MikrotikRouter` y ejecuta `MikrotikHealthChecker::check()` **desde dentro del contenedor** |

### Tres decisiones de orden que no son arbitrarias

**El router va antes que el hosting.** La clave pública del equipo no existe
hasta que RouterOS crea la interfaz, y el peer del servidor la necesita.

**El endurecimiento va después de verificar.** Cerrar la API a la subred del
túnel deja al agente de la oficina sin acceso por la LAN. Si eso pasara antes de
verificar, no habría forma de comprobar nada ni de revertir con las credenciales
de fábrica. El paso 7 incluye `user.verify_login` **antes** de cerrar la puerta:
si el usuario nuevo no funciona, todavía se puede dar marcha atrás.

**La fila `MikrotikRouter` se crea al final.** Crearla al empezar significaría
que, ante cualquier fallo, la regla «el primer router es primary» ya habría
dejado el sistema devolviendo 423 en todas las rutas con `primary_router`.

### La verificación que de verdad cierra el círculo

Que los dos extremos digan que hay handshake no basta. El paso 8 hace que sea
**la aplicación, aislada en Docker**, la que alcance al equipo por el túnel con
las credenciales rotadas. Es exactamente lo que hará el resto del sistema a
partir de ese momento. Si falla, el alta se revierte aunque la VPN esté perfecta.

### Reversión

Cada paso que modifica algo apila su compensación *antes* de mandar la tarea —si
el agente aplica parte y muere sin reportar, la reversión ya está registrada—.
Al fallar, se desapilan en orden inverso.

Una compensación que falla **no detiene las demás**: pararse ahí dejaría residuo
en el otro extremo. Lo que no se pudo revertir se audita con
`manual_cleanup: true` y la notificación crítica dice exactamente qué hay que
limpiar a mano en cada equipo.

---

## Seguridad

### Canal máquina a máquina

No usa Sanctum: sus tokens están atados a un usuario y no caducan. El canal se
implementa a mano en `AuthenticateProvisioningAgent` (alias `agent.hmac`):

```
firma = HMAC-SHA256(secreto, "MÉTODO\nRUTA\nTIMESTAMP\nNONCE\nsha256(cuerpo)")
```

Cabeceras: `X-ISPG-Agent`, `X-ISPG-Timestamp`, `X-ISPG-Nonce`, `X-ISPG-Signature`.

Se valida en este orden, y el orden importa:

1. El agente existe y está activo — revocar surte efecto en la siguiente petición.
2. El desfase de reloj ≤ 300 s, lo que acota la ventana de validez de una
   petición capturada.
3. **La firma**, comparada con `hash_equals` (tiempo constante).
4. **El nonce**, consumido con `Cache::add` (atómico). Se comprueba *después* de
   la firma a propósito: una petición no autenticada no debe dejar rastro en el
   almacén de nonces, o cualquiera con el token podría quemar los de un agente
   legítimo.

El TTL del nonce (900 s) es holgadamente mayor que el doble del desfase
tolerado; si no, una repetición tardía encontraría el nonce ya olvidado.

Cada rechazo se audita como `AGENT_AUTH_FAILED`: un barrido de tokens contra
este canal queda a la vista en el historial.

### Autorización por rol

Un `provisioner` **nunca** recibe claves del servidor WireGuard; un `vpn_host`
**nunca** recibe credenciales de un router. Se comprueba dos veces: al crear la
tarea (`ProvisioningTaskDispatcher`) y al reclamarla (`AgentTaskController`).

### Ninguna clave privada circula

La del router la genera RouterOS al crear la interfaz y no sale del equipo; la
del servidor vive en el sistema de ficheros del hosting y el agente solo publica
su contraparte pública. Por la API viajan únicamente claves públicas.

### Lista blanca de operaciones

El servidor no manda comandos crudos, sino operaciones tipadas
(`wireguard.create_interface`, `ip.add_address`, …). El agente rechaza cualquier
`op` sin manejador. Ni siquiera un servidor comprometido puede hacerle ejecutar
algo arbitrario en la red del cliente. Añadir una capacidad exige escribir su
manejador en `routeros.py` o `wireguard.py` — esa fricción es deliberada.

### Rotación de credenciales

El alta automática **no puede** dejar los equipos con las credenciales de
fábrica: eso convertiría el automatismo en un agujero. El paso 7 crea el usuario
`ispgestor-api` con una contraseña generada de 32 caracteres, acotado a la
subred del túnel, y la guarda cifrada en `mikrotik_routers.password`.

> **Nota de esquema.** `mikrotik_routers.password` era `VARCHAR(255)` y se
> quedaba corta: el cast `encrypted` produce un sobre de ~280 caracteres. Era un
> fallo latente —el alta manual valida `max:255` sobre el texto plano, así que
> una contraseña larga tecleada a mano ya reventaba— que solo salió a la luz con
> las contraseñas generadas. La migración `..._000005` la pasa a `TEXT`.

---

## Configuración

### Estructural — `config/provisioning.php`

Nombres, versión mínima de RouterOS, nombre de la interfaz, usuario dedicado.
Cosas que rara vez cambian.

### Operativa — panel → *Configuraciones → Workers*

Respaldada por `automation_settings`, clave `device_auto_provisioning`. Cambiar
un parámetro **no exige redesplegar en Coolify**, que fue el criterio que ya
siguieron los módulos de MikroTik y notificaciones.

| Parámetro | Por defecto | Para qué |
|---|---|---|
| `auto_approve` | `true` | Con `false`, cada alta espera aprobación manual antes de tocar el router |
| `vpn_subnet` | `10.77.0.0/24` | Rango del que se reparten las IP de gestión |
| `vpn_server_ip` | `10.77.0.1` | Extremo del hosting; es la IP que los routers hacen ping |
| `endpoint_host` | *(vacío)* | Vacío = el que publica el agente. Rellenar solo si el host ve una IP privada y los equipos deben marcar a otro nombre |
| `endpoint_port` | `51820` | Puerto UDP del servidor |
| `keepalive` | `25` | Mantiene abierto el mapeo NAT de la oficina; sin esto el hosting no puede iniciar conexiones hacia el equipo |

Desactivar la fila entera detiene las altas nuevas sin revocar credenciales de
los agentes — útil para una ventana de mantenimiento.

Segunda fila, `provisioning_agent_monitor`: vigila que los agentes sigan
reportando. Un agente caído no rompe nada de forma visible, simplemente deja de
haber altas; por eso hay que detectarlo activamente.

---

## Auditoría

Todo el proceso queda en `audits`, siguiendo la convención ya establecida para
eventos que no nacen de un modelo Eloquent (pseudo-tabla + verbo de dominio,
igual que `mikrotik_queue_sync`).

- **`table_name`**: `device_provisioning` · **`record_id`**: el id de la sesión.
- Punto único de escritura: `ProvisioningAuditor`.

Como `record_id` es la sesión, el visor existente reconstruye el alta entera sin
necesitar cambios:

```sql
SELECT operation, created_at FROM audits
WHERE table_name = 'device_provisioning' AND record_id = '42' ORDER BY id;
```

Verbos: `PROVISION_DETECTED`, `PROVISION_IDENTIFIED`,
`PROVISION_REJECTED_INCOMPATIBLE`, `PROVISION_APPROVED`,
`PROVISION_ROUTER_APPLIED`, `PROVISION_HOST_APPLIED`, `PROVISION_VERIFIED`,
`PROVISION_COMPLETED`, `PROVISION_STEP_FAILED`, `PROVISION_COMPENSATED`,
`PROVISION_ROLLED_BACK`, `PROVISION_CANCELLED`, `AGENT_ENROLLED`,
`AGENT_REVOKED`, `AGENT_AUTH_FAILED`.

Los agentes no son usuarios del sistema: `user_id` queda a `null` y su identidad
va en `new_values.executor` como `agent:{id}:{rol}`. Sin eso, un paso ejecutado
por un agente sería indistinguible de uno del scheduler.

### El agujero que se cierra de paso

`MikrotikRouter` pasa a usar `Auditable`. Dos detalles que no son evidentes:

**Se excluyen los campos de salud.** El monitor de conectividad los reescribe
cada 5 minutos con `forceFill()->save()`, que **sí** dispara eventos Eloquent.
Sin excluirlos serían cientos de filas al día por router, y ahogarían los
cambios que importan. Se hace sobrescribiendo `auditIgnoredFields()`, un gancho
nuevo del trait: PHP prohíbe redeclarar una propiedad de trait con otro valor.

**La despromoción del principal se audita a mano.** El hook `saving` usa un
`update()` masivo que no dispara eventos. Cambiar el router principal hace que
todo el sistema opere contra otro equipo; sin este registro explícito no dejaría
rastro. Se escribe en `saved` y no en `saving` porque en un alta el router que
promueve todavía no tiene id.

### Logs

Canal dedicado `provisioning` (`storage/logs/provisioning-*.log`, 30 días).
Recoge cada transición y **las líneas que los agentes adjuntan a sus reportes**,
para que diagnosticar no exija acceso al journald de la máquina remota.

---

## Pruebas

### Automatizadas

`phpunit.xml` declara sqlite pero **no hay `pdo_sqlite`** en esta máquina:

```bash
DB_CONNECTION=mysql DB_DATABASE=ispgestor_test php artisan test
```

| Archivo | Cubre |
|---|---|
| `tests/Feature/Provisioning/AgentAuthenticationTest.php` | Firma válida, alterada, cuerpo manipulado, nonce repetido, desfase de reloj, agente revocado, rol ajeno, enrolamiento |
| `tests/Feature/Provisioning/DeviceDetectionTest.php` | Normalización de MAC, filtro por fabricante, idempotencia, deduplicación por serie |
| `tests/Feature/Provisioning/ProvisioningFlowE2ETest.php` | Flujo completo con los dos agentes simulados por HTTP |
| `tests/Feature/Provisioning/ProvisioningRollbackTest.php` | Compensación, liberación de IP, vencimiento de tareas, cancelación |
| `tests/Feature/Provisioning/ProvisioningAuditTest.php` | Traza completa, ausencia de secretos, y que el monitor no genera ruido |
| `tests/Unit/VpnAddressAllocatorTest.php` | Concurrencia, reciclaje, subred agotada |
| `tests/Unit/DeviceCompatibilityCheckerTest.php` | Umbral 7.1, comparación numérica, etiquetas beta/rc |

Los agentes se simulan **reclamando y reportando por HTTP** contra los endpoints
reales, así que el recorrido pasa por la firma HMAC, el middleware y los
controladores. Lo único que se sustituye es `MikrotikHealthChecker`, que es lo
único que exige un router de verdad al otro lado.

### Runbook con hardware real

#### 1. Montaje — son dos cables

```
NIC de aprovisionamiento del agente ──── puerto LAN del MikroTik (ether2+)
                                          → el agente queda en 192.168.88.0/24

ether1 del MikroTik ──────────────────── red de la oficina (internet)
```

Sin el cable de WAN el router no puede alcanzar el endpoint. El paso 1 lo
detecta y aborta con `ROUTER_NO_WAN` **antes de tocar nada**, en vez de fallar
tres pasos después con un handshake que nunca llega.

#### 2. Comprobar los agentes

```bash
ispgestor-agent selftest          # en ambas máquinas
```

#### 3. Enchufar y observar

```bash
journalctl -u ispgestor-agent -f
```

Debe verse el carrier y, poco después, el anuncio MNDP. En el panel, la sesión
aparece en *MikroTik → Dispositivos* y avanza por el stepper.

#### 4. Contrastar los dos extremos

```bash
# En el VPS
wg show wg0

# En el router (Winbox o consola)
/interface/wireguard/peers/print
/ip/address/print where interface=wg-ispgestor
```

#### 5. Verificar desde dentro del contenedor

```bash
php artisan mikrotik:test
```

#### 6. Comprobar la auditoría

```sql
SELECT operation, created_at FROM audits
WHERE table_name = 'device_provisioning' ORDER BY id;
```

Deben aparecer los seis verbos del camino feliz, en orden.

#### 7. Probar la reversión

Parar el agente `vpn_host` a mitad del flujo:

```bash
systemctl stop ispgestor-agent    # en el VPS, tras el paso 3
```

Debe confirmarse que: la tarea vence (worker `provisioning`), el paso 3 se
revierte en el router, la IP vuelve al pool, no queda fila en
`mikrotik_routers`, y llega la alerta crítica.

---

## Diagnóstico

| Síntoma | Causa habitual |
|---|---|
| No aparece nada al enchufar | La NIC no está en `provisioning_interfaces`, o la MAC no cae en los prefijos admitidos |
| `ROUTEROS_VERSION_UNSUPPORTED` | WireGuard exige RouterOS ≥ 7.1. El equipo no se ha tocado |
| `WIREGUARD_UNAVAILABLE` | Versión suficiente pero sin el paquete. Típico en SMIPS (hAP lite, RB941) con juego de paquetes recortado |
| `ROUTER_NO_WAN` | Falta el cable de `ether1` |
| `NO_HANDSHAKE` | El puerto UDP del servidor no es alcanzable desde la red de la oficina, o el `endpoint_host` es incorrecto |
| `VPN_HOST_UNAVAILABLE` | El agente del hosting no reporta. No se inicia el alta a propósito: empezar sabiendo que el otro extremo no está solo garantizaría un rollback |
| `ROTATED_CREDENTIALS_INVALID` | El usuario dedicado se creó pero no permite entrar. Se detecta antes de cerrar la API, así que se puede revertir |

### `CONTAINER_CANNOT_REACH_ROUTER`

El caso que más despista: `wg show` muestra handshake en ambos extremos, los
pings entre router y servidor funcionan, y aun así el alta falla en el paso 8.

**Casi nunca es la VPN.** El contenedor alcanza `10.77.0.X` por su gateway (el
bridge de Docker) → host → `wg0`. Eso requiere:

```bash
sysctl net.ipv4.ip_forward          # Docker ya lo pone a 1
iptables -L FORWARD -n -v           # que no bloquee docker0 ↔ wg0
```

Si la política de `FORWARD` es `DROP` y no hay regla que permita ese tránsito,
hace falta añadirla:

```bash
iptables -I DOCKER-USER -i docker0 -o wg0 -j ACCEPT
iptables -I DOCKER-USER -i wg0 -o docker0 -j ACCEPT
```

Comprobación rápida desde el contenedor:

```bash
docker exec -it <contenedor> ping -c3 10.77.0.1
```

---

## Ficheros

| Ruta | Contenido |
|---|---|
| `app/Services/Provisioning/DeviceProvisioningOrchestrator.php` | La saga |
| `app/Services/Provisioning/AgentSignature.php` | Firma HMAC del canal |
| `app/Services/Provisioning/VpnAddressAllocator.php` | Reparto de direcciones con cerrojo |
| `app/Services/Provisioning/DeviceCompatibilityChecker.php` | Umbral de RouterOS |
| `app/Services/Provisioning/ProvisioningAuditor.php` | Punto único de escritura en `audits` |
| `app/Services/Provisioning/Vpn/` | `VpnDriver` + `WireGuardDriver` + `TunnelSpec` |
| `app/Http/Middleware/AuthenticateProvisioningAgent.php` | Autenticación del canal |
| `app/Http/Controllers/Agent/` | Endpoints que consumen los agentes |
| `agent/` | El agente Python (ver `agent/README.md`) |

### Ampliar a otra tecnología de túnel

Toda la saga opera contra la interfaz `VpnDriver`, no contra `wg`. Añadir un
`L2tpIpsecDriver` para los equipos que se queden en RouterOS 6 es implementar la
interfaz y cambiar el binding de `ProvisioningServiceProvider`, sin tocar el
orquestador.
