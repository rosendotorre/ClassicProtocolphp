# ClassicProtocolphp

# ClassicCubeProtocol.php

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Una implementación pura en PHP del protocolo **Minecraft Classic 0.30c** (sin CPE). Creada para ser simple, rápida y sin dependencias externas. Perfecta para crear servidores, proxies o herramientas de administración para ClassiCube y Minecraft Classic.

Inspirado en el excelente [classicube-protocol](https://github.com/rosendotorre/classicube-protocol) para Node.js, pero pensado para el ecosistema PHP.

## 🚀 Características

- ✅ **Protocolo Completo**: Soporte para todos los paquetes base (0x00 a 0x0F).
- 📦 **Sin Dependencias**: Usa solo funciones nativas de PHP (como `gzencode`).
- 🧱 **Manejo de Mapas**: Compresión GZIP automática y división en chunks.
- 🧠 **Parser de Stream Robusto**: Gestiona paquetes incompletos y resincronización.
- 📝 **100% Tipado y Documentado**: PHPDoc en cada método y propiedades tipadas.

## 📦 Instalación

Simplemente copia el archivo `ClassicCubeProtocol.php` a tu proyecto o instálalo vía Composer (si decides publicarlo).

```bash
# Si lo publicas en Packagist, sería algo así:
composer require tu-usuario/classicube-protocol-php
```

## 🧩 Estructura de Clases

```
ClassicCubeProtocol.php
├── PacketID          # Constantes con los IDs de paquetes
├── ClassicConstants  # Versión del protocolo, tipos de usuario
├── ClassicBuffer     # Lector/escritor binario con big-endian
├── PacketParser      # Parseador de streams TCP entrantes
└── PacketBuilder     # Constructores de paquetes (S->C y C->S)
```

## 🔌 Uso Básico

### Servidor: Enviar el Mapa y Spawnear Jugador

```php
require_once 'ClassicCubeProtocol.php';

// 1. Generar un mapa simple (64x64x64, mitad piedra, mitad aire)
$xSize = 64; $ySize = 64; $zSize = 64;
$totalBlocks = $xSize * $ySize * $zSize;
$blocks = str_repeat("\x01", $totalBlocks / 2) . str_repeat("\x00", $totalBlocks / 2);

// 2. Obtener todos los paquetes para enviar el mapa (Init + Chunks + Finalize)
$mapData = PacketBuilder::levelSendMap($blocks, $xSize, $ySize, $zSize);

// 3. (Ejemplo) Enviar por un socket
// fwrite($clientSocket, $mapData);

// 4. Spawnear al jugador local
$spawnSelf = PacketBuilder::spawnPlayer(-1, 'JugadorPHP', 32.0, 35.0, 32.0, 0, 0);
// fwrite($clientSocket, $spawnSelf);
```

### Cliente: Procesar Paquetes Entrantes

```php
// $stream es el buffer del socket (ej: $stream .= fread($socket, 4096))
$packets = PacketParser::parse($stream); // $stream se modifica (se consume)

foreach ($packets as $pkt) {
    switch ($pkt['id']) {
        case PacketID::IDENTIFICATION:
            $info = PacketBuilder::parseIdentification($pkt['buf']);
            echo "Conectado como: {$info['username']}\n";
            break;

        case PacketID::SPAWN_PLAYER:
            // Parsear paquete entrante (0x07) es más complejo y requeriría su propio método.
            // $player = parseSpawnPlayer($pkt['buf']);
            break;

        case PacketID::MESSAGE:
            $msg = PacketBuilder::parseMessage($pkt['buf']);
            echo "Mensaje [{$msg['playerId']}]: {$msg['message']}\n";
            break;
    }
}
```

## 📖 Referencia Rápida de Paquetes

### Paquetes Servidor -> Cliente (`PacketBuilder::`)
| Método | Descripción |
| :--- | :--- |
| `serverIdentification($name, $motd, $userType)` | Handshake inicial (0x00). |
| `ping()` | Keep-alive (0x01). |
| `levelInit()` | Inicio de la transferencia del mapa (0x02). |
| `levelDataChunk($chunkData, $percent)` | Un fragmento del mapa GZip (0x03). |
| `levelFinalize($x, $y, $z)` | Fin del mapa y sus dimensiones (0x04). |
| `levelSendMap($blocks, $x, $y, $z)` | **¡Método de alto nivel!** Genera la secuencia completa de mapa (Init + chunks + finalize). |
| `setBlock($x, $y, $z, $blockType)` | Notificar cambio de bloque (0x06). |
| `spawnPlayer($id, $name, $x, $y, $z, $yaw, $pitch)` | Aparecer un jugador (0x07). |
| `playerTeleport($id, $x, $y, $z, $yaw, $pitch)` | Teletransportar jugador (0x08). |
| `despawnPlayer($id)` | Desaparecer jugador (0x0C). |
| `message($playerId, $text)` | Enviar mensaje de chat (0x0D). |
| `disconnect($reason)` | Desconectar con razón (0x0E). |
| `updateUserType($userType)` | Cambiar permisos (0x0F). |

### Paquetes Cliente -> Servidor (`PacketBuilder::parse*()`)
| Método de Parseo | ID | Descripción |
| :--- | :--- | :--- |
| `parseIdentification($buf)` | 0x00 | El cliente envía su nombre y clave. |
| `parseSetBlock($buf)` | 0x05 | El cliente coloca o rompe un bloque. |
| `parsePositionOrientation($buf)` | 0x08 | El cliente actualiza su posición/rotación. |
| `parseMessage($buf)` | 0x0D | El cliente envía un mensaje de chat. |

## 🧠 Conceptos Clave

### El Flujo del Mapa (GZIP)
El protocolo Classic requiere que el mapa se envíe en un formato muy específico:
1.  Se empaqueta: `[Longitud Original (Int32 BE)] + [Bytes de Bloques]`
2.  Ese paquete se comprime con **GZip**.
3.  El resultado se divide en trozos de **1024 bytes**.
4.  Cada trozo se envía con un paquete `LevelData` (0x03).

Tu método `levelSendMap()` hace **todo esto automáticamente**.

### El Parser de Stream (`PacketParser::parse()`)
Cuando trabajas con sockets TCP, los mensajes pueden llegar fragmentados. Este método:
1.  Acumula datos en `$stream`.
2.  Intenta leer paquetes completos basándose en sus tamaños fijos.
3.  Si un paquete está incompleto, detiene el proceso y espera más datos.
4.  Devuelve los paquetes completos y deja el resto en `$stream`.

```php
$stream .= socket_read($client, 1024);
$packets = PacketParser::parse($stream); // $stream ahora contiene solo los datos incompletos
```

## ⚠️ Notas Importantes

- **Protocolo Base**: Esta implementación se ciñe al protocolo **original de Minecraft Classic 0.30c**. No soporta extensiones CPE (Client Protocol Extensions).
- **Coordenadas FShort**: Recuerda que las coordenadas se envían como `FShort` (entero de 16 bits en punto fijo, donde 1 bloque = 32 unidades). Los métodos `writeFShort` y `readFShort` manejan esto automáticamente.
- **Relleno (Padding)**: Todos los strings se rellenan con espacios (` `) hasta 64 bytes. El buffer lo hace automáticamente.

## 🧪 Ejemplo Completo: Servidor Mínimo

Puedes encontrar un ejemplo de servidor funcional en el directorio
```php
// (Aquí podrías poner un ejemplo corto de un bucle de servidor)
```

## 🤝 Contribuir

¡Las contribuciones son bienvenidas! Por favor, asegúrate de que cualquier cambio:
- Mantiene la compatibilidad con el protocolo base.
- Incluye comentarios PHPDoc.
- Sigue el estilo de código existente.
