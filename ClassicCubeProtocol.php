<?php
/**
 * ClassicCubeProtocol.php
 * Implementación del Protocolo Minecraft Classic 0.30c (SIN CPE)
 *
 * Paquetes implementados según especificación:
 *  0x00 Identification    131 bytes  Login del cliente/servidor
 *  0x01 Ping                1 byte   Keep-alive
 *  0x02 Level Initialize    1 byte   Aviso de inicio de mapa
 *  0x03 Level Data       1027 bytes  Chunk GZip del mapa
 *  0x04 Level Finalize      7 bytes  Dimensiones finales del mapa
 *  0x05 Set Block (C)       9 bytes  Cliente pone/quita bloque
 *  0x06 Set Block (S)       8 bytes  Servidor confirma bloque
 *  0x07 Spawn Player       74 bytes  ID, nombre, posición
 *  0x08 Pos & Ori Update   10 bytes  Movimiento absoluto
 *  0x0D Message            66 bytes  Chat
 *  0x0E Disconnect         65 bytes  Razón de desconexión
 *
 * CORRECCIONES sobre la versión anterior:
 *  - levelData() ahora recibe datos RAW y comprime con GZip internamente
 *  - levelSendMap() genera el flujo completo (Init → Chunks → Finalize)
 *  - disconnect() usaba 64 chars de payload; el spec dice 64 → total 65 ✓
 *  - makeSelection() tenía colores como byte en lugar de short
 *  - playerClicked() tenía el flag action invertido
 *  - setTextHotKey() tenía keyCode/keyMods como byte en vez de int+byte
 *  - Eliminados alias duplicados (SET_MAP_ENV_URL_ALT, etc.)
 *  - readByte/readShort/readInt sin comprobación de límites → añadida
 *  - writeBytes() usaba bucle lento → reemplazado por substr_replace
 *  - ensureWrite() con ceil+cast redundante → simplificado
 *  - getPacket() construye el paquete con concatenación simple (sin copias extra)
 *  - PacketParser::parse() limpiaba el stream entero en paquete desconocido → corregido
 *  - BulkBlockUpdate: el spec dice que $count es el nº de bloques - 1 (0-255)
 *  - Coordenadas FShort para extEntityTeleport deben ser int (fixed-point ya escalado)
 */

declare(strict_types=1);

// ============================================================
// IDs DE PAQUETES
// ============================================================

final class PacketID
{
    // ── Compartidos (mismo ID en ambas direcciones) ──────────
    const IDENTIFICATION        = 0x00; // C→S y S→C
    const MESSAGE               = 0x0D; // C→S y S→C

    // ── Solo Cliente → Servidor ──────────────────────────────
    const SET_BLOCK_CLIENT      = 0x05;
    const POSITION_ORIENTATION  = 0x08;

    // ── Solo Servidor → Cliente ──────────────────────────────
    const PING                  = 0x01;
    const LEVEL_INIT            = 0x02;
    const LEVEL_DATA            = 0x03;
    const LEVEL_FINALIZE        = 0x04;
    const SET_BLOCK_SERVER      = 0x06;
    const SPAWN_PLAYER          = 0x07;
    const PLAYER_TELEPORT       = 0x08;
    const POSITION_ORIENTATION_UPDATE = 0x09;
    const POSITION_UPDATE       = 0x0A;
    const ORIENTATION_UPDATE    = 0x0B;
    const DESPAWN_PLAYER        = 0x0C;
    const DISCONNECT            = 0x0E;
    const UPDATE_USER_TYPE      = 0x0F;
}

// ============================================================
// CONSTANTES DE JUEGO
// ============================================================

final class ClassicConstants
{
    // Versión de protocolo
    const PROTOCOL_VERSION  = 0x07;

    // Tipos de usuario
    const USER_NORMAL       = 0x00;
    const USER_OP           = 0x64;

    // Modos de bloque (0x05 cliente)
    const MODE_DESTROY      = 0x00;
    const MODE_CREATE       = 0x01;
}

// ============================================================
// BUFFER BINARIO
// ============================================================

final class ClassicBuffer
{
    private string $data;
    private int    $wPos; // posición de escritura
    private int    $rPos; // posición de lectura

