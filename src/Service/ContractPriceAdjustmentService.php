<?php

namespace App\Service;

use App\Entity\Contract;
use App\Entity\ContractItem;
use App\Entity\ContractItemPricing;
use App\Model\Client;
use App\Repository\ClientRepository;
use App\Repository\ContractRepository;
use DateInterval;
use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;

readonly class ContractPriceAdjustmentService
{
    public function __construct(private ContractRepository     $contractRepository,
                                private ClientRepository       $clientRepository,
                                private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Find all contracts that have active contract items.
     *
     * @return array{
     *     'contract': Contract,
     *     'client': Client,
     *     'activeContractItems': array{
     *         array {
     *             'contractItem': ContractItem,
     *             'activePricing': ContractItemPricing
     *         }
     *     }
     * }
     */
    public function getContractItemsToAdjust(): array
    {
        $data = [];

        foreach ($this->contractRepository->findAll() as $contract) {
            $contractItems = $contract->getItems();

            $activeContractItems = [];

            foreach ($contractItems as $contractItem) {
                $pricings = $contractItem->getPricings();

                try {
                    $activePricing = $this->getActivePricing($pricings);

                    $activeContractItems[] = [
                        'contractItem' => $contractItem,
                        'activePricing' => $activePricing,
                    ];
                } catch (NoActivePricingException $ignored) {
                    // when no active pricing, then don't add it to the array.
                }
            }

            if (count($activeContractItems) > 0) {
                $client = $this->clientRepository->findById($contract->getClient());
                $data[] = [
                    'contract' => $contract,
                    'activeContractItems' => $activeContractItems,
                    'client' => $client,
                ];
            }
        }

        return $data;
    }


    /**
     * Creates new pricings for specified contract items that will be valid from a specific date on.
     *
     * @param DateTime $startDate When the new prices should apply
     * @param Adjustment[] $adjustments List of contract items and their adjustments
     * @param bool $preview If true: No adjustments will be made to the database. It will only return the planned pricing adjustments.
     * @return array{
     *  array{
     *      'contract': Contract,
     *      'client': Client,
     *      'adjustedItems': array{
     *          array{
     *              'contractItem': ContractItem,
     *              'oldPricing': ContractItemPricing,
     *              'newPricing': ContractItemPricing
     *          }
     *       }
     *     }
     *  }
     * @throws \DateInvalidOperationException
     */
    public function adjustPrices(DateTime $startDate, array $adjustments, bool $preview = false): array
    {
        $endDateOfCurrentPricing = clone $startDate;
        $endDateOfCurrentPricing->sub(new DateInterval('P1D'));

        /**
         * @var array{
         *  array{
         *      'contract': Contract,
         *      'client': Client,
         *      'adjustedItems': array{
         *          array{
         *              'contractItem': ContractItem,
         *              'oldPricing': ContractItemPricing,
         *              'newPricing': ContractItemPricing
         *          }
         *       }
         *     }
         *  } $adjustedContracts
         */
        $adjustedContracts = [];


        foreach ($adjustments as $adjustment) {
            $factor = $adjustment->factor;

            $contractItem = $adjustment->contractItem;

            if ($factor > 0) {
                if (!array_key_exists($contractItem->getContract()->getId(), $adjustedContracts)) {
                    $adjustedContract = [];
                    $adjustedContract['contract'] = $contractItem->getContract();
                    $adjustedContract['client'] = $this->clientRepository->findById($contractItem->getContract()->getClient());
                    $adjustedContract['adjustedItems'] = [];
                } else {
                    $adjustedContract = $adjustedContracts[$contractItem->getContract()->getId()];
                }

                $adjustedItem = [
                    'contractItem' => $contractItem,
                ];

                $oldPricings = $contractItem->getPricings();

                $activePricing = $this->getActivePricing($oldPricings);

                $activePricing->setEnd($endDateOfCurrentPricing);

                $newPrice = round($activePricing->getPrice() + ($activePricing->getPrice() * $factor), 2);

                $newPricing = new ContractItemPricing();
                $newPricing->setContractItem($contractItem);
                $newPricing->setPrice($newPrice);
                $newPricing->setStart($startDate);
                $newPricing->setPeriod($activePricing->getPeriod());

                $this->entityManager->persist($newPricing);

                $adjustedItem['oldPricing'] = $activePricing;
                $adjustedItem['newPricing'] = $newPricing;

                $adjustedContract['adjustedItems'][] = $adjustedItem;

                $adjustedContracts[$contractItem->getContract()->getId()] = $adjustedContract;
            }
        }

        if (!$preview) {
            $this->entityManager->flush();
        }

        return $adjustedContracts;
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

}

class Adjustment
{
    public function __construct(public ContractItem $contractItem, public float $factor)
    {
    }
}

class NoActivePricingException extends \RuntimeException
{
    #[Pure]
    public function __construct()
    {
        parent::__construct('Unable to determine active pricing');
    }

}
