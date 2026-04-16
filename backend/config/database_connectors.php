<?php

use App\Services\Database\Connectors\MongoConnector;
use App\Services\Database\Connectors\MysqlConnector;
use App\Services\Database\Connectors\PostgresConnector;
use App\Services\Database\Connectors\SqlServerConnector;

return [
    'drivers' => [
        'pgsql' => [
            'label' => 'PostgreSQL',
            'connector' => PostgresConnector::class,
            'default_port' => 5432,
            'resource_type' => 'table',
        ],
        'mysql' => [
            'label' => 'MySQL',
            'connector' => MysqlConnector::class,
            'default_port' => 3306,
            'resource_type' => 'table',
        ],
        'mariadb' => [
            'label' => 'MariaDB',
            'connector' => MysqlConnector::class,
            'default_port' => 3306,
            'resource_type' => 'table',
        ],
        'sqlsrv' => [
            'label' => 'MS SQL',
            'connector' => SqlServerConnector::class,
            'default_port' => 1433,
            'resource_type' => 'table',
        ],
        'mongodb' => [
            'label' => 'MongoDB',
            'connector' => MongoConnector::class,
            'default_port' => 27017,
            'resource_type' => 'collection',
        ],
    ],
    'custom_option' => [
        'key' => '__custom__',
        'label' => 'Other / Custom',
    ],
];
