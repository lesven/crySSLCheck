<?php

namespace App\Entity;

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

    #[ORM\Column(name: 'finding_type', length: 50)]
    private string $findingType;

    #[ORM\Column(length: 20)]
    private string $severity;

    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(length: 20, options: ['default' => 'new'])]
    private string $status = 'new';

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

    public function getFindingType(): string
    {
        return $this->findingType;
    }

    public function setFindingType(string $findingType): static
    {
        $this->findingType = $findingType;

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function markResolved(): void
    {
        $this->status = 'resolved';
    }

    public function getSeverityBadgeClass(): string
    {
        return match ($this->severity) {
            'critical' => 'danger',
            'high'     => 'warning',
            'medium'   => 'info',
            'low'      => 'secondary',
            default    => 'success',
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            'new'      => 'danger',
            'known'    => 'warning',
            'resolved' => 'success',
            default    => 'secondary',
        };
    }
}
