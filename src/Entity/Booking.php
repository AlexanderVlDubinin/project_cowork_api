<?php

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'bookings')]
#[ORM\Index(name: 'idx_bookings_collision_lookup', columns: ['resource_id', 'started_at', 'ended_at'])]
#[ORM\Index(name: 'idx_bookings_status', columns: ['status'])]
class Booking
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['booking:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['booking:read'])]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Resource::class, inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['booking:read'])]
    private ?Resource $resource = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    #[Groups(['booking:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    #[Groups(['booking:read'])]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: BookingStatus::class)]
    #[Groups(['booking:read'])]
    private ?BookingStatus $status = null;

    #[ORM\Column]
    #[Assert\Positive]
    #[Groups(['booking:read'])]
    private ?int $totalPrice = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    #[Groups(['booking:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, PaymentTransaction>
     */
    #[ORM\OneToMany(targetEntity: PaymentTransaction::class, mappedBy: 'booking', orphanRemoval: true)]
    private Collection $paymentTransactions;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->paymentTransactions = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getResource(): ?Resource
    {
        return $this->resource;
    }

    public function setResource(?Resource $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getStatus(): ?BookingStatus
    {
        return $this->status;
    }

    public function setStatus(BookingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTotalPrice(): ?int
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(int $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, PaymentTransaction>
     */
    public function getPaymentTransactions(): Collection
    {
        return $this->paymentTransactions;
    }

    public function addPaymentTransaction(PaymentTransaction $paymentTransaction): static
    {
        if (!$this->paymentTransactions->contains($paymentTransaction)) {
            $this->paymentTransactions->add($paymentTransaction);
            $paymentTransaction->setBooking($this);
        }

        return $this;
    }

    public function removePaymentTransaction(PaymentTransaction $paymentTransaction): static
    {
        if ($this->paymentTransactions->removeElement($paymentTransaction)) {
            // set the owning side to null (unless already changed)
            if ($paymentTransaction->getBooking() === $this) {
                $paymentTransaction->setBooking(null);
            }
        }

        return $this;
    }
}
