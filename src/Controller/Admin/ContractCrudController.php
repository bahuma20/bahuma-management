<?php

namespace App\Controller\Admin;

use App\Admin\Field\ClientField;
use App\Entity\Contract;
use App\Repository\ClientRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class ContractCrudController extends AbstractCrudController
{

    public function __construct(private ClientRepository $clientRepository)
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



        return $actions
            ->add(Crud::PAGE_INDEX, $clearCacheAction)
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


}
