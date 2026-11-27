# UDP Client Specification

UDP протокол в нашем движке обязан иметь Header и Protocol, а Request и Response
опциональны. Они задают структуру будущего запроса. Далее будет продемонстрирован
полный пример. За основу взята игра SA:MP, здесь мы шлём UDP на сервер запущенный
и получаем инфу.

## Пример запроса без реквеста на SA:MP протокол:
```php 
$header = Datagram::create(function($datagram) {
            $datagram->constant('signature', 'SAMP');
            $datagram->bytes('ip', 4);
            $datagram->uint16('port');
            $datagram->uint8('opcode');
        });
$request = null;
$response = Datagram::create(function($datagram) {
            $datagram->bool('password');
            $datagram->uint16('players');
            $datagram->uint16('max_players');
            $datagram->string('hostname', length: 'uint32');
            $datagram->string('gamemode', length: 'uint32');
            $datagram->string('language', length: 'uint32');
        });  
$packet = Packet::define('info')
            ->key(0x69)
            ->request($request)
            ->response($response);
$protocol = Protocol::define('samp')
            ->transport('udp')
            ->endian('little')
            ->header($header)
            ->discriminate('opcode');          
$sampServer = Protocol::request(
            address: '185.169.134.239:7777', 
            schema: 'samp::info', 
            payload: [],
            timeout: 3000,
            retries: 1,
          ]);
echo $sampServer->hostname;
```

## Архитектурный подход

Не всегда удобно строить такую последовательность из переменных, поэтому 
фреймворк поддерживает сегрегацию по классам с удобством использования.

### SampHeader

```php 
class SampHeader extends BinarySchema
{
    public function define(): void
    {
        Datagram::create(function($blueprint) {
            $blueprint->constant('signature', 'SAMP');
            $blueprint->bytes('ip', 4);
            $blueprint->uint16('port');
            $blueprint->uint8('opcode');
        });
    }
}
```

#### ServerInfoRequestDatagram

```php 
class ServerInfoRequestDatagram extends BinarySchema
{
    public function define(): void
    {
    }
}
```

#### ServerInfoResponseDatagram

```php 
class ServerInfoResponseDatagram extends BinarySchema
{
    public function define(): void
    {
        Datagram::create(function($datagram) {
            $datagram->uint8('password');
            $datagram->uint16('players');
            $datagram->uint16('max_players');
            $datagram->string('hostname', length: 'uint32');
            $datagram->string('gamemode', length: 'uint32');
            $datagram->string('language', length: 'uint32');
        });
    }
}
```

#### SampProtocol

```php 
class SampProtocol extends Protocol
{
    public function define(): void
    {
        $this->protocol->define('udp')
            ->endian('little')
            ->header(SampHeader::class)
            ->discriminate('opcode');
    }
    
    public function packets()
    {
        $this->protocol->packet->define('info', 0x69, fn ($p) => $p
            ->request(ServerInfoRequestDatagram::class)
            ->response(ServerInfoResponseDatagram::class));
    }
}
```

#### Использование

```php 
echo SampProtocol::request('185.169.134.239:7777', 'info')->hostname;
```