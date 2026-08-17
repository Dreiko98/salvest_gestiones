<?php
declare(strict_types=1);

namespace Salvest;

final class ImapClient
{
    /** @var resource|null */ private $socket = null;
    private int $tag = 0;
    private string $delimiter = '/';
    private string $uidValidity = '';
    /** @var list<string> */ private array $capabilities = [];

    public function __construct(private string $host, private int $port, private string $username,
        private string $password, private string $folder = 'INBOX', private int $timeout = 30) {}

    public function connect(): void
    {
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true]]);
        $socket = @stream_socket_client("ssl://{$this->host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) throw new \RuntimeException("No se pudo conectar por IMAP: $error ($errno)");
        stream_set_timeout($socket, $this->timeout); $this->socket = $socket;
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK')) throw new \RuntimeException('El servidor IMAP no aceptó la conexión');
        $this->command('LOGIN '.$this->quote($this->username).' '.$this->quote($this->password));
        $capability = $this->command('CAPABILITY');
        if (preg_match('/\* CAPABILITY (.+)/i', $capability, $match)) $this->capabilities = preg_split('/\s+/', strtoupper(trim($match[1]))) ?: [];
        $selected = $this->command('SELECT '.$this->mailbox($this->folder));
        if (!preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $selected, $match)) throw new \RuntimeException('IONOS no devolvió UIDVALIDITY');
        $this->uidValidity = $match[1];
        $listed = $this->command('LIST "" ""');
        if (preg_match('/\([^)]*\)\s+(?:"([^"]+)"|NIL)\s+/i', $listed, $match) && ($match[1] ?? '') !== '') $this->delimiter = $match[1];
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            try { $this->command('LOGOUT'); } catch (\Throwable) {}
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct() { $this->close(); }
    public function uidValidity(): string { return $this->uidValidity; }
    public function delimiter(): string { return $this->delimiter; }

    /** @return list<string> */
    public function listUids(): array
    {
        $response = $this->command('UID SEARCH ALL');
        if (!preg_match('/\* SEARCH([^\r\n]*)/i', $response, $match)) return [];
        return array_values(array_filter(preg_split('/\s+/', trim($match[1])) ?: []));
    }

    public function fetch(string $uid): string
    {
        $tag = $this->nextTag(); $this->write("$tag UID FETCH $uid (BODY.PEEK[])\r\n");
        $literal = null; $response = '';
        while (true) {
            $line = $this->line(); $response .= $line."\r\n";
            if (preg_match('/\{(\d+)\}$/', $line, $match)) {
                $literal = $this->readBytes((int)$match[1]);
            }
            if (str_starts_with($line, "$tag ")) {
                if (!str_starts_with($line, "$tag OK")) throw new \RuntimeException("Falló UID FETCH: $line");
                break;
            }
        }
        if ($literal === null) throw new \RuntimeException('UID FETCH no devolvió el mensaje');
        return $literal;
    }

    public function markSeen(string $uid): void { $this->command("UID STORE $uid +FLAGS (\\Seen)"); }

    public function move(string $uid, string $destination): void
    {
        $wire = $this->ensureFolder($destination);
        if (in_array('MOVE', $this->capabilities, true)) {
            try { $this->command("UID MOVE $uid $wire"); return; } catch (\Throwable) {}
        }
        $this->command("UID COPY $uid $wire");
        $this->command("UID STORE $uid +FLAGS (\\Deleted)");
        $this->command('EXPUNGE');
    }

    public function ensureFolder(string $destination): string
    {
        $parts = array_values(array_filter(preg_split('~[/\\\\]+~', $destination) ?: []));
        if (!$parts) throw new \InvalidArgumentException('Carpeta IMAP vacía');
        $path = '';
        foreach ($parts as $part) {
            $part = mb_substr(trim((string)preg_replace('/[\x00-\x1F]+/', ' ', $part)), 0, 100);
            $path = $path === '' ? $part : $path.$this->delimiter.$part;
            try { $this->command('CREATE '.$this->mailbox($path)); }
            catch (\RuntimeException $error) {
                if (!str_contains($error->getMessage(), ' ALREADYEXISTS') && !str_contains($error->getMessage(), ' NO ')) throw $error;
            }
        }
        return $this->mailbox($path);
    }

    private function command(string $command): string
    {
        $tag = $this->nextTag(); $this->write("$tag $command\r\n"); $response = '';
        while (true) {
            $line = $this->line(); $response .= $line."\r\n";
            if (str_starts_with($line, "$tag ")) {
                if (!str_starts_with($line, "$tag OK")) throw new \RuntimeException("IMAP rechazó $command: $line");
                return $response;
            }
        }
    }

    private function nextTag(): string { return 'A'.str_pad((string)++$this->tag, 4, '0', STR_PAD_LEFT); }
    private function quote(string $value): string { return '"'.addcslashes($value, "\\\"").'"'; }
    private function mailbox(string $value): string { return $this->quote(self::modifiedUtf7($value)); }

    public static function modifiedUtf7(string $value): string
    {
        $output = ''; $buffer = '';
        $flush = static function () use (&$buffer, &$output): void {
            if ($buffer === '') return;
            $utf16 = mb_convert_encoding($buffer, 'UTF-16BE', 'UTF-8');
            $output .= '&'.rtrim(str_replace('/', ',', base64_encode($utf16)), '=').'-'; $buffer = '';
        };
        foreach (mb_str_split($value) as $char) {
            $code = mb_ord($char);
            if ($code >= 0x20 && $code <= 0x7e) { $flush(); $output .= $char === '&' ? '&-' : $char; }
            else $buffer .= $char;
        }
        $flush(); return $output;
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || fwrite($this->socket, $data) === false) throw new \RuntimeException('Falló la escritura IMAP');
    }

    private function line(): string
    {
        if (!is_resource($this->socket)) throw new \RuntimeException('IMAP no conectado');
        $line = fgets($this->socket);
        if ($line === false) throw new \RuntimeException('La conexión IMAP se cerró inesperadamente');
        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') throw new \RuntimeException('Mensaje IMAP truncado');
            $data .= $chunk;
        }
        return $data;
    }
}
