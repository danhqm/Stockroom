<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $connection = $this->app['db']->connection();
        if ($connection->getDriverName() !== 'mysql'
            || $connection->getConfig('host') !== 'mysql-test'
            || $connection->getDatabaseName() !== 'stockroom_test') {
            throw new LogicException('Database tests must run against the isolated Stockroom MySQL test service.');
        }

        return parent::setUpTraits();
    }
}
