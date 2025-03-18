<?php

namespace SilverStripe\SearchServiceElastic\Service;

use Elastic\Elasticsearch\Client;
use Exception;
use InvalidArgumentException;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\SearchService\Exception\IndexConfigurationException;
use SilverStripe\SearchService\Exception\IndexingServiceException;
use SilverStripe\SearchService\Interfaces\BatchDocumentRemovalInterface;
use SilverStripe\SearchService\Interfaces\DocumentInterface;
use SilverStripe\SearchService\Interfaces\IndexingInterface;
use SilverStripe\SearchService\Schema\Field;
use SilverStripe\SearchService\Service\DocumentBuilder;
use SilverStripe\SearchService\Service\IndexConfiguration;
use SilverStripe\SearchService\Service\Traits\ConfigurationAware;

class ElasticsearchService implements IndexingInterface, BatchDocumentRemovalInterface
{

    use Configurable;
    use ConfigurationAware;
    use Injectable;

    private const DEFAULT_FIELD_TYPE = 'text';

    private Client $client;

    private DocumentBuilder $builder;

    private static int $max_document_size = 102400;

    public function __construct(Client $client, IndexConfiguration $configuration, DocumentBuilder $exporter)
    {
        $this->setClient($client);
        $this->setConfiguration($configuration);
        $this->setBuilder($exporter);
    }

    /**
     * To ensure we get a unique name per environment
     *
     * @param string $indexName
     * @return string
     */
    public function environmentizeIndex(string $indexName): string
    {
        $variant = $this->getConfiguration()->getIndexVariant();
        return ($variant) ? sprintf('%s_%s', $variant, $indexName) : $indexName;
    }

    public function getExternalURL(): ?string
    {
        return null;
    }

    public function getExternalURLDescription(): ?string
    {
        return null;
    }

    public function getDocumentationURL(): ?string
    {
        return 'https://www.elastic.co/elasticsearch/';
    }

    /**
     * @param DocumentInterface $document
     * @return string|null
     * @throws IndexingServiceException
     */
    public function addDocument(DocumentInterface $document): ?string
    {
        $id = $document->getIdentifier();
        $source = $document->getSource();
        $index = $this->environmentizeIndex($source);

        $this->findOrMakeIndex($index);

        try {
            $params = [
                'index' => $index,
                'id' => $id,
                'body' => $this->getBuilder()->normaliseDocument($document),
            ];

            $response = $this->getClient()->index($params);
            return $response['_id'] ?? null;
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to index document with ID "%s": %s',
                $id,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param array $documents
     * @return array
     * @throws IndexingServiceException
     */
    public function addDocuments(array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        $grouped = $this->groupDocumentsByIndex($documents);
        $results = [];

        foreach ($grouped as $index => $indexDocuments) {
            $this->findOrMakeIndex($index);

            $bulkParams = ['body' => []];

            foreach ($indexDocuments as $document) {
                $id = $document->getIdentifier();
                
                // Add action
                $bulkParams['body'][] = [
                    'index' => [
                        '_index' => $index,
                        '_id' => $id,
                    ]
                ];
                
                // Add document body
                $bulkParams['body'][] = $this->getBuilder()->normaliseDocument($document);
            }

            try {
                $response = $this->getClient()->bulk($bulkParams);
                
                if ($response['errors']) {
                    $errors = [];
                    foreach ($response['items'] as $item) {
                        if (isset($item['index']['error'])) {
                            $errors[] = $item['index']['error']['reason'];
                        }
                    }
                    
                    throw new IndexingServiceException(sprintf(
                        'Bulk indexing failed with errors: %s',
                        implode(', ', $errors)
                    ));
                }

                foreach ($response['items'] as $item) {
                    if (isset($item['index']['_id'])) {
                        $results[] = $item['index']['_id'];
                    }
                }
            } catch (Exception $e) {
                throw new IndexingServiceException(sprintf(
                    'Failed to bulk index documents: %s',
                    $e->getMessage()
                ));
            }
        }

        return $results;
    }

    /**
     * @param DocumentInterface $document
     * @return string|null
     * @throws IndexingServiceException
     */
    public function removeDocument(DocumentInterface $document): ?string
    {
        $id = $document->getIdentifier();
        $source = $document->getSource();
        $index = $this->environmentizeIndex($source);

        try {
            $params = [
                'index' => $index,
                'id' => $id,
            ];

            $response = $this->getClient()->delete($params);
            return $response['_id'] ?? null;
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to remove document with ID "%s": %s',
                $id,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param array $documents
     * @return array
     * @throws IndexingServiceException
     */
    public function removeDocuments(array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        $grouped = $this->groupDocumentsByIndex($documents);
        $results = [];

        foreach ($grouped as $index => $indexDocuments) {
            $bulkParams = ['body' => []];

            foreach ($indexDocuments as $document) {
                $id = $document->getIdentifier();
                
                // Add delete action
                $bulkParams['body'][] = [
                    'delete' => [
                        '_index' => $index,
                        '_id' => $id,
                    ]
                ];
            }

            try {
                $response = $this->getClient()->bulk($bulkParams);
                
                if ($response['errors']) {
                    $errors = [];
                    foreach ($response['items'] as $item) {
                        if (isset($item['delete']['error'])) {
                            $errors[] = $item['delete']['error']['reason'];
                        }
                    }
                    
                    throw new IndexingServiceException(sprintf(
                        'Bulk delete failed with errors: %s',
                        implode(', ', $errors)
                    ));
                }

                foreach ($response['items'] as $item) {
                    if (isset($item['delete']['_id'])) {
                        $results[] = $item['delete']['_id'];
                    }
                }
            } catch (Exception $e) {
                throw new IndexingServiceException(sprintf(
                    'Failed to bulk delete documents: %s',
                    $e->getMessage()
                ));
            }
        }

        return $results;
    }

    /**
     * @param string $indexName
     * @return int
     * @throws IndexingServiceException
     */
    public function removeAllDocuments(string $indexName): int
    {
        $index = $this->environmentizeIndex($indexName);

        try {
            // Check if index exists
            $exists = $this->getClient()->indices()->exists(['index' => $index]);
            
            if (!$exists) {
                return 0;
            }

            // Delete by query to remove all documents
            $params = [
                'index' => $index,
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass()
                    ]
                ]
            ];

            $response = $this->getClient()->deleteByQuery($params);
            return $response['deleted'] ?? 0;
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to remove all documents from index "%s": %s',
                $index,
                $e->getMessage()
            ));
        }
    }

