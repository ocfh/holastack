<?php
namespace holastack\Integration;






class MqttClient
{
    private $socket;
    private $host;
    private $port;
    private $clientId;
    private $username;
    private $password;
    private $tls;
    private $verifyPeer;
    private $packetId = 0;

    public function __construct(string $server, string $username = '', string $password = '', string $clientId = '', bool $tls = false, bool $verifyPeer = true)
    {
        $parts = parse_url($server);
        $scheme = strtolower($parts['scheme'] ?? 'tcp');
        $this->host = $parts['host'] ?? '127.0.0.1';
        // 带 ssl/tls/mqtts scheme 时若未显式给端口，默认回退到 8883
        $defaultPort = in_array($scheme, ['ssl', 'tls', 'mqtts'], true) ? 8883 : 1883;
        $this->port = (int) ($parts['port'] ?? $defaultPort);
        // scheme 显式写了 ssl/tls/mqtts，或调用方强制 tls=true，都走 TLS
        $this->tls = $tls || in_array($scheme, ['ssl', 'tls', 'mqtts'], true);
        $this->verifyPeer = $verifyPeer;
        $this->username = $username;
        $this->password = $password;
        $this->clientId = $clientId !== '' ? $clientId : ('holastack-' . bin2hex(random_bytes(4)));
    }

    private static function encodeRemainingLength(int $len): string
    {
        $bytes = '';
        do {
            $b = $len % 128;
            $len = (int) ($len / 128);
            if ($len > 0) {
                $b |= 0x80;
            }
            $bytes .= chr($b);
        } while ($len > 0);
        return $bytes;
    }

    private static function encodeString(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    public function connect(): bool
    {
        $transport = $this->tls ? 'ssl' : 'tcp';
        if ($this->tls) {
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer'       => $this->verifyPeer,
                    'verify_peer_name'  => $this->verifyPeer,
                    'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                ],
            ]);
            $this->socket = @stream_socket_client(
                "$transport://{$this->host}:{$this->port}",
                $errno, $errstr, 3.0, STREAM_CLIENT_CONNECT, $ctx
            );
        } else {
            $this->socket = @stream_socket_client("$transport://{$this->host}:{$this->port}", $errno, $errstr, 3.0);
        }
        if (!$this->socket) {
            return false;
        }
        stream_set_timeout($this->socket, 3);

        $flags = 0x02; 

        if ($this->username !== '') {
            $flags |= 0x80;
        }
        if ($this->password !== '') {
            $flags |= 0x40;
        }
        $payload = self::encodeString($this->clientId);
        if ($this->username !== '') {
            $payload .= self::encodeString($this->username);
        }
        if ($this->password !== '') {
            $payload .= self::encodeString($this->password);
        }
        $variable = self::encodeString('MQTT') . "\x04" . chr($flags) . pack('n', 60);
        $packet = "\x10" . self::encodeRemainingLength(strlen($variable) + strlen($payload)) . $variable . $payload;
        $this->write($packet);

        

        $ack = $this->read(4);
        return $ack !== false;
    }

    public function publish(string $topic, string $message, int $qos = 0): bool
    {
        if (!$this->socket) {
            return false;
        }
        // 只实现 QoS 0/1；QoS2 请求按 QoS1 处理（工业上行场景够用，避免半实现的 QoS2 状态机）
        $qos = $qos > 0 ? 1 : 0;
        $variable = self::encodeString($topic);
        if ($qos === 1) {
            $this->packetId = $this->packetId >= 65535 ? 1 : $this->packetId + 1;
            $variable .= pack('n', $this->packetId);
        }
        $variable .= $message;
        // PUBLISH 固定头：0x30 | qos<<1（dup=0, retain=0）
        $header = chr(0x30 | ($qos << 1));
        $packet = $header . self::encodeRemainingLength(strlen($variable)) . $variable;
        if (!$this->write($packet)) {
            return false;
        }
        if ($qos === 1) {
            // 等待 PUBACK（type=4）：固定头 1B + 剩余长度 1B，标准 PUBACK 剩余长度恒为 2
            $ack = $this->read(2);
            if ($ack === false || strlen($ack) < 2 || (ord($ack[0]) >> 4) !== 4) {
                return false;
            }
            $rl = ord($ack[1]);
            if ($rl > 0) {
                $this->read($rl);
            }
        }
        return true;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            $this->write("\xe0\x00");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function write(string $data): bool
    {
        if (!$this->socket) {
            return false;
        }
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                return false;
            }
            $written += $n;
        }
        return true;
    }

    private function read(int $bytes)
    {
        if (!$this->socket) {
            return false;
        }
        $data = '';
        $got = 0;
        while ($got < $bytes) {
            $chunk = @fread($this->socket, $bytes - $got);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $got += strlen($chunk);
        }
        return $data === '' ? false : $data;
    }
}
