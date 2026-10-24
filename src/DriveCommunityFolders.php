<?php
declare(strict_types=1);

namespace Salvest;

/**
 * El segundo nombre de cada comunidad: el de su carpeta real en Drive ("66-PK GERMANIAS"), que es
 * como la administración la llama en el día a día. communities.official_name guarda el nombre de
 * la columna "Comunidad" del maestro y no hay otra columna de nombre, así que este es el único
 * segundo nombre real disponible sin inventar datos.
 *
 * Se lee una sola vez por pasada (una petición a Drive) y nunca rompe la clasificación: si Drive
 * falla, la IA simplemente no recibe este nombre.
 */
final class DriveCommunityFolders
{
    /** @var array<string,string>|null */
    private ?array $names = null;

    public function __construct(private GoogleDriveClient $drive, private string $rootFolderId, private Database $db) {}

    /** @return array<string,string> código externo → nombre de la carpeta */
    public function names(): array
    {
        if ($this->names !== null) return $this->names;
        $this->names = [];
        try {
            $byNumber = [];
            foreach ($this->drive->children($this->rootFolderId) as $file) {
                if (($file['mimeType'] ?? null) !== 'application/vnd.google-apps.folder') continue;
                if (!preg_match('/^0*(\d+)\D/', trim((string)($file['name'] ?? '')), $match)) continue;
                $byNumber[(int)$match[1]][] = trim((string)$file['name']);
            }
            foreach ($this->db->all('SELECT external_code FROM communities WHERE active=1') as $community) {
                $folders = $byNumber[(int)$community['external_code']] ?? [];
                // Dos carpetas con el mismo número (p. ej. una "ANULADO") = no se sabe cuál es: se omite.
                if (count($folders) === 1) $this->names[(string)$community['external_code']] = $folders[0];
            }
        } catch (\Throwable $error) {
            error_log('drive_community_folders status=failed error='.$error->getMessage());
        }
        return $this->names;
    }
}