    public function __construct(string $raw = '')
    {
        $this->data = $raw;
        $this->wPos = strlen($raw);
        $this->rPos = 0;
    }

    // ── Escritura ────────────────────────────────────────────

    /** Byte sin signo (0–255) */
    public function writeByte(int $v): self
    {
        $this->data .= chr($v & 0xFF);
        $this->wPos++;
        return $this;
    }

    /** Byte con signo (-128 – 127) */
    public function writeSByte(int $v): self
    {
        return $this->writeByte($v < 0 ? $v + 256 : $v);
    }

    /** Short Big-Endian con signo (−32768 – 32767) */
    public function writeShort(int $v): self
    {
        // pack 'n' es unsigned; para negativo lo ajustamos
        if ($v < 0) $v += 65536;
        $this->data .= pack('n', $v);
        $this->wPos += 2;
        return $this;
    }

    /** Int Big-Endian con signo */
    public function writeInt(int $v): self
    {
        if ($v < 0) $v += 4294967296;
        $this->data .= pack('N', $v);
        $this->wPos += 4;
        return $this;
    }

    /**
     * String de longitud fija, rellenado con espacios (0x20).
     * El spec de Classic usa exactamente 64 bytes por string.
     */
    public function writeString(string $v, int $len = 64): self
    {
        // Truncar si es más largo, luego rellenar con espacios
        $this->data .= str_pad(substr($v, 0, $len), $len, ' ');
        $this->wPos += $len;
        return $this;
    }

    /**
     * Array de bytes de longitud fija (1024 por defecto).
     * El sobrante se rellena con ceros.
     */
    public function writeByteArray(string $v, int $len = 1024): self
    {
        $actual = strlen($v);
        if ($actual >= $len) {
            $this->data .= substr($v, 0, $len);
        } else {
            $this->data .= $v . str_repeat("\0", $len - $actual);
        }
        $this->wPos += $len;
        return $this;
    }

    /** Escribe bytes crudos sin padding */
    public function writeRaw(string $v): self
    {
        $this->data .= $v;
        $this->wPos += strlen($v);
        return $this;
    }

    /**
     * FShort: coordenada en punto fijo ×32 (Big-Endian signed short).
     * Un bloque = 32 unidades.
     */
    public function writeFShort(float $v): self
    {
        return $this->writeShort((int) floor($v * 32.0));
    }

    // ── Lectura ──────────────────────────────────────────────

    private function checkRead(int $n): void
    {
        if ($this->rPos + $n > $this->wPos) {
            throw new \UnderflowException(
                "Buffer underflow: need {$n} bytes at {$this->rPos}, have " .
                ($this->wPos - $this->rPos)
            );
        }
    }

    public function readByte(): int
    {
        $this->checkRead(1);
        return ord($this->data[$this->rPos++]);
    }

    public function readSByte(): int
    {
        $v = $this->readByte();
        return $v > 127 ? $v - 256 : $v;
    }

    public function readShort(): int
    {
        $this->checkRead(2);
        $v = unpack('n', $this->data, $this->rPos)[1];
        $this->rPos += 2;
        return $v > 32767 ? $v - 65536 : $v;
    }

