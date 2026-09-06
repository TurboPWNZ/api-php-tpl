<?php

namespace Api;

use PDO;

abstract class Migration
{
    abstract public function up(PDO $pdo): void;
    abstract public function down(PDO $pdo): void;
}
