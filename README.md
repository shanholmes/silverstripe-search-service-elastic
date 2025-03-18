# Silverstripe Search Service > Elastic

Elastic Service provider for [Silverstripe Search Service](https://github.com/silverstripe/silverstripe-search-service).

This module uses Elastic's official [Elasticsearch PHP library](https://github.com/elastic/elasticsearch-php) to provide
the ability to index content for Elasticsearch. This module **does not** provide any method for
performing searches on your indices - we've added some [suggestions](#searching) though.

## Requirements

* php: ^8.1
* silverstripe/framework: ^5
* silverstripe/silverstripe-search-service: ^3
* elasticsearch/elasticsearch: ^8.6
* guzzlehttp/guzzle: ^7

## Installation

`composer require silverstripe/silverstripe-search-service-elastic`

## Activating Elasticsearch

To start using Elasticsearch, define environment variables containing your endpoint, credentials, and index prefix.

```
ELASTICSEARCH_ENDPOINT="https://localhost:9200"
ELASTICSEARCH_USERNAME="elastic"
ELASTICSEARCH_PASSWORD="password"
ELASTICSEARCH_INDEX_PREFIX="mysite"
```

## Configuring Elasticsearch

The most notable configuration surface for Elasticsearch is the schema, which determines how data is stored in your
Elasticsearch index. There are several types of data in Elasticsearch:

* `text` (default)
* `date`
* `integer`
* `float`
* `boolean`
* `geo_point`

You can specify these data types in the `options` node of your fields.

```yaml
SilverStripe\SearchService\Service\IndexConfiguration:
  indexes:
    myindex:
      includeClasses:
        SilverStripe\CMS\Model\SiteTree:
          fields:
            title: true
            summary_field:
              property: SummaryField
              options:
                type: text
```

**Note**: Be careful about whimsically changing your schema. Elasticsearch may need to be fully reindexed if you
change the data type of a field.

## Additional documentation

Majority of documentation is provided by the Silverstripe Search Service module. A couple in particular that might be
useful to you are:

* [Configuration](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/configuration.md)
* [Customisation](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/customising.md)

## Searching

Elastic provides several options for searching Elasticsearch:

1. Direct API calls using the REST API
2. Using the Search UI library
3. Using the official Elasticsearch PHP client

### REST API

You can make direct HTTP calls to the Elasticsearch API endpoints to perform searches. This is the most low-level approach but gives you full control over the search request.

```php
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => 'https://localhost:9200/myindex/_search',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'query' => [
            'match' => [
                'title' => 'search term'
            ]
        ]
    ]),
]);
$response = curl_exec($curl);
curl_close($curl);
$results = json_decode($response, true);
```

### Search UI Library

Elasticsearch provides a headless [Search UI](https://docs.elastic.co/search-ui/overview) JS library, which can
be used with vanilla JS or any framework like React, Vue, etc.

The main library is:

* [@elastic/search-ui](https://www.npmjs.com/package/@elastic/search-ui)
  * Provides a class that allows you to perform searches and manage your search state.

If you are using React, there is also
[@elastic/react-search-ui](https://www.npmjs.com/package/@elastic/react-search-ui), which provides interface components.

### PHP Client

You can also use the official Elasticsearch PHP client directly to perform searches:

```php
use Elastic\Elasticsearch\ClientBuilder;

$client = ClientBuilder::create()
    ->setHosts(['localhost:9200'])
    ->setBasicAuthentication('username', 'password')
    ->build();

$params = [
    'index' => 'myindex',
    'body' => [
        'query' => [
            'match' => [
                'title' => 'search term'
            ]
        ]
    ]
];

$response = $client->search($params);

// Get response as an array
$results = $response->asArray();

// Or access properties directly (Elasticsearch 8.x feature)
echo $response['hits']['total']['value']; // Number of results
```