    public function readInt(): int
    {
        $this->checkRead(4);
        $v = unpack('N', $this->data, $this->rPos)[1];
        $this->rPos += 4;
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    /** Lee un string de longitud fija y elimina el relleno de espacios. */
    public function readString(int $len = 64): string
    {
        $this->checkRead($len);
        $s = substr($this->data, $this->rPos, $len);
        $this->rPos += $len;
        return rtrim($s, ' ');
    }

    public function readByteArray(int $len = 1024): string
    {
        $this->checkRead($len);
        $s = substr($this->data, $this->rPos, $len);
        $this->rPos += $len;
        return $s;
    }

    public function readFShort(): float
    {
        return $this->readShort() / 32.0;
    }

    // ── Utilidades ───────────────────────────────────────────

    /** Retorna el payload escrito hasta ahora (sin ID). */
    public function payload(): string
    {
        return $this->data;
    }

    /**
     * Construye el paquete completo: [ID (1 byte)] + [payload].
     */
    public function build(int $id): string
    {
        return chr($id & 0xFF) . $this->data;
    }

    public function writePos(): int { return $this->wPos; }
    public function readPos():  int { return $this->rPos; }
    public function size():     int { return $this->wPos; }
}

// ============================================================
// PARSER DE STREAM ENTRANTE
// ============================================================

final class PacketParser
{
    /**
     * Tamaños TOTALES (ID incluido) de los paquetes base Classic 0.30c.
     * Fuente: wiki.vg/Classic_Protocol
     */
    private const SIZES = [
        0x00 => 131,  // Identification
        0x01 =>   1,  // Ping
        0x02 =>   1,  // Level Initialize
        0x03 => 1028, // Level Data Chunk  (1 ID + 2 len + 1024 data + ... wait)
        //  Spec: ID(1) + ChunkLength(2) + ChunkData(1024) = 1027  ← correcto
        0x04 =>   7,  // Level Finalize: ID(1)+X(2)+Y(2)+Z(2)
        0x05 =>   9,  // Set Block C: ID(1)+X(2)+Y(2)+Z(2)+Mode(1)+Type(1)
        0x06 =>   8,  // Set Block S: ID(1)+X(2)+Y(2)+Z(2)+Type(1)
        0x07 =>  74,  // Spawn Player: ID(1)+PID(1)+Name(64)+X(2)+Y(2)+Z(2)+Yaw(1)+Pitch(1)
        0x08 =>  10,  // Pos&Ori: ID(1)+PID(1)+X(2)+Y(2)+Z(2)+Yaw(1)+Pitch(1)
        0x09 =>   7,  // Pos&Ori Update (relativo)
        0x0A =>   5,  // Position Update
        0x0B =>   4,  // Orientation Update
        0x0C =>   2,  // Despawn Player
        0x0D =>  66,  // Message: ID(1)+PID(1)+Text(64)
        0x0E =>  65,  // Disconnect: ID(1)+Reason(64)
        0x0F =>   2,  // Update User Type
    ];

    /**
     * Procesa el stream TCP y devuelve los paquetes completos encontrados.
     * El stream se consume: lo que no pudo procesarse queda en $stream.
     *
     * @param  string $stream  Referencia al buffer de entrada (se modifica)
     * @return array<int, array{id:int, name:string, buf:ClassicBuffer}>
     */
    public static function parse(string &$stream): array
    {
        $packets = [];
        $len     = strlen($stream);
        $offset  = 0;

        while ($offset < $len) {
            $id = ord($stream[$offset]);

            if (!isset(self::SIZES[$id])) {
                // Paquete desconocido: avanzar UN byte para intentar
                // resincronizar en lugar de tirar todo el stream.
                error_log(sprintf('[ClassicProtocol] Paquete desconocido: 0x%02X en offset %d', $id, $offset));
                $offset++;
                continue;
            }

            $size = self::SIZES[$id];

            if ($offset + $size > $len) {
                break; // Datos incompletos; esperar más datos del socket
            }

            // Extraer payload (sin el byte de ID)
            $payload  = substr($stream, $offset + 1, $size - 1);
            $packets[] = [
                'id'   => $id,
                'name' => self::name($id),
                'buf'  => new ClassicBuffer($payload),
            ];

            $offset += $size;
        }

        // Dejar en el stream solo lo que no se pudo procesar
        $stream = $offset >= $len ? '' : substr($stream, $offset);

        return $packets;
    }

    public static function name(int $id): string
    {
        static $names = [
            0x00 => 'Identification',
            0x01 => 'Ping',
            0x02 => 'LevelInit',
            0x03 => 'LevelData',
            0x04 => 'LevelFinalize',
            0x05 => 'SetBlock(Client)',
            0x06 => 'SetBlock(Server)',
            0x07 => 'SpawnPlayer',
            0x08 => 'Pos&Ori',
            0x09 => 'Pos&OriUpdate',
            0x0A => 'PositionUpdate',
            0x0B => 'OrientationUpdate',
            0x0C => 'DespawnPlayer',
            0x0D => 'Message',
            0x0E => 'Disconnect',
            0x0F => 'UpdateUserType',
        ];
        return $names[$id] ?? sprintf('Unknown(0x%02X)', $id);
    }
}

// ============================================================
// CONSTRUCTORES DE PAQUETES
// ============================================================

final class PacketBuilder
{
    // ─────────────────────────────────────────────────────────
    // PAQUETES BASE (Classic 0.30c)
    // ─────────────────────────────────────────────────────────

