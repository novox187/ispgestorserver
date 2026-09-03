<?php

namespace App\Services\Provisioning;

use App\Models\ProvisioningAgent;
use RuntimeException;
use ZipArchive;

/**
 * Arma el instalador desatendido de un agente.
 *
 * El flujo manual —copiar la carpeta `agent/` a la máquina, correr
 * `install.sh`, averiguar la NIC, pegar el token— tiene demasiados pasos para
 * quien solo quiere poner un equipo a funcionar. Aquí se genera un único script
 * que ya lleva dentro el código del agente y su token, de modo que instalar se
 * reduce a ejecutarlo.
 *
 * El paquete viaja **incrustado en base64** y no como una segunda descarga a
 * propósito: así la instalación no depende de que la máquina de la oficina
 * pueda alcanzar ninguna URL más allá de la propia API, ni de que tenga `tar` o
 * `unzip`. Se descomprime con Python, que ya es requisito del agente.
 */
class AgentInstallerBuilder
{
    /**
     * Un solo script para Linux y macOS: comparten rutas, permisos y entorno
     * virtual, y lo único que se separa —systemd frente a launchd— lo resuelve
     * `ispgestor-agent-service` dentro del propio paquete.
     */
    public const UNIX = 'unix';

    /** Windows necesita otro script entero: PowerShell y tarea programada. */
    public const WINDOWS = 'windows';

    private const PLANTILLAS = [
        self::UNIX    => 'resources/provisioning/installer.sh',
        self::WINDOWS => 'resources/provisioning/installer.ps1',
    ];

    /** Ficheros y carpetas del agente que se empaquetan. */
    private const INCLUIR = [
        'ispgestor_agent',
        'install.sh',
        // Capa que traduce «arranca esto» a systemd o a launchd, para que el
        // instalador desatendido sea el mismo script en Linux y en macOS.
        'ispgestor-agent-service',
        'ispgestor-agent.service',
        // La unidad plantilla permite un agente por rol en la misma máquina.
        // `install.sh` la copia siempre, así que si falta aquí la instalación
        // aborta en el `cp` — y solo se vería en la máquina del cliente.
        'ispgestor-agent@.service',
        // launchd no tiene unidades con plantilla: el modelo del plist viaja y
        // se instancia al arrancar cada agente.
        'uk.ironlink.ispgestor-agent.plist',
        'requirements.txt',
        'README.md',
    ];

    public function __construct(private readonly ProvisioningSettings $settings)
    {
    }

    /**
     * Devuelve el script listo para ejecutar.
     *
     * @param string $token Token de enrolamiento en claro. Solo existe en este
     *                      momento: en la fila queda únicamente su hash.
     */
    public function build(ProvisioningAgent $agent, string $token, string $platform = self::UNIX): string
    {
        $plantilla = base_path(self::PLANTILLAS[$platform] ?? self::PLANTILLAS[self::UNIX]);

        if (!is_readable($plantilla)) {
            throw new RuntimeException('No se encuentra la plantilla del instalador.');
        }

        return str_replace(
            ['{{API_URL}}', '{{TOKEN}}', '{{ROLE}}', '{{AGENT_NAME}}', '{{PAYLOAD}}'],
            [
                rtrim((string) config('app.url'), '/'),
                $token,
                $agent->role->value,
                // El nombre solo se imprime; se sanea para que no pueda cerrar
                // la cadena y colar comandos en el script generado.
                $this->sanear($agent->name),
                $this->paquete(),
            ],
            (string) file_get_contents($plantilla)
        );
    }

    /** Nombre del fichero que se ofrece al descargar. */
    public function filename(ProvisioningAgent $agent, string $platform = self::UNIX): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $agent->name) ?: 'agente';
        $extension = $platform === self::WINDOWS ? '.ps1' : '.sh';

        return 'instalar-' . strtolower(trim($slug, '-')) . $extension;
    }

    /** ¿Es una plataforma para la que hay instalador? */
    public static function soporta(string $platform): bool
    {
        return isset(self::PLANTILLAS[$platform]);
    }

    /**
     * El agente entero, comprimido y en base64 troceado en líneas.
     *
     * Se recorta a 76 columnas porque un `base64 -d` alimentado con una única
     * línea de 200 KB es incómodo de diagnosticar si algo se corta por el
     * camino, y porque algunos intermediarios rompen líneas muy largas.
     */
    private function paquete(): string
    {
        $origen = base_path('agent');

        if (!is_dir($origen)) {
            throw new RuntimeException('No se encuentra el código del agente en el servidor.');
        }

        $temporal = tempnam(sys_get_temp_dir(), 'agente-') . '.zip';

        $zip = new ZipArchive();

        if ($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el paquete del agente.');
        }

        try {
            foreach (self::INCLUIR as $entrada) {
                $ruta = $origen . DIRECTORY_SEPARATOR . $entrada;

                if (is_file($ruta)) {
                    $zip->addFile($ruta, $entrada);
                } elseif (is_dir($ruta)) {
                    $this->anadirDirectorio($zip, $ruta, $entrada);
                }
            }

            $zip->close();

            $contenido = (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }

        return chunk_split(base64_encode($contenido), 76, "\n");
    }

    private function anadirDirectorio(ZipArchive $zip, string $ruta, string $prefijo): void
    {
        $zip->addEmptyDir($prefijo);

        /** @var iterable<\SplFileInfo> $items */
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }

            // Los `.pyc` y las cachés no aportan nada y engordan el script.
            if (str_contains($item->getPathname(), '__pycache__')) {
                continue;
            }

            $relativa = $prefijo . '/' . str_replace(
                '\\',
                '/',
                substr($item->getPathname(), strlen($ruta) + 1)
            );

            $zip->addFile($item->getPathname(), $relativa);
        }
    }

    /** Deja solo lo imprimible y sin comillas, para incrustarlo sin riesgo. */
    private function sanear(string $valor): string
    {
        return trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', $valor) ?? '') ?: 'Agente';
    }
}
