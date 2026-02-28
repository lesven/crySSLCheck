<?php

namespace App\DataFixtures;

use App\Entity\ScanRun;
use App\Enum\ScanRunStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ScanRunFixtures extends Fixture
{
    public const SCAN_RUN_SUCCESS_REFERENCE = 'scan-run-success';
    public const SCAN_RUN_PARTIAL_REFERENCE = 'scan-run-partial';

    public function load(ObjectManager $manager): void
    {
        $successRun = new ScanRun();
        $successRun->setStartedAt(new \DateTimeImmutable('-2 hours'));
        $successRun->setFinishedAt(new \DateTimeImmutable('-1 hour 55 minutes'));
        $successRun->setStatus(ScanRunStatus::Success->value);
        $manager->persist($successRun);
        $this->addReference(self::SCAN_RUN_SUCCESS_REFERENCE, $successRun);

        $partialRun = new ScanRun();
        $partialRun->setStartedAt(new \DateTimeImmutable('-25 hours'));
        $partialRun->setFinishedAt(new \DateTimeImmutable('-24 hours 58 minutes'));
        $partialRun->setStatus(ScanRunStatus::Partial->value);
        $manager->persist($partialRun);
        $this->addReference(self::SCAN_RUN_PARTIAL_REFERENCE, $partialRun);

        $manager->flush();
    }
}
