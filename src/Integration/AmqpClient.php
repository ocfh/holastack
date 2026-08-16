<?php
namespace holastack\Integration;

/**
 * 极简 AMQP 0.9.1 发布客户端（RabbitMQ，纯 TCP socket，无第三方依赖）。
 * 仅实现「发布」所需的最小握手：protocol header → connection.start/start-ok →
 * connection.tune/tune-ok → connection.open → channel.open → basic.publish → close。
 * best-effort：连接/发布失败仅记录日志，不阻断上行主流程（与 MqttClient 风格一致）。
 *
 * 参考 ChirpStack 的 application_server.integration.amqp：
 *   url, event_routing_key_template
 */
class AmqpClient
{
    private $socket;
    private $host;
    private $port;
    private $user;
    private $pass;
    private $vhost;
    private $channel = 1;

    public function __construct(string $url, string $vhost = '/')
    {
        $parts = parse_url($url);
        $this->host = $parts['host'] ?? '127.0.0.1';
        $this->port = (int) ($parts['port'] ?? 5672);
        $this->user = urldecode($parts['user'] ?? 'guest');
        $this->pass = urldecode($parts['pass'] ?? 'guest');
        $this->vhost = $vhost;
    }

    private static function encShortStr(string $s): string
    {
        return chr(strlen($s)) . $s; // 1 字节长度 + 内容
    }

    private static function encLongStr(string $s): string
    {
        return pack('N', strlen($s)) . $s; // 4 字节长度 + 内容
    }

    private function frame(int $type, int $channel, string $payload): string
    {
        return chr($type)
            . pack('n', $channel)   // channel
            . pack('N', strlen($payload)) // size
            . $payload
            . "\xce";               // frame-end
    }

    private function writeFrame(int $type, int $channel, string $payload): bool
    {
        if (!$this->socket) {
            return false;
        }
        $fr = $this->frame($type, $channel, $payload);
        $len = strlen($fr);
        $w = 0;
        while ($w < $len) {
            $n = @fwrite($this->socket, substr($fr, $w));
            if ($n === false || $n === 0) {
                return false;
            }
            $w += $n;
        }
        return true;
    }

    private function readn(int $n)
    {
        if (!$this->socket) {
            return false;
        }
        $data = '';
        $got = 0;
        while ($got < $n) {
            $chunk = @fread($this->socket, $n - $got);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $got += strlen($chunk);
        }
        return $data === '' ? false : $data;
    }

    private function readFrame(): ?array
    {
        $hdr = $this->readn(7);
        if ($hdr === false || strlen($hdr) < 7) {
            return null;
        }
        $type = ord($hdr[0]);
        $channel = unpack('n', $hdr[1] . $hdr[2])[1];
        $size = unpack('N', $hdr[3] . $hdr[4] . $hdr[5] . $hdr[6])[1];
        $payload = $size > 0 ? $this->readn($size) : '';
        $this->readn(1); // frame-end
        return ['type' => $type, 'channel' => $channel, 'payload' => $payload];
    }

    /**
     * 读取下一个 method 帧，直到匹配 (class, method)。忽略心跳(8)与其他 method。
     */
    private function readMethod(int $cls, int $mth): ?array
    {
        for ($i = 0; $i < 12; $i++) {
            $fr = $this->readFrame();
            if ($fr === null) {
                return null;
            }
            if ($fr['type'] === 8) {
                continue; // heartbeat
            }
            if ($fr['type'] === 1 && strlen($fr['payload']) >= 4) {
                $c = unpack('n', $fr['payload'][0] . $fr['payload'][1])[1];
                $m = unpack('n', $fr['payload'][2] . $fr['payload'][3])[1];
                if ($c === $cls && $m === $mth) {
                    return $fr;
                }
            }
        }
        return null;
    }

    public function connect(): bool
    {
        $this->socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 3.0);
        if (!$this->socket) {
            return false;
        }
        stream_set_timeout($this->socket, 3);

        // 协议头（非普通帧）
        if (@fwrite($this->socket, "AMQP\x00\x00\x09\x01") === false) {
            return false;
        }

        // connection.start (10.10)
        if ($this->readMethod(10, 10) === null) {
            return false;
        }
        // connection.start-ok (10.11)
        $response = "\0" . $this->user . "\0" . $this->pass;
        $args = pack('N', 0)                          // 空 client-properties 表
            . self::encShortStr('PLAIN')              // mechanism
            . self::encLongStr($response)             // response
            . self::encShortStr('en_US');             // locale
        $this->writeFrame(1, 0, pack('n', 10) . pack('n', 11) . $args);

        // connection.tune (10.30) -> tune-ok (10.31)
        if ($this->readMethod(10, 30) === null) {
            return false;
        }
        $this->writeFrame(1, 0, pack('n', 10) . pack('n', 31) . pack('n', 0) . pack('N', 0) . pack('n', 0));

        // connection.open (10.40) -> open-ok (10.41)
        $this->writeFrame(1, 0, pack('n', 10) . pack('n', 40) . self::encShortStr($this->vhost) . self::encShortStr('') . "\x00");
        if ($this->readMethod(10, 41) === null) {
            return false;
        }

        // channel.open (20.10) -> open-ok (20.11)
        $this->writeFrame(1, $this->channel, pack('n', 20) . pack('n', 10) . self::encShortStr(''));
        if ($this->readMethod(20, 11) === null) {
            return false;
        }
        return true;
    }

    /**
     * 发布一条消息到指定 exchange / routing-key。
     */
    public function publish(string $exchange, string $routingKey, string $message): bool
    {
        if (!$this->socket) {
            return false;
        }
        // basic.publish (60.40)
        $args = pack('n', 0)                         // reserved-1
            . self::encShortStr($exchange)
            . self::encShortStr($routingKey)
            . "\x00" . "\x00";                       // mandatory, immediate
        $this->writeFrame(1, $this->channel, pack('n', 60) . pack('n', 40) . $args);

        // 内容头帧 (type 2)：class 60, weight 0, body size(8), property flags(0=无)
        $header = pack('n', 60) . pack('n', 0) . pack('J', strlen($message)) . pack('n', 0x0000);
        $this->writeFrame(2, $this->channel, $header);

        // 内容体帧 (type 3)
        $this->writeFrame(3, $this->channel, $message);
        return true;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            // channel.close (20.40) + connection.close (10.50)（best-effort，忽略响应）
            @$this->writeFrame(1, $this->channel, pack('n', 20) . pack('n', 40) . pack('n', 0) . pack('n', 0) . self::encShortStr(''));
            @$this->writeFrame(1, 0, pack('n', 10) . pack('n', 50) . pack('n', 0) . pack('n', 0) . self::encShortStr(''));
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
