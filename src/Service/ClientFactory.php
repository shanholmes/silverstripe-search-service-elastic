<?php

namespace SilverStripe\SearchServiceElastic\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Exception;
use SilverStripe\Core\Injector\Factory;

class ClientFactory implements Factory
{

    /**
     * @throws Exception
     */
    public function create($service, array $params = []) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $host = $params['host'] ?? null;
        $username = $params['username'] ?? null;
        $password = $params['password'] ?? null;
        $httpClient = $params['http_client'] ?? null;

        if (!$host) {
            throw new Exception(sprintf(
                'The %s implementation requires environment variables: ' .
                'ELASTICSEARCH_ENDPOINT',
                Client::class
            ));
        }

        $builder = ClientBuilder::create()
            ->setHosts([$host]);

        // If credentials are provided, set them
        if ($username && $password) {
            $builder->setBasicAuthentication($username, $password);
        }

        // If a desired HTTP Client has been defined and instantiated in config (@see elasticsearch.yml) then we'll
        // set it here. If it hasn't been defined, then it will be left up to PSR-18 "discovery"
        if ($httpClient) {
            $builder->setHttpClient($httpClient);
        }

        return $builder->build();
    }

}
