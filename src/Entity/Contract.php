<?php

namespace App\Entity;

use App\Repository\ContractRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\Valid;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
class Contract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $client = null;

    /**
     * @var Collection<int, ContractItem>
     */
    #[ORM\OneToMany(targetEntity: ContractItem::class, mappedBy: 'contract', cascade: ['persist'], orphanRemoval: true)]
    #[Valid]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function setClient(string $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return Collection<int, ContractItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ContractItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setContract($this);
        }

        return $this;
    }

    public function removeItem(ContractItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getContract() === $this) {
                $item->setContract(null);
            }
        }

        return $this;
    }
}