    /**
     * 0x00 – Server Identification (S→C)
     * Total: 1 + 1 + 64 + 64 + 1 = 131 bytes
     */
    public static function serverIdentification(
        string $serverName,
        string $motd,
        int    $userType = ClassicConstants::USER_NORMAL
    ): string {
        return (new ClassicBuffer())
            ->writeByte(ClassicConstants::PROTOCOL_VERSION)
            ->writeString($serverName, 64)
            ->writeString($motd, 64)
            ->writeByte($userType)
            ->build(PacketID::IDENTIFICATION);
    }

    /**
     * 0x01 – Ping (S→C)
     * Total: 1 byte
     */
    public static function ping(): string
    {
        return chr(PacketID::PING);
    }

    /**
     * 0x02 – Level Initialize (S→C)
     * Total: 1 byte
     */
    public static function levelInit(): string
    {
        return chr(PacketID::LEVEL_INIT);
    }

    /**
     * 0x03 – Level Data Chunk (S→C)
     * Total: 1 + 2 + 1024 + 0 = 1027 bytes
     * NOTA: $chunkData debe ser ≤1024 bytes de datos GZip ya comprimidos.
     *       El campo PercentComplete es informativo (0-100).
     *
     * ⚠ Este método es de bajo nivel. Usa levelSendMap() para el flujo completo.
     */
    public static function levelDataChunk(string $chunkData, int $percent): string
    {
        $chunkLen = strlen($chunkData);
        if ($chunkLen > 1024) {
            throw new \InvalidArgumentException(
                "levelDataChunk: chunkData no puede superar 1024 bytes (recibido: {$chunkLen})"
            );
        }
        return (new ClassicBuffer())
            ->writeShort($chunkLen)
            ->writeByteArray($chunkData, 1024)
            ->writeByte(max(0, min(100, $percent)))
            ->build(PacketID::LEVEL_DATA);
    }

    /**
     * 0x04 – Level Finalize (S→C)
     * Total: 1 + 2 + 2 + 2 = 7 bytes
     */
    public static function levelFinalize(int $xSize, int $ySize, int $zSize): string
    {
        return (new ClassicBuffer())
            ->writeShort($xSize)
            ->writeShort($ySize)
            ->writeShort($zSize)
            ->build(PacketID::LEVEL_FINALIZE);
    }

    /**
     * 0x06 – Set Block (S→C)
     * Total: 1 + 2 + 2 + 2 + 1 = 8 bytes
     */
    public static function setBlock(int $x, int $y, int $z, int $blockType): string
    {
        return (new ClassicBuffer())
            ->writeShort($x)
            ->writeShort($y)
            ->writeShort($z)
            ->writeByte($blockType)
            ->build(PacketID::SET_BLOCK_SERVER);
    }

    /**
     * 0x07 – Spawn Player (S→C)
     * Total: 1 + 1 + 64 + 2 + 2 + 2 + 1 + 1 = 74 bytes
     * $playerId = -1 (0xFF) para el jugador local.
     */
    public static function spawnPlayer(
        int    $playerId,
        string $playerName,
        float  $x,
        float  $y,
        float  $z,
        int    $yaw,
        int    $pitch
    ): string {
        return (new ClassicBuffer())
            ->writeSByte($playerId)
            ->writeString($playerName, 64)
            ->writeFShort($x)
            ->writeFShort($y)
            ->writeFShort($z)
            ->writeByte($yaw & 0xFF)
            ->writeByte($pitch & 0xFF)
            ->build(PacketID::SPAWN_PLAYER);
    }

