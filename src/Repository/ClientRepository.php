<?php

namespace App\Repository;

use App\Model\Client;
use PHPUnit\Event\RuntimeException;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClientRepository
{
    private string $invoiceShelfUrl;
    private string $invoiceShelfUser;
    private string $invoiceShelfPassword;

    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient, private CacheInterface $rechnungTokenCache, private CacheInterface $clientsCache)
    {
        $this->invoiceShelfUrl = $_ENV['INVOICE_SHELF_URL'];
        $this->invoiceShelfUser = $_ENV['INVOICE_SHELF_USER'];
        $this->invoiceShelfPassword = $_ENV['INVOICE_SHELF_PASSWORD'];

        if (!isset($this->invoiceShelfPassword) || !isset($this->invoiceShelfUrl) || !isset($this->invoiceShelfUser)) {
            throw new RuntimeException('InvoiceShelf environment parameters are not configured');
        }

        $options = (new HttpOptions())
            ->setBaseUri($this->invoiceShelfUrl)
            ->setHeader('Accept', 'application/json')
            ->setHeader('Content-Type', 'application/json');

        $token = $this->rechnungTokenCache->get('token', function () use ($httpClient, $options) {
            $httpClient = $httpClient->withOptions($options->toArray());

            $response = $httpClient->request('POST', '/api/v1/auth/login', [
                'body' => json_encode([
                    'username' => $this->invoiceShelfUser,
                    'password' => $this->invoiceShelfPassword,
                    'device_name' => 'Bahuma-Management'
                ])
            ]);

            $content = $response->toArray();

            return $content['token'];
        });


        $options->setHeader('Authorization', 'Bearer ' . $token);

        $this->httpClient = $httpClient->withOptions($options->toArray());
    }

    /**
     * @return Client[]
     */
    public function find(): array
    {
        $httpClient = $this->httpClient;

        return $this->clientsCache->get('clients_list', function () use ($httpClient) {
            $response = $httpClient->request('GET', '/api/v1/customers');
            $content = $response->toArray();

            $clients = [];

            foreach ($content['data'] as $data) {
                $client = new Client();
                $client->setId($data['id']);
                $client->setName($data['name']);
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
}
