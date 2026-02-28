<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Orchestrates loading of all fixtures in the correct dependency order.
 *
 * Load order: Users → Domains → ScanRuns → Findings
 *
 * Usage:
 *   php bin/console doctrine:fixtures:load
 *   php bin/console doctrine:fixtures:load --env=test
 */
class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            DomainFixtures::class,
            ScanRunFixtures::class,
            FindingFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // All data is loaded by the individual fixtures above.
        // This class exists so you can run a single command to load everything.
    }
}