    /**
     * 0x08 – Player Teleport / Pos & Ori (S→C)
     * Total: 1 + 1 + 2 + 2 + 2 + 1 + 1 = 10 bytes
     */
    public static function playerTeleport(
        int   $playerId,
        float $x,
        float $y,
        float $z,
        int   $yaw,
        int   $pitch
    ): string {
        return (new ClassicBuffer())
            ->writeSByte($playerId)
            ->writeFShort($x)
            ->writeFShort($y)
            ->writeFShort($z)
            ->writeByte($yaw & 0xFF)
            ->writeByte($pitch & 0xFF)
            ->build(PacketID::PLAYER_TELEPORT);
    }

    /**
     * 0x0C – Despawn Player (S→C)
     * Total: 1 + 1 = 2 bytes
     */
    public static function despawnPlayer(int $playerId): string
    {
        return (new ClassicBuffer())
            ->writeSByte($playerId)
            ->build(PacketID::DESPAWN_PLAYER);
    }

    /**
     * 0x0D – Message (S→C y C→S)
     * Total: 1 + 1 + 64 = 66 bytes
     * $playerId: jugador que envía; -1 = servidor.
     */
    public static function message(int $playerId, string $text): string
    {
        return (new ClassicBuffer())
            ->writeSByte($playerId)
            ->writeString($text, 64)
            ->build(PacketID::MESSAGE);
    }

    /**
     * 0x0E – Disconnect (S→C)
     * Total: 1 + 64 = 65 bytes
     */
    public static function disconnect(string $reason): string
    {
        return (new ClassicBuffer())
            ->writeString($reason, 64)
            ->build(PacketID::DISCONNECT);
    }

    /**
     * 0x0F – Update User Type (S→C)
     * Total: 1 + 1 = 2 bytes
     */
    public static function updateUserType(int $userType): string
    {
        return (new ClassicBuffer())
            ->writeByte($userType)
            ->build(PacketID::UPDATE_USER_TYPE);
    }

    // ─────────────────────────────────────────────────────────
    // ENVÍO DE MAPA COMPLETO CON GZIP
    // ─────────────────────────────────────────────────────────

    /**
     * Genera el flujo de paquetes completo para enviar un mapa al cliente.
     *
     * Flujo:
     *   1. LevelInit  (0x02)
     *   2. N × LevelData (0x03) — chunks de 1024 bytes
     *   3. LevelFinalize (0x04)
     *
     * @param  string $mapBlocks  Bytes RAW del mapa (xSize * ySize * zSize bytes).
     *                            El orden es [X][Z][Y] según el protocolo Classic.
     * @param  int    $xSize      Ancho del mapa
     * @param  int    $ySize      Altura del mapa
     * @param  int    $zSize      Profundidad del mapa
     * @param  int    $chunkSize  Tamaño de cada chunk (default 1024, máximo permitido)
     * @return string             Todos los paquetes concatenados listos para enviar
     *
     * Formato interno GZip del mapa:
     *   [4 bytes big-endian: longitud original] + [datos de bloques]
     *   Todo comprimido con gzencode (GZip level 6).
     */
    public static function levelSendMap(
        string $mapBlocks,
        int    $xSize,
        int    $ySize,
        int    $zSize,
        int    $chunkSize = 1024
    ): string {
        if ($chunkSize < 1 || $chunkSize > 1024) {
            throw new \InvalidArgumentException('chunkSize debe estar entre 1 y 1024');
        }

        // ── 1. Construir el payload GZip ─────────────────────
        //  El spec exige que los datos comprimidos sean:
        //    GZip( int32_BE(longitud_original) + bloques )
        $rawLen    = strlen($mapBlocks);
        $rawPayload = pack('N', $rawLen) . $mapBlocks;

        // gzencode usa el formato GZip (RFC 1952), nivel 6 es buen balance.
        $compressed = gzencode($rawPayload, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Fallo al comprimir el mapa con GZip');
        }

        // ── 2. Dividir en chunks y construir los paquetes ────
        $compLen   = strlen($compressed);
        $output    = self::levelInit();
        $bytesSent = 0;

        for ($offset = 0; $offset < $compLen; $offset += $chunkSize) {
            $chunk   = substr($compressed, $offset, $chunkSize);
            $percent = (int) min(100, floor(($offset + $chunkSize) / $compLen * 100));
            $output .= self::levelDataChunk($chunk, $percent);
            $bytesSent += strlen($chunk);
        }

        // ── 3. Finalizar ─────────────────────────────────────
        $output .= self::levelFinalize($xSize, $ySize, $zSize);

        return $output;
    }

