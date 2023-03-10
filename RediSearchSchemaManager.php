<?php

namespace Schranz\Search\SEAL\Adapter\RediSearch;

use Redis;
use Schranz\Search\SEAL\Task\SyncTask;
use Schranz\Search\SEAL\Adapter\SchemaManagerInterface;
use Schranz\Search\SEAL\Schema\Field;
use Schranz\Search\SEAL\Schema\Index;
use Schranz\Search\SEAL\Task\TaskInterface;
use Solarium\Core\Client\Request;
use Solarium\QueryType\Server\Collections\Result\ClusterStatusResult;

final class RediSearchSchemaManager implements SchemaManagerInterface
{
    public function __construct(
        private readonly Redis $client,
    ) {
    }

    public function existIndex(Index $index): bool
    {
        try {
            $indexInfo = $this->client->rawCommand('FT.INFO', $index->name);
        } catch (\RedisException $e) {
            if ($e->getMessage() !== 'Unknown Index name') {
                throw $e;
            }

            return false;
        }

        return true;
    }

    public function dropIndex(Index $index, array $options = []): ?TaskInterface
    {
        $dropIndex = $this->client->rawCommand('FT.DROPINDEX', $index->name);
        if ($dropIndex === false) {
            throw $this->createRedisLastErrorException();
        }

        if (true !== ($options['return_slow_promise_result'] ?? false)) {
            return null;
        }

        return new SyncTask(null);
    }

    public function createIndex(Index $index, array $options = []): ?TaskInterface
    {
        $indexFields = $this->createJsonFields($index->fields);

        $properties = [];
        foreach ($indexFields as $name => $indexField) {
            $properties[] = $indexField['jsonPath'];
            $properties[] = 'AS';
            $properties[] = str_replace('.', '__', $name);
            $properties[] = $indexField['type'];

            if (!$indexField['searchable'] && !$indexField['filterable']) { // TODO check if we can make something filterable but not searchable
                $properties[] = 'NOINDEX';
            }

            if ($indexField['sortable']) {
                $properties[] = 'SORTABLE';
            }
        }

        $createIndex = $this->client->rawCommand(
            'FT.CREATE',
            $index->name,
            'ON',
            'JSON',
            'PREFIX',
            '1',
            $index->name,
            'SCHEMA',
            ...$properties,
        );

        if ($createIndex === false) {
            throw $this->createRedisLastErrorException();
        }

        if (true !== ($options['return_slow_promise_result'] ?? false)) {
            return null;
        }

        return new SyncTask(null);
    }

    /**
     * @param Field\AbstractField[] $fields
     *
     * @return array<string, mixed>
     */
    private function createJsonFields(array $fields, string $prefix = '', string $jsonPathPrefix = '$.'): array
    {
        $indexFields = [];

        foreach ($fields as $name => $field) {
            $jsonPath = $jsonPathPrefix . $name;
            if ($field->multiple) {
                $jsonPath .= '[*]';
            }
            $name = $prefix . $name;

            // ignore all fields without search, sort or filterable activated
            if (!$field->searchable && !$field->sortable && !$field->filterable) {
                continue;
            }

            match (true) {
                $field instanceof Field\IdentifierField => $indexFields[$name] = [
                    'jsonPath' => $jsonPath,
                    'type' => 'TEXT',
                    'searchable' => $field->searchable,
                    'sortable' => $field->sortable,
                    'filterable' => $field->filterable,
                ],
                $field instanceof Field\TextField, $field instanceof Field\DateTimeField => $indexFields[$name] = [
                    'jsonPath' => $jsonPath,
                    'type' => 'TEXT',
                    'searchable' => $field->searchable,
                    'sortable' => $field->sortable,
                    'filterable' => $field->filterable,
                ],
                $field instanceof Field\BooleanField => $indexFields[$name] = [
                    'jsonPath' => $jsonPath,
                    'type' => 'TAG',
                    'searchable' => $field->searchable,
                    'sortable' => $field->sortable,
                    'filterable' => $field->filterable,
                ],
                $field instanceof Field\IntegerField, $field instanceof Field\FloatField => $indexFields[$name] = [
                    'jsonPath' => $jsonPath,
                    'type' => 'NUMERIC',
                    'searchable' => $field->searchable,
                    'sortable' => $field->sortable,
                    'filterable' => $field->filterable,
                ],
                $field instanceof Field\ObjectField => $indexFields = \array_replace($indexFields, $this->createJsonFields($field->fields, $name . '.', $jsonPath . '.')),
                $field instanceof Field\TypedField => array_map(function($fields, $type) use ($name, &$indexFields, $jsonPath, $field) {
                    $newJsonPath = $jsonPath . '.' . $type;
                    if ($field->multiple) {
                        $newJsonPath = substr($jsonPath, 0, -3) . '.' . $type . '[*]';
                    }

                    $indexFields = \array_replace($indexFields, $this->createJsonFields($fields, $name . '.' . $type . '.', $newJsonPath . '.'));
                }, $field->types, \array_keys($field->types)),
                default => throw new \RuntimeException(sprintf('Field type "%s" is not supported.', get_class($field))),
            };
        }

        return $indexFields;
    }

    private function createRedisLastErrorException(): \RedisException
    {
        $lastError = $this->client->getLastError();
        $this->client->clearLastError();

        return new \RuntimeException('Redis: ' . $lastError);
    }
}
