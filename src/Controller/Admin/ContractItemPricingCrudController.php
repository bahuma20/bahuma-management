<?php

namespace App\Controller\Admin;

use App\Entity\ContractItemPricing;
use App\Entity\PricingPeriod;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;

class ContractItemPricingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ContractItemPricing::class;
    }


    public function configureFields(string $pageName): iterable
    {
        $periods = [];

        foreach (PricingPeriod::cases() as $period) {
            $periods[$period->value] = $period->value;
        }

        return [
            IdField::new('id')->setDisabled(),
            ChoiceField::new('period')
                ->setChoices(PricingPeriod::cases())
                ->setRequired(true),
            DateField::new('start')->setRequired(true),
            DateField::new('end')->setRequired(false),
            MoneyField::new('price')
                ->setCurrency('EUR')
                ->setStoredAsCents(false)
                ->setRequired(true),
        ];
    }

}