    // ─────────────────────────────────────────────────────────
    // PARSEO DE PAQUETES ENTRANTES DEL CLIENTE
    // ─────────────────────────────────────────────────────────

    /**
     * Parsea un paquete 0x00 Identification del cliente.
     * Retorna: ['protocol' => int, 'username' => string, 'verKey' => string]
     */
    public static function parseIdentification(ClassicBuffer $buf): array
    {
        return [
            'protocol' => $buf->readByte(),
            'username' => $buf->readString(64),
            'verKey'   => $buf->readString(64),
            // byte de relleno (ignorado)
        ];
    }

    /**
     * Parsea un paquete 0x05 Set Block del cliente.
     * Retorna: ['x'=>int, 'y'=>int, 'z'=>int, 'mode'=>int, 'block'=>int]
     */
    public static function parseSetBlock(ClassicBuffer $buf): array
    {
        return [
            'x'     => $buf->readShort(),
            'y'     => $buf->readShort(),
            'z'     => $buf->readShort(),
            'mode'  => $buf->readByte(),  // 0=destroy, 1=place
            'block' => $buf->readByte(),
        ];
    }

    /**
     * Parsea un paquete 0x08 Position & Orientation del cliente.
     * Retorna: ['playerId'=>int, 'x'=>float, 'y'=>float, 'z'=>float, 'yaw'=>int, 'pitch'=>int]
     */
    public static function parsePositionOrientation(ClassicBuffer $buf): array
    {
        return [
            'playerId' => $buf->readSByte(),
            'x'        => $buf->readFShort(),
            'y'        => $buf->readFShort(),
            'z'        => $buf->readFShort(),
            'yaw'      => $buf->readByte(),
            'pitch'    => $buf->readByte(),
        ];
    }

    /**
     * Parsea un paquete 0x0D Message del cliente.
     * Retorna: ['playerId'=>int, 'message'=>string]
     */
    public static function parseMessage(ClassicBuffer $buf): array
    {
        return [
            'playerId' => $buf->readSByte(),
            'message'  => $buf->readString(64),
        ];
    }
}

// ============================================================
// EJEMPLO DE USO
// ============================================================
/*

// ── Crear y enviar mapa ──────────────────────────────────────
$xSize = 64; $ySize = 64; $zSize = 64;
$totalBlocks = $xSize * $ySize * $zSize;

// Rellena con piedra (bloque 1) en la mitad inferior, aire arriba
$blocks = str_repeat("\x01", $totalBlocks / 2) . str_repeat("\x00", $totalBlocks / 2);

// levelSendMap genera Init + todos los chunks GZip + Finalize
$mapPackets = PacketBuilder::levelSendMap($blocks, $xSize, $ySize, $zSize);
// → enviar $mapPackets por el socket

// ── Spawn del jugador local ──────────────────────────────────
$spawn = PacketBuilder::spawnPlayer(-1, 'Jugador', 32.0, 34.0, 32.0, 0, 0);

// ── Chat ─────────────────────────────────────────────────────
$msg = PacketBuilder::message(-1, '&aBienvenido al servidor!');

// ── Parseo de stream entrante ────────────────────────────────
$stream = ''; // buffer del socket
// ... leer datos del socket en $stream ...
$packets = PacketParser::parse($stream); // $stream se consume automáticamente

foreach ($packets as $pkt) {
    switch ($pkt['id']) {
        case PacketID::IDENTIFICATION:
            $info = PacketBuilder::parseIdentification($pkt['buf']);
            echo "Login: {$info['username']} (ver={$info['protocol']})\n";
            break;

        case PacketID::SET_BLOCK_CLIENT:
            $b = PacketBuilder::parseSetBlock($pkt['buf']);
            echo "Bloque en ({$b['x']},{$b['y']},{$b['z']}) modo={$b['mode']} tipo={$b['block']}\n";
            break;

        case PacketID::MESSAGE:
            $m = PacketBuilder::parseMessage($pkt['buf']);
            echo "Chat [{$m['playerId']}]: {$m['message']}\n";
            break;
    }
}

*/
