<?php

namespace App\Entity;

use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\Severity;
use App\Repository\FindingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FindingRepository::class)]
#[ORM\Table(name: 'findings')]
#[ORM\HasLifecycleCallbacks]
class Finding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'findings')]
    #[ORM\JoinColumn(name: 'domain_id', nullable: false)]
    private Domain $domain;

    #[ORM\ManyToOne(targetEntity: ScanRun::class, inversedBy: 'findings')]
    #[ORM\JoinColumn(name: 'run_id', nullable: false)]
    private ScanRun $scanRun;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(name: 'finding_type', length: 50, enumType: FindingType::class)]
    private FindingType $findingType;

    #[ORM\Column(length: 20, enumType: Severity::class)]
    private Severity $severity;

    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(length: 20, enumType: FindingStatus::class, options: ['default' => 'new'])]
    private FindingStatus $status = FindingStatus::NEW;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function setDomain(Domain $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getScanRun(): ScanRun
    {
        return $this->scanRun;
    }

    public function setScanRun(ScanRun $scanRun): static
    {
        $this->scanRun = $scanRun;

        return $this;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getFindingType(): FindingType
    {
        return $this->findingType;
    }

    public function setFindingType(FindingType $findingType): static
    {
        $this->findingType = $findingType;

        return $this;
    }

    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    public function setSeverity(Severity $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getStatus(): FindingStatus
    {
        return $this->status;
    }

    public function setStatus(FindingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function markResolved(): void
    {
        $this->status = FindingStatus::RESOLVED;
    }

    public function getSeverityBadgeClass(): string
    {
        return match ($this->severity) {
            Severity::CRITICAL => 'danger',
            Severity::HIGH     => 'warning',
            Severity::MEDIUM   => 'info',
            Severity::LOW      => 'secondary',
            default            => 'success',
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            FindingStatus::NEW      => 'danger',
            FindingStatus::KNOWN    => 'warning',
            FindingStatus::RESOLVED => 'success',
            default                 => 'secondary',
        };
    }
}
