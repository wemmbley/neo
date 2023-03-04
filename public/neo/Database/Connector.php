<?php

declare(strict_types=1);

namespace App\Neo\Database;

use App\Neo\Helpers\Primitives\Arr;
use PDO;

class Connector
{
    // @todo make it configuration at database config instead of hardcode
    protected array $availableConnections = ['mysql', 'sqlite', 'pgsql'];

    protected string $connection = '';
    protected string $host = '127.0.0.1';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected string $port = '3306';

    public function __construct(string $connection)
    {
        if ( ! Arr::has($this->availableConnections, $connection))
            throw new \Exception('Connection not found');

        $this->connection = $connection;

        return $this;
    }

    public function withHost(string $host): Connector
    {
        $this->host = $host;

        return $this;
    }

    public function withUser(string $user): Connector
    {
        $this->user = $user;

        return $this;
    }

    public function withPort(string $port): Connector
    {
        $this->port = $port;

        return $this;
    }

    public function withPassword(string $password): Connector
    {
        $this->password = $password;

        return $this;
    }

    public function withDatabase(string $dbName): Connector
    {
        $this->database = $dbName;

        return $this;
    }

    public function get(): PDO
    {
        return new PDO($this->connection
            . ':host=' . $this->host
            . ';dbname=' . $this->database
            . ';port=' . $this->port,
            $this->user,
            $this->password);
    }
}