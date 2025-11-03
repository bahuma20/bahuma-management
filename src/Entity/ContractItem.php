<?php

namespace App\Entity;

use App\Repository\ContractItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

enum ContractItemType: string
{
    case Domain = 'domain';
    case Hosting = 'hosting';
    case Nextcloud = 'nextcloud';
}

#[ORM\Entity(repositoryClass: ContractItemRepository::class)]
class ContractItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Contract $contract = null;

    #[ORM\Column(type: 'string', enumType: ContractItemType::class)]
    private ContractItemType $type;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /**
     * @var Collection<int, ContractItemPricing>
     */
    #[ORM\OneToMany(targetEntity: ContractItemPricing::class, mappedBy: 'contractItem', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['start' => 'ASC'])]
    private Collection $pricings;

    public function __construct()
    {
        $this->pricings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ContractItemType
    {
        return $this->type;
    }

    public function setType(ContractItemType $type): void
    {
        $this->type = $type;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getContract(): ?Contract
    {
        return $this->contract;
    }

    public function setContract(?Contract $contract): static
    {
        $this->contract = $contract;

        return $this;
    }

    /**
     * @return Collection<int, ContractItemPricing>
     */
    public function getPricings(): Collection
    {
        return $this->pricings;
    }

    public function addPricing(ContractItemPricing $pricing): static
    {
        if (!$this->pricings->contains($pricing)) {
            $this->pricings->add($pricing);
            $pricing->setContractItem($this);
        }

        return $this;
    }

    public function removePricing(ContractItemPricing $pricing): static
    {
        if ($this->pricings->removeElement($pricing)) {
            // set the owning side to null (unless already changed)
            if ($pricing->getContractItem() === $this) {
                $pricing->setContractItem(null);
            }
        }

        return $this;
    }
}
