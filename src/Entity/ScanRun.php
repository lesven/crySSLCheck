<?php

namespace App\Entity;

use App\Repository\ScanRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScanRunRepository::class)]
#[ORM\Table(name: 'scan_runs')]
class ScanRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $status = null;

    #[ORM\OneToMany(targetEntity: Finding::class, mappedBy: 'scanRun', cascade: ['remove'])]
    private Collection $findings;

    public function __construct()
    {
        $this->findings = new ArrayCollection();
        $this->startedAt = new \DateTimeImmutable();
        $this->status = 'running';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function finish(string $status): void
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, Finding>
     */
    public function getFindings(): Collection
    {
        return $this->findings;
    }
}
