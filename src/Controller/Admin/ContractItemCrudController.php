<?php

namespace App\Controller\Admin;

use App\Entity\ContractItem;
use App\Entity\ContractItemType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class ContractItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ContractItem::class;
    }


    public function configureFields(string $pageName): iterable
    {
        $contractTypes = [];

        foreach (ContractItemType::cases() as $type) {
            $contractTypes[$type->value] = $type->name;
        }

        return [
            FormField::addColumn('col-12'),
            IdField::new('id')->setDisabled(),
            ChoiceField::new('type')
                ->setChoices(ContractItemType::cases())
                ->setRequired(true),
            TextareaField::new('comment'),
            CollectionField::new('pricings')
                ->useEntryCrudForm(ContractItemPricingCrudController::class)
                ->setRequired(true)

        ];
    }

}
