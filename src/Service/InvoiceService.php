<?php

namespace App\Service;

use App\Entity\Contract;
use App\Entity\ContractItem;
use App\Entity\ContractItemPricing;
use App\Entity\PricingPeriod;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use http\Exception\RuntimeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class InvoiceService
{

    private HttpClientInterface $httpClient;

    public function __construct(InvoiceShelfHttpClientService $httpClientService, private LoggerInterface $logger, private EntityManagerInterface $entityManager)
    {
        $this->httpClient = $httpClientService->httpClient;
    }

    public function syncInvoice(Contract $contract)
    {
        if (!$contract->getRecurringInvoiceId()) {
            $somethingToDo = $this->createRecurringInvoice($contract);
        } else {
            $somethingToDo = $this->updateRecurringInvoice($contract->getRecurringInvoiceId(), $contract);
        }

        if (!$somethingToDo) {
            $this->logger->info('Contract ' . $contract->getId() . ' has no active items. Therefore no recurring invoice was created or updated.');
        }

        return $somethingToDo;
    }

    private function createRecurringInvoice(Contract $contract): bool
    {
        $this->logger->info('Syncing contract ' . $contract->getId() . ' with action CREATE');

        $body = $this->buildRecurringInvoiceBody($contract);

        if (!$body) {
            return false;
        }

        try {
            $this->logger->debug('Creating recurring invoice for contract ' . $contract->getId(), [
                'body' => json_encode($body),
            ]);

            $response = $this->httpClient->request('POST', '/api/v1/recurring-invoices', [
                'body' => json_encode($body),
            ]);

            $responseBody = json_decode($response->getContent());

            $id = $responseBody->data->id;

            if (!$id) {
                throw new RuntimeException('Could not get id of created recurring invoice');
            }

            $contract->setRecurringInvoiceId($id);

            $this->entityManager->flush();

            $this->logger->info('Recurring invoice ' . $id . ' was created');
        } catch (ClientException $clientException) {
            $this->handleException('Error while updating recurring invoice', $clientException, $body);
        }


        return true;
    }

    private function updateRecurringInvoice(string $recurringInvoiceId, Contract $contract)
    {
        $this->logger->info('Syncing contract ' . $contract->getId() . ' with action UPDATE');

        $body = $this->buildRecurringInvoiceBody($contract);

        if (!$body) {
            return false;
        }

        try {
            $this->logger->debug('Updating recurring invoice for contract' . $contract->getId(), [
                'recurringInvoiceId' => $recurringInvoiceId,
                'body' => json_encode($body),
            ]);

            $this->httpClient->request('PUT', '/api/v1/recurring-invoices/' . $recurringInvoiceId, [
                'body' => json_encode($body),
            ]);

            $this->logger->info('Recurring invoice ' . $recurringInvoiceId . ' was updated');
        } catch (ClientException $clientException) {
            $this->handleException('Error while updating recurring invoice', $clientException, $body);
        }

        return true;
    }

    private function buildRecurringInvoiceBody(Contract $contract): array|bool
    {
        $items = $this->buildItems($contract);

        if (count($items) === 0) {
            return false;
        }

        $total = 0;

        foreach ($items as $item) {
            $total += intval($item['total']);
        }


        return [
            'starts_at' => (new \DateTime('now'))->format('Y-m-d H:i:s'),
            'send_automatically' => false,
            'customer_id' => $contract->getClient(),
            'status' => 'ACTIVE',
            'discount' => '0.00',
            'discount_val' => 0,
            'sub_total' => $total,
            'total' => $total,
            'due_amount' => $total,
            'tax' => 0,
            'frequency' => '0 0 1 1 *',
            'limit_by' => 'NONE',
            'customFields' => [
                [
                    'id' => '1',
                    'value' => 'Jahresrechnung',
                ],
            ],
            'template_name' => 'bahuma',
            'items' => $items,
        ];
    }

    private function buildItems(Contract $contract): array
    {
        $this->logger->debug('There are ' . count($contract->getItems()) . ' items for contract ' . $contract->getId(), ['contract' => $contract]);

        /** @var ContractItem[] $activeItems */
        $activeItems = [];

        foreach ($contract->getItems() as $item) {
            try {
                $this->getActivePricing($item->getPricings());
                $activeItems[] = $item;
            } catch (NoActivePricingException) {
                $this->logger->debug('No active pricing for item ' . $item->getId());
                continue;
            }
        }

        $this->logger->debug('There are ' . count($activeItems) . ' active items for contract ' . $contract->getId(), ['activeItems' => $activeItems]);

        $itemsBody = [];

        foreach ($activeItems as $activeItem) {

            $pricing = $this->getActivePricing($activeItem->getPricings());

            $quantity = 12;

            if ($pricing->getPeriod() == PricingPeriod::Jahr) {
                $price = round($pricing->getPrice() * 100 / 12);
            } else if ($pricing->getPeriod() == PricingPeriod::Monat) {
                $price = $pricing->getPrice() * 100;
            } else {
                throw new \RuntimeException('Unsupported pricing period');
            }

            $total = $price * $quantity;


            $itemsBody[] = [
                'item_id' => ContractItemService::getInvoiceShelfId($activeItem->getType()),
                'name' => ContractItemService::getLabel($activeItem->getType()),
                'description' => $activeItem->getComment() ? str_replace("\r", '', $activeItem->getComment()) : '',
                'quantity' => strval($quantity),
                'price' => strval($price),
                'total' => strval($total),
                'discount' => '0',
                'discount_type' => 'fixed',
            ];
        }

        return $itemsBody;
    }

    /**
     * Find the currently active pricing.
     *
     * @param Collection $pricings List of all pricings of a ContractItem
     * @return ContractItemPricing
     * @throws NoActivePricingException when there is no active pricing
     */
    private function getActivePricing(Collection $pricings): ContractItemPricing
    {
        $activePricings = array_filter($pricings->toArray(), function ($pricing) {
            return $pricing->getEnd() == null;
        });

        if (count($activePricings) == 0) {
            throw new NoActivePricingException();
        }

        return array_values($activePricings)[0];
    }

    private function handleException(string $message, ClientException $clientException, $body)
    {
        $this->logger->error($message, [
            'request_body' => $body,
            'cause' => $clientException,
            'response_code' => $clientException->getResponse()->getStatusCode(),
            'response_body' => $clientException->getResponse()->getContent(false)
        ]);
        throw new \RuntimeException($message . ' Check logs for details.', 0, $clientException);
    }
}
