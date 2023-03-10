<?php

namespace Schranz\Search\SEAL\Adapter\RediSearch;

use Redis;
use Schranz\Search\SEAL\Marshaller\Marshaller;
use Schranz\Search\SEAL\Schema\Exception\FieldByPathNotFoundException;
use Schranz\Search\SEAL\Task\SyncTask;
use Schranz\Search\SEAL\Adapter\ConnectionInterface;
use Schranz\Search\SEAL\Schema\Field;
use Schranz\Search\SEAL\Schema\Index;
use Schranz\Search\SEAL\Search\Condition;
use Schranz\Search\SEAL\Search\Result;
use Schranz\Search\SEAL\Search\Search;
use Schranz\Search\SEAL\Task\TaskInterface;

final class RediSearchConnection implements ConnectionInterface
{
    private Marshaller $marshaller;

    public function __construct(
        private readonly Redis $client,
    ) {
        $this->marshaller = new Marshaller();
    }

    public function save(Index $index, array $document, array $options = []): ?TaskInterface
    {
        $identifierField = $index->getIdentifierField();

        /** @var string|null $identifier */
        $identifier = ((string) $document[$identifierField->name]) ?? null;

        $marshalledDocument = $this->marshaller->marshall($index->fields, $document);

        $jsonSet = $this->client->rawCommand(
            'JSON.SET',
            $index->name . ':' . $identifier,
            '$',
            json_encode($marshalledDocument),
        );

        if ($jsonSet === false) {
            throw $this->createRedisLastErrorException();
        }

        if (true !== ($options['return_slow_promise_result'] ?? false)) {
            return null;
        }

        return new SyncTask(null);
    }

    public function delete(Index $index, string $identifier, array $options = []): ?TaskInterface
    {
        $jsonDel = $this->client->rawCommand(
            'JSON.DEL',
            $index->name . ':' . $identifier,
        );

        if ($jsonDel === false) {
            throw $this->createRedisLastErrorException();
        }

        if (true !== ($options['return_slow_promise_result'] ?? false)) {
            return null;
        }

        return new SyncTask(null);
    }

    public function search(Search $search): Result
    {
        // optimized single document query
        if (
            count($search->indexes) === 1
            && count($search->filters) === 1
            && $search->filters[0] instanceof Condition\IdentifierCondition
            && $search->offset === 0
            && $search->limit === 1
        ) {
            $jsonGet = $this->client->rawCommand(
                'JSON.GET',
                $search->indexes[\array_key_first($search->indexes)]->name . ':' . $search->filters[0]->identifier,
            );

            if ($jsonGet === false) {
                return new Result(
                    $this->hitsToDocuments($search->indexes, []),
                    0
                );
            }

            return new Result(
                $this->hitsToDocuments($search->indexes, [\json_decode($jsonGet, true)]),
                1
            );
        }

        if (count($search->indexes) !== 1) {
            throw new \RuntimeException('Solr does not yet support search multiple indexes: https://github.com/schranz-search/schranz-search/issues/86');
        }

        $index = $search->indexes[\array_key_first($search->indexes)];

        $queryText = '';

        $filters = [];
        foreach ($search->filters as $filter) {
            match (true) {
                $filter instanceof Condition\SearchCondition => $filters[] = $this->escape($filter->query),
                $filter instanceof Condition\IdentifierCondition => $filters[] = '@' . $index->getIdentifierField()->name . ':(' . $this->escape($filter->identifier) . ')',
                $filter instanceof Condition\EqualCondition => $filters[] = '@' . $this->getFilterField($search->indexes, $filter->field) . ':(' . $this->escape($filter->value) . ')',
                $filter instanceof Condition\NotEqualCondition => $filters[] = '-@' . $this->getFilterField($search->indexes, $filter->field) . ':(' . $this->escape($filter->value) . ')',
                $filter instanceof Condition\GreaterThanCondition => $filters[] = '@' . $this->getFilterField($search->indexes, $filter->field) . ':[(' . $this->escape($filter->value, true) . ' inf]',
                $filter instanceof Condition\GreaterThanEqualCondition => $filters[] = '@' . $this->getFilterField($search->indexes, $filter->field) . ':[' . $this->escape($filter->value, true) . ' inf]',
                $filter instanceof Condition\LessThanCondition => $filters[] = '@' . $this->getFilterField($search->indexes, $filter->field) . ':[-inf (' . $this->escape($filter->value, true) . ']',
                $filter instanceof Condition\LessThanEqualCondition => $filters[] = '@' . $this->getFilterField($search->indexes, $filter->field) . ':[-inf ' . $this->escape($filter->value, true) . ']',
                default => throw new \LogicException($filter::class . ' filter not implemented.'),
            };
        }

        $query = '*';
        if (count($filters) > 0) {
            $query =implode(' ', $filters);
        }

        $query = $query;

        $arguments = [];
        foreach ($search->sortBys as $field => $direction) {
            $arguments[] = 'SORTBY';
            $arguments[] = $this->escape($field);
            $arguments[] = strtoupper($this->escape($direction));
        }

        if ($search->offset || $search->limit) {
            $arguments[] = 'LIMIT';
            $arguments[] = $search->offset;
            $arguments[] = ($search->limit ?: 10);
        }

        $arguments[] = 'DIALECT';
        $arguments[] = '3';

        $result = $this->client->rawCommand(
            'FT.SEARCH',
            $index->name,
            $query,
            ...$arguments
        );

        if ($result === false) {
            throw $this->createRedisLastErrorException();
        }

        $total = $result[0];

        $documents = [];
        foreach ($result as $item) {
            if (!is_array($item)) {
                continue;
            }

            $previousValue = null;
            foreach ($item as $value) {
                if ($previousValue === '$') {
                    $documents[] = json_decode($value, true)[0];
                }

                $previousValue = $value;
            }
        }

        return new Result(
            $this->hitsToDocuments($search->indexes, $documents),
            $total
        );
    }

    /**
     * @param Index[] $indexes
     * @param iterable<\Solarium\QueryType\Select\Result\Document> $hits
     *
     * @return \Generator<array<string, mixed>>
     */
    private function hitsToDocuments(array $indexes, iterable $hits): \Generator
    {
        $index = $indexes[\array_key_first($indexes)];

        foreach ($hits as $hit) {
            yield $this->marshaller->unmarshall($index->fields, $hit);
        }
    }

    private function getFilterField(array $indexes, string $name): string
    {
        return str_replace('.', '__', $name);
    }

    private function createRedisLastErrorException(): \RuntimeException
    {
        $lastError = $this->client->getLastError();
        $this->client->clearLastError();

        return new \RuntimeException('Redis: ' . $lastError);
    }

    private function escape(string|int|float $text, bool $asNumber = false): string
    {
        if ($asNumber) {
            return (string) ((float) $text);
        }

        return addcslashes($text, ',.<>{}[]"\':;!@#$%^&*()-+=~');
    }
}