    /**
     * @return int
     */
    public function getMaxDocumentSize(): int
    {
        return self::$max_document_size;
    }

    /**
     * @param string $id
     * @return DocumentInterface|null
     * @throws IndexingServiceException
     */
    public function getDocument(string $id): ?DocumentInterface
    {
        try {
            // We need to search across all indices to find the document
            $params = [
                'body' => [
                    'query' => [
                        'term' => [
                            '_id' => $id
                        ]
                    ]
                ]
            ];

            $response = $this->getClient()->search($params);
            $hits = $response['hits']['hits'] ?? [];
            
            if (empty($hits)) {
                return null;
            }
            
            $hit = $hits[0];
            $source = $hit['_index'];
            $cleanSource = $this->removeEnvironmentFromIndex($source);
            
            return $this->getBuilder()->createFromArray($hit['_id'], $cleanSource, $hit['_source']);
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to get document with ID "%s": %s',
                $id,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param array $ids
     * @return array
     * @throws IndexingServiceException
     */
    public function getDocuments(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        try {
            // Search across all indices to find the documents
            $params = [
                'body' => [
                    'query' => [
                        'terms' => [
                            '_id' => $ids
                        ]
                    ],
                    'size' => count($ids)
                ]
            ];

            $response = $this->getClient()->search($params);
            $hits = $response['hits']['hits'] ?? [];
            
            if (empty($hits)) {
                return [];
            }
            
            $documents = [];
            foreach ($hits as $hit) {
                $source = $hit['_index'];
                $cleanSource = $this->removeEnvironmentFromIndex($source);
                $documents[] = $this->getBuilder()->createFromArray($hit['_id'], $cleanSource, $hit['_source']);
            }
            
            return $documents;
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to get documents: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * @param string $indexName
     * @param int|null $pageSize
     * @param int $currentPage
     * @return array
     * @throws IndexingServiceException
     */
    public function listDocuments(string $indexName, ?int $pageSize = null, int $currentPage = 0): array
    {
        $index = $this->environmentizeIndex($indexName);

        try {
            // Check if index exists
            $exists = $this->getClient()->indices()->exists(['index' => $index]);
            
            if (!$exists) {
                return [
                    'documents' => [],
                    'meta' => [
                        'page' => [
                            'current' => $currentPage,
                            'total_pages' => 0,
                            'total_results' => 0,
                            'size' => $pageSize ?? 0
                        ]
                    ]
                ];
            }

            $size = $pageSize ?? 10;
            $from = $currentPage * $size;
            
            $params = [
                'index' => $index,
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass()
                    ],
                    'from' => $from,
                    'size' => $size
                ]
            ];

            $response = $this->getClient()->search($params);
            $total = $response['hits']['total']['value'] ?? 0;
            $hits = $response['hits']['hits'] ?? [];
            
            $documents = [];
            foreach ($hits as $hit) {
                $documents[] = $this->getBuilder()->createFromArray($hit['_id'], $indexName, $hit['_source']);
            }
            
            return [
                'documents' => $documents,
                'meta' => [
                    'page' => [
                        'current' => $currentPage,
                        'total_pages' => ceil($total / $size),
                        'total_results' => $total,
                        'size' => $size
                    ]
                ]
            ];
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to list documents from index "%s": %s',
                $index,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param string $indexName
     * @return int
     * @throws IndexingServiceException
     */
    public function getDocumentTotal(string $indexName): int
    {
        $index = $this->environmentizeIndex($indexName);

        try {
            // Check if index exists
            $exists = $this->getClient()->indices()->exists(['index' => $index]);
            
            if (!$exists) {
                return 0;
            }

            $params = [
                'index' => $index,
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass()
                    ],
                    'size' => 0
                ]
            ];

            $response = $this->getClient()->search($params);
            return $response['hits']['total']['value'] ?? 0;
        } catch (Exception $e) {
            throw new IndexingServiceException(sprintf(
                'Failed to get document total from index "%s": %s',
                $index,
                $e->getMessage()
            ));
        }
    }

    /**
     * @return array
     * @throws IndexConfigurationException
     */
    public function configure(): array
    {
        $configuration = $this->getConfiguration();
        $indexes = $configuration->getIndexes();
        $results = [];

        foreach ($indexes as $index => $config) {
            $envIndex = $this->environmentizeIndex($index);
            
            try {
                $this->findOrMakeIndex($envIndex);
                
                // Get all fields from the config
                $allFields = $configuration->getFieldsForIndex($index);
                
                // Update the mapping of the index
                $this->updateIndexMapping($envIndex, $allFields);
                
                $results[$index] = true;
            } catch (Exception $e) {
                throw new IndexConfigurationException(sprintf(
                    'Failed to configure index "%s": %s',
                    $index,
                    $e->getMessage()
                ));
            }
        }

        return $results;
    }

    /**
     * @param string $field
     * @throws InvalidArgumentException
     */
    public function validateField(string $field): void
    {
        // Elasticsearch field names have these restrictions:
        // 1. Can't start with _
        // 2. Can't contain #, \, /, *, ?, ", <, >, |, space, ,, :
        $invalidChars = ['#', '\\', '/', '*', '?', '"', '<', '>', '|', ' ', ',', ':'];
        
        if (strpos($field, '_') === 0) {
            throw new InvalidArgumentException(sprintf(
                'Field "%s" begins with an underscore which is not allowed in Elasticsearch',
                $field
            ));
        }
        
        foreach ($invalidChars as $char) {
            if (strpos($field, $char) !== false) {
                throw new InvalidArgumentException(sprintf(
                    'Field "%s" contains invalid character "%s" which is not allowed in Elasticsearch',
                    $field,
                    $char
                ));
            }
        }
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @return DocumentBuilder
     */
    public function getBuilder(): DocumentBuilder
    {
        return $this->builder;
    }

    /**
     * @param Client $client
     * @return ElasticsearchService
     */
    private function setClient(Client $client): ElasticsearchService
    {
        $this->client = $client;
        return $this;
    }

    /**
     * @param DocumentBuilder $builder
     * @return ElasticsearchService
     */
    private function setBuilder(DocumentBuilder $builder): ElasticsearchService
    {
        $this->builder = $builder;
        return $this;
    }

    /**
     * Create an index if it doesn't exist
     * 
     * @param string $index
     * @throws Exception
     */
    private function findOrMakeIndex(string $index): void
    {
        $exists = $this->getClient()->indices()->exists(['index' => $index]);
        
        if (!$exists) {
            $params = [
                'index' => $index,
                'body' => [
                    'settings' => [
                        'number_of_shards' => 1,
                        'number_of_replicas' => 0,
                    ],
                ],
            ];
            
            $this->getClient()->indices()->create($params);
        }
    }

    /**
     * Update the mapping of an index
     * 
     * @param string $index
     * @param array $fields
     * @throws Exception
     */
    private function updateIndexMapping(string $index, array $fields): void
    {
        $properties = [];
        
        foreach ($fields as $field) {
            $fieldName = $field->getName();
            $options = $field->getOptions();
            $type = $options['type'] ?? self::DEFAULT_FIELD_TYPE;
            
            $properties[$fieldName] = [
                'type' => $type,
            ];
            
            // Add additional options based on field type
            if ($type === 'date') {
                $properties[$fieldName]['format'] = 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis';
            }
        }
        
        $params = [
            'index' => $index,
            'body' => [
                'properties' => $properties,
            ],
        ];
        
        $this->getClient()->indices()->putMapping($params);
    }

    /**
     * Group documents by their index
     * 
     * @param array $documents
     * @return array
     */
    private function groupDocumentsByIndex(array $documents): array
    {
        $grouped = [];
        
        foreach ($documents as $document) {
            if (!$document instanceof DocumentInterface) {
                continue;
            }
            
            $source = $document->getSource();
            $index = $this->environmentizeIndex($source);
            
            if (!isset($grouped[$index])) {
                $grouped[$index] = [];
            }
            
            $grouped[$index][] = $document;
        }
        
        return $grouped;
    }

    /**
     * Remove environment prefix from index name
     * 
     * @param string $index
     * @return string
     */
    private function removeEnvironmentFromIndex(string $index): string
    {
        $variant = $this->getConfiguration()->getIndexVariant();
        
        if ($variant && strpos($index, $variant . '_') === 0) {
            return substr($index, strlen($variant) + 1);
        }
        
        return $index;
    }
}
