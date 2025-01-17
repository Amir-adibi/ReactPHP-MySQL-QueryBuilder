<?php

namespace Saraf\QB\QueryBuilder\Core;

use React\EventLoop\LoopInterface;
use React\MySQL\Factory;
use React\Promise\PromiseInterface;
use Saraf\QB\QueryBuilder\Exceptions\DBFactoryException;
use Saraf\QB\QueryBuilder\QueryBuilder;

class DBFactory
{
    private const MAX_CONNECTION_COUNT = 1000000000;

    protected Factory $factory;
    protected array $writeConnections = [];
    protected array $readConnections = [];

    /**
     * @throws DBFactoryException
     */
    public function __construct(
        protected LoopInterface $loop,
        protected string        $host,
        protected string        $dbName,
        protected string        $username,
        protected string        $password,
        protected int           $writePort = 6446,
        protected int           $readPort = 6447,
        protected int           $writeInstanceCount = 2,
        protected int           $readInstanceCount = 2,
        protected int           $timeout = 2,
        protected int           $idle = 2,
    )
    {
        $this->factory = new Factory($loop);
        $this->createConnections();
    }

    /**
     * @throws DBFactoryException
     */
    protected function createConnections(): static
    {
        if (count($this->readConnections) > 0 || count($this->writeConnections) > 0)
            throw new DBFactoryException("Connections Already Created");

        for ($i = 0; $i < $this->writeInstanceCount; ++$i) {
            $this->writeConnections[] = new DBWorker(
                $this->factory
                    ->createLazyConnection(
                        sprintf(
                            "%s:%s@%s:%s/%s?idle=%s&timeout=%s",
                            $this->username,
                            urlencode($this->password),
                            $this->host,
                            $this->writePort,
                            $this->dbName,
                            $this->idle,
                            $this->timeout
                        )
                    )
            );
        }

        for ($s = 0; $s < $this->readInstanceCount; ++$s) {
            $this->readConnections[] = new DBWorker(
                $this->factory
                    ->createLazyConnection(
                        sprintf(
                            "%s:%s@%s:%s/%s?idle=%s&timeout=%s",
                            $this->username,
                            urlencode($this->password),
                            $this->host,
                            $this->readPort,
                            $this->dbName,
                            $this->idle,
                            $this->timeout
                        )
                    )
            );
        }
        return $this;
    }

    /**
     * @throws DBFactoryException
     */
    public function getQueryBuilder(): QueryBuilder
    {
        if (count($this->readConnections) == 0 || count($this->writeConnections) == 0)
            throw new DBFactoryException("Connections Not Created");

        return new QueryBuilder($this);
    }

    /**
     * @throws DBFactoryException
     */
    public function query(string $query): PromiseInterface
    {
        $isWrite = true;
        if (str_starts_with(strtolower($query), "select")
            || str_starts_with(strtolower($query), "show")
        ) $isWrite = false;

        $bestConnections = $this->getBestConnection();

        $connection = $isWrite
            ? $this->writeConnections[$bestConnections['write']]
            : $this->readConnections[$bestConnections['read']];

        if (!($connection instanceof DBWorker))
            throw new DBFactoryException("Connections Not Instance of Worker / Restart App");

        return $connection->query($query);
    }

    /**
     * @throws DBFactoryException
     */
    private function getBestConnection(): array
    {
        if (count($this->readConnections) == 0 || count($this->writeConnections) == 0)
            throw new DBFactoryException("Connections Not Created");

        // Best Writer
        $minWriteJobs = self::MAX_CONNECTION_COUNT;
        $minJobsWriterConnection = null;

        foreach ($this->writeConnections as $i => $writeConnection) {
            if ($writeConnection->getJobs() < $minWriteJobs) {
                $minWriteJobs = $writeConnection->getJobs();
                $minJobsWriterConnection = $i;
            }
        }

        // Best Read
        $minReadJobs = self::MAX_CONNECTION_COUNT;
        $minJobsReaderConnection = null;

        foreach ($this->readConnections as $s => $readConnection) {
            if ($readConnection->getJobs() < $minReadJobs) {
                $minReadJobs = $readConnection->getJobs();
                $minJobsReaderConnection = $s;
            }
        }

        return [
            'write' => $minJobsWriterConnection,
            'read' => $minJobsReaderConnection
        ];
    }


}
