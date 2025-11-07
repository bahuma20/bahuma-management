<?php

namespace App\Controller\Admin;

use App\Admin\Field\ClientField;
use App\Entity\Contract;
use App\Entity\ContractItem;
use App\Entity\ContractItemPricing;
use App\Repository\ClientRepository;
use App\Repository\ContractItemPricingRepository;
use App\Repository\ContractItemRepository;
use App\Repository\ContractRepository;
use App\Service\Adjustment;
use App\Service\ContractPriceAdjustmentService;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Event\RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

class ContractCrudController extends AbstractCrudController
{

    public function __construct(private ClientRepository               $clientRepository,
                                private ContractRepository             $contractRepository,
                                private ContractItemRepository         $contractItemRepository,
                                private ContractItemPricingRepository  $contractItemPricingRepository,
                                private EntityManagerInterface         $entityManager,
                                private ContractPriceAdjustmentService $contractPriceAdjustmentService)
    {
    }


    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Vertrag')
            ->setEntityLabelInPlural('Verträge');
    }

    public function configureActions(Actions $actions): Actions
    {
        $clearCacheAction = Action::new('clear_cache')
            ->setLabel('Kunden-Cache löschen')
            ->linkToRoute('clear_clients_cache')
            ->createAsGlobalAction();

        $adjustPriceAction = Action::new('adjust_prices')
            ->setLabel('Preisanpassung')
            ->linkToRoute('adjust_prices')
            ->createAsGlobalAction();


        return $actions
            ->add(Crud::PAGE_INDEX, $clearCacheAction)
            ->add(Crud::PAGE_INDEX, $adjustPriceAction);
    }


    public static function getEntityFqcn(): string
    {
        return Contract::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $clients = $this->getClientChoices();

        return [
            FormField::addColumn('col-12'),
            IdField::new('id')->setDisabled(),
            ChoiceField::new('client')
                ->setChoices($clients)
                ->setRequired(true)
                ->setLabel('Kunde'),
            CollectionField::new('items')
                ->useEntryCrudForm(ContractItemCrudController::class)
                ->renderExpanded()
                ->setEntryIsComplex()
        ];
    }

    private function getClientChoices(): array
    {
        $clients = [];

        foreach ($this->clientRepository->find() as $client) {
            $clients[$client->getName()] = $client->getId();
        }

        ksort($clients);

        return $clients;
    }

    #[Route(name: 'clear_clients_cache', path: '/clear_clients_cache')]
    public function clearClientsCache()
    {
        $this->clientRepository->clearCache();

        $this->addFlash('success', 'Cache wurde gelöscht');

        $url = $this->container->get(AdminUrlGenerator::class)
            ->setController(ContractCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
        return new RedirectResponse($url);
    }

    #[Route(path: 'adjust_prices', name: 'adjust_prices', methods: ['GET', 'POST'])]
    public function adjustPrices(Request $request)
    {
        $form = $this->buildAdjustPriceForm();

        $form->handleRequest($request);

        $adjustedContracts = false;

        if ($form->isSubmitted() && $form->isValid()) {

            $data = $form->getData();

            $execute = false;

            $rawData = $request->get('form');
            if (array_key_exists('execute', $rawData)) {
                $execute = true;
            }

            $form = $this->buildAdjustPriceForm($data);

            $startDate = $data['date'];

            $adjustments = [];

            foreach ($data['contract_items'] as $contractItemId => $factor) {
                $contractItem = $this->contractItemRepository->find($contractItemId);
                $adjustments[] = new Adjustment($contractItem, $factor);
            }

            $adjustedContracts = $this->contractPriceAdjustmentService->adjustPrices($startDate, $adjustments, !$execute);

            $adjustedContracts = array_map(function ($item) use ($data) {
                $item['mailtext'] = $this->generateMailText($data['mailtext'], $item, $data['date']);
                return $item;
            }, $adjustedContracts);

            if ($execute) {
                $this->addFlash('success', 'Preise wurden angepasst');

                return $this->render('contract/adjust_prices.html.twig', [
                    'adjustedContracts' => $adjustedContracts,
                ]);
            }
        }

        return $this->render('contract/adjust_prices.html.twig', [
            'form' => $form,
            'adjustedContracts' => $adjustedContracts,
        ]);
    }

    private function getContractItemsToAdjust()
    {
        $data = [];

        foreach ($this->contractRepository->findAll() as $contract) {
            $contractItems = $contract->getItems();

            $activeContractItems = [];

            foreach ($contractItems as $contractItem) {
                $pricings = $contractItem->getPricings();

                $activePricings = array_filter($pricings->toArray(), function ($pricing) {
                    return $pricing->getEnd() === null;
                });

                if (count($activePricings) > 0) {
                    $activeContractItems[] = [
                        'contractItem' => $contractItem,
                        'activePricing' => array_values($activePricings)[0],
                    ];
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

    private function doAdjustPrices(array $data, bool $execute): array
    {
        /** @var \DateTime $startDate */
        $startDate = $data['date'];

        $endDateOfCurrentPricing = clone $startDate;
        $endDateOfCurrentPricing->sub(new DateInterval('P1D'));

        /**
         * contract
         * client
         * adjustedItems
         *   contractItem
         *   oldPricing (pricing)
         *   newPricing (pricing)
         */
        $adjustedContracts = [];

        foreach ($data['contract_items'] as $contractItemId => $adjustment) {
            if ($adjustment > 0) {
                /** @var ContractItem $contractItem */
                $contractItem = $this->contractItemRepository->find($contractItemId);

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
                $activePricings = array_filter($oldPricings->toArray(), function ($pricing) {
                    return $pricing->getEnd() == null;
                });

                if (count($activePricings) == 0) {
                    throw new RuntimeException('Unable to determine active pricing');
                }

                /** @var ContractItemPricing $activePricing */
                $activePricing = array_values($activePricings)[0];

                $activePricing->setEnd($endDateOfCurrentPricing);

                $newPrice = round($activePricing->getPrice() + ($activePricing->getPrice() * $adjustment), 2);

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

        if ($execute) {
            $this->entityManager->flush();
        }


        return $adjustedContracts;
    }

    private function generateMailText(string $template, array $adjustedContract, \DateTime $startDate): string
    {
        /** @var Environment $twig */
        $twig = $this->container->get('twig');

        $twigTemplate = $twig->createTemplate(trim($template));

        return $twig->render($twigTemplate, [
            'adjustedContract' => $adjustedContract,
            'startDate' => $startDate,
        ]);
    }

    private function buildAdjustPriceForm($data = null): FormInterface
    {
        $contractItemsToAdjust = $this->contractPriceAdjustmentService->getContractItemsToAdjust();

        $formBuilder = $this->createFormBuilder($data);

        $mailTextTemplate =
            'Hallo {{ adjustedContract.client.contactName }},

Aufgrund gestiegener Server- und Lizenzkosten müssen die Preise für "{{ adjustedContract.client.name }}" ab dem {{ startDate | format_date("long", locale: "de") }} wie folgt angepasst werden.

{% for item in adjustedContract.adjustedItems %}
Produkt: {{ item.contractItem.type.name }}
{% if item.contractItem.comment %}
{{ item.contractItem.comment }}
{% endif %}
bisheriger Preis: {{ item.oldPricing.price|format_currency(\'EUR\', locale: \'de\') }} / {{ item.oldPricing.period.name }}
Preis ab {{ item.newPricing.start | format_date("long", locale: "de") }}: {{ item.newPricing.price|format_currency(\'EUR\', locale: \'de\') }} / {{ item.newPricing.period.name }}

{% endfor %}

Sollten Sie Fragen zur Preisanpassung haben, melden Sie sich gerne bei mir.';

        $formBuilder->add('mailtext', TextareaType::class, [
            'data' => $data ? $data['mailtext'] : $mailTextTemplate,
            'attr' => [
                'rows' => 10,
            ],
            'label' => 'E-Mail Vorlage'
        ]);

        $formBuilder->add('date', DateType::class, [
            'widget' => 'single_text',
            'label' => 'Datum der Preiserhöhung',
        ]);

        $contractsGroup = $formBuilder->create('contract_items', FormType::class, [
            'label' => false
        ]);

        foreach ($contractItemsToAdjust as $contractEntry) {
            $contractGroup = $formBuilder->create($contractEntry['contract']->getId(), FormType::class, [
                'inherit_data' => true,
                'label' => $contractEntry['client']->getName(),
                'label_attr' => [
                    'style' => 'font-weight: bold; font-size: 1.1rem;'
                ]
            ]);

            foreach ($contractEntry['activeContractItems'] as $contractItemEntry) {
                /** @var ContractItem $contractItem */
                $contractItem = $contractItemEntry['contractItem'];

                /** @var ContractItemPricing $activePricing */
                $activePricing = $contractItemEntry['activePricing'];

                $contractGroup->add($contractItem->getId(), PercentType::class, [
                    'label' => $contractItem->getType()->name . ($contractItem->getComment() !== null ? ' (' . $contractItem->getComment() . ')' : ''),
                    'help' => 'Aktueller Preis: ' . $activePricing->getPrice() . ' € / ' . $activePricing->getPeriod()->name,
                    'required' => false,
                    'data' => $data ? $data['contract_items'][$contractItem->getId()] : 0,
                    'attr' => [
                        'data-current-price' => $activePricing->getPrice(),
                        'autocomplete' => 'off',
                    ]
                ]);
            }


            $contractsGroup->add($contractGroup);
        }

        $formBuilder->add($contractsGroup);


        $formBuilder->add('preview', SubmitType::class, ['label' => 'Vorschau', 'attr' => ['value' => 42]]);
        $formBuilder->add('execute', SubmitType::class, ['label' => 'Preisanpassung ausführen', 'disabled' => !$data, 'attr' => ['value' => 42]]);

        return $formBuilder->getForm();
    }

}
