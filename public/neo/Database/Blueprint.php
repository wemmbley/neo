<?php

declare(strict_types=1);

namespace App\Neo\Database;

use App\Neo\Helpers\Regex;

class Blueprint
{
    protected string $sql = '';

    public function __construct(string $tableName)
    {
        $this->sql .= 'CREATE TABLE IF NOT EXISTS ' . $tableName . '(';
    }

    public function id()
    {
        $this->sql .= 'id int auto_increment, PRIMARY KEY (id),';
    }

    public function string(string $name)
    {
        $this->sql .= $name . ' varchar(255),';
    }

    public function int(string $name)
    {
        $this->sql .= $name . ' int,';
    }

    public function text(string $name)
    {
        $this->sql .= $name . ' text,';
    }

    public function get()
    {
        $this->sql .= ');';

        $this->deleteLastComma();

        return $this->sql;
    }

    protected function deleteLastComma()
    {
        $this->sql = Regex::replace('/(,)\);/')->string($this->sql)->to(');');
    }
}