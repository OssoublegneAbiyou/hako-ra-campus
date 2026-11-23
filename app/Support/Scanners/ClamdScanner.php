<?php

namespace App\Support\Scanners;

/**
 * Client du démon ClamAV (clamd) par le protocole INSTREAM : le contenu du fichier est transmis par la connexion,
 * le démon n'a donc besoin d'aucun accès au stockage privé. L'adresse vient uniquement de la configuration de l'exploitant.
 */
class ClamdScanner implements Scanner
{
    public function __construct(private string $host, private int $port, private int $timeoutSeconds = 30) {}

    public function scan(string $absolutePath): array
    {
        $file = @fopen($absolutePath, 'rb');
        if ($file === false) {
            return ['result' => 'error', 'detail' => 'file_unreadable'];
        }
        $socket = $this->connect();
        if ($socket === null) {
            fclose($file);

            return ['result' => 'error', 'detail' => 'scanner_unreachable'];
        }
        try {
            if (@fwrite($socket, "zINSTREAM\0") === false) {
                return ['result' => 'error', 'detail' => 'scanner_write_failed'];
            }
            while (! feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false || ($chunk !== '' && @fwrite($socket, pack('N', strlen($chunk)).$chunk) === false)) {
                    return ['result' => 'error', 'detail' => 'scanner_write_failed'];
                }
            }
            @fwrite($socket, pack('N', 0));
            $reply = stream_get_contents($socket);

            return self::interpret($reply === false ? '' : $reply);
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    /**
     * Analyse d'un contenu en mémoire, pour le contrôle d'exploitation : rien n'est écrit sur disque.
     *
     * @return array{result: string, detail: string|null}
     */
    public function scanBytes(string $bytes): array
    {
        $socket = $this->connect();
        if ($socket === null) {
            return ['result' => 'error', 'detail' => 'scanner_unreachable'];
        }
        @fwrite($socket, "zINSTREAM\0".pack('N', strlen($bytes)).$bytes.pack('N', 0));
        $reply = stream_get_contents($socket);
        fclose($socket);

        return self::interpret($reply === false ? '' : $reply);
    }

    public function available(): bool
    {
        $socket = $this->connect();
        if ($socket === null) {
            return false;
        }
        @fwrite($socket, "zPING\0");
        $reply = stream_get_contents($socket);
        fclose($socket);

        return is_string($reply) && str_starts_with(trim($reply, "\0\r\n "), 'PONG');
    }

    /**
     * Lecture de la réponse de clamd. Seul « OK » explicite vaut sain ; « FOUND » vaut refusé ; tout le reste vaut erreur.
     *
     * @return array{result: string, detail: string|null}
     */
    public static function interpret(string $reply): array
    {
        $reply = trim($reply, "\0\r\n ");
        if ($reply === 'stream: OK') {
            return ['result' => 'clean', 'detail' => null];
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match) === 1) {
            return ['result' => 'infected', 'detail' => mb_substr((string) preg_replace('/[^A-Za-z0-9._\-]/', '_', $match[1]), 0, 100)];
        }

        return ['result' => 'error', 'detail' => $reply === '' ? 'scanner_no_reply' : 'scanner_error'];
    }

    /**
     * @return resource|null
     */
    private function connect()
    {
        $socket = @stream_socket_client('tcp://'.$this->host.':'.$this->port, $code, $message, 5);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, $this->timeoutSeconds);

        return $socket;
    }
}
