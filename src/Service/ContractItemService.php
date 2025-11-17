<?php

namespace App\Service;

use App\Entity\ContractItemType;

class ContractItemService
{
    public static function getLabel(ContractItemType $type): string
    {
        return match ($type) {
            ContractItemType::Domain => 'Domain',
            ContractItemType::Hosting => 'Hosting',
            ContractItemType::Nextcloud => 'Nextcloud',
            default => throw new \RuntimeException("Unknown contract type $type->value"),
        };
    }

    public static function getInvoiceShelfId(ContractItemType $type): string
    {
        return match ($type) {
            ContractItemType::Domain => '4',
            ContractItemType::Hosting => '5',
            ContractItemType::Nextcloud => '7',
            default => throw new \RuntimeException("Unknown contract type $type->value"),
        };
    }
}
