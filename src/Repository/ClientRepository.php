<?php

namespace App\Repository;

use App\Model\Client;
use App\Service\InvoiceShelfHttpClientService;
use PHPUnit\Event\RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClientRepository
{
    private HttpClientInterface $httpClient;

    public function __construct(InvoiceShelfHttpClientService $httpClientService, private readonly CacheInterface $clientsCache, private readonly CacheInterface $rechnungTokenCache)
    {
        $this->httpClient = $httpClientService->httpClient;
    }

    /**
     * @return Client[]
     */
    public function find(): array
    {
        $httpClient = $this->httpClient;

        return $this->clientsCache->get('clients_list', function () use ($httpClient) {
            $response = $httpClient->request('GET', '/api/v1/customers?limit=100');
            $content = $response->toArray();

            $clients = [];

            foreach ($content['data'] as $data) {
                $client = new Client();
                $client->setId($data['id']);
                $client->setName($data['name']);
                $client->setContactName($data['contact_name']);
                if ($data['email'] == null) {
                    throw new RuntimeException('Kunde ' . $client->getName() . ' hat keine E-Mail Adresse');
                }
                $client->setEmail($data['email']);
                $clients[] = $client;
            }

            return $clients;
        });
    }

    public function clearCache()
    {
        $this->rechnungTokenCache->clear();
        $this->clientsCache->clear();
    }

    public function findById(string $id)
    {
        $clients = $this->find();

        $results = array_values(array_filter($clients, function (Client $client) use ($id) {
            return $client->getId() === $id;
        }));

        if (count($results) === 0) {
            throw new \RuntimeException('Could not find a client with id ' . $id);
        }

        return $results[0];
    }
}
