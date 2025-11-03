<?php

namespace App\Entity;

use App\Repository\ContractItemPricingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

enum PricingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}

#[ORM\Entity(repositoryClass: ContractItemPricingRepository::class)]
class ContractItemPricing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pricings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ContractItem $contractItem = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $start = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $end = null;

    #[ORM\Column(type: 'string', enumType: PricingPeriod::class)]
    private PricingPeriod $period;

    #[ORM\Column]
    private ?float $price = null;

    public function __toString(): string
    {
        $start = isset($this->start) ? $this->start->format('d.m.Y') : '';

        $end = isset($this->end) ? $this->end->format('d.m.Y') : 'heute';
        $price = isset($this->price) ? $this->price . ' €' : '???';

        return $start . ' - ' . $end . ': ' . $price;
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContractItem(): ?ContractItem
    {
        return $this->contractItem;
    }

    public function setContractItem(?ContractItem $contractItem): static
    {
        $this->contractItem = $contractItem;

        return $this;
    }

    public function getStart(): ?\DateTime
    {
        return $this->start;
    }

    public function setStart(\DateTime $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?\DateTime
    {
        return $this->end;
    }

    public function setEnd(?\DateTime $end): static
    {
        $this->end = $end;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getPeriod(): PricingPeriod
    {
        return $this->period;
    }

    public function setPeriod(PricingPeriod $period): void
    {
        $this->period = $period;
    }
}
