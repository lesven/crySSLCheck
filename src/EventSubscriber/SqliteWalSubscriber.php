<?php

namespace App\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Events;

class SqliteWalSubscriber implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [Events::postConnect];
    }

    public function postConnect(object $event): void
    {
        if (!method_exists($event, 'getConnection')) {
            return;
        }

        /** @var Connection $connection */
        $connection = $event->getConnection();

        if ($connection->getDatabasePlatform()->getName() !== 'sqlite') {
            return;
        }

        $connection->executeStatement('PRAGMA journal_mode=WAL;');
        $connection->executeStatement('PRAGMA busy_timeout=5000;');
    }
}
