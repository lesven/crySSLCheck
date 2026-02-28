<?php

namespace App\DataFixtures;

use App\Entity\Domain;
use App\Enum\DomainStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class DomainFixtures extends Fixture
{
    public const DOMAIN_GOOGLE_REFERENCE = 'domain-google';
    public const DOMAIN_GITHUB_REFERENCE = 'domain-github';
    public const DOMAIN_EXPIRED_REFERENCE = 'domain-expired';
    public const DOMAIN_INACTIVE_REFERENCE = 'domain-inactive';
    public const DOMAIN_INTERNAL_REFERENCE = 'domain-internal';

    public function load(ObjectManager $manager): void
    {
        $google = new Domain();
        $google->setFqdn('google.com');
        $google->setPort(443);
        $google->setDescription('Google – Referenz-Domain für gültige Zertifikate');
        $google->setStatus(DomainStatus::Active->value);
        $manager->persist($google);
        $this->addReference(self::DOMAIN_GOOGLE_REFERENCE, $google);

        $github = new Domain();
        $github->setFqdn('github.com');
        $github->setPort(443);
        $github->setDescription('GitHub – Code-Hosting-Plattform');
        $github->setStatus(DomainStatus::Active->value);
        $manager->persist($github);
        $this->addReference(self::DOMAIN_GITHUB_REFERENCE, $github);

        $expired = new Domain();
        $expired->setFqdn('expired.badssl.com');
        $expired->setPort(443);
        $expired->setDescription('BadSSL – abgelaufenes Zertifikat (Test-Domain)');
        $expired->setStatus(DomainStatus::Active->value);
        $manager->persist($expired);
        $this->addReference(self::DOMAIN_EXPIRED_REFERENCE, $expired);

        $inactive = new Domain();
        $inactive->setFqdn('disabled-monitor.internal');
        $inactive->setPort(443);
        $inactive->setDescription('Deaktivierte interne Domain');
        $inactive->setStatus(DomainStatus::Inactive->value);
        $manager->persist($inactive);
        $this->addReference(self::DOMAIN_INACTIVE_REFERENCE, $inactive);

        $internal = new Domain();
        $internal->setFqdn('intranet.example.local');
        $internal->setPort(8443);
        $internal->setDescription('Internes Intranet-Portal auf Port 8443');
        $internal->setStatus(DomainStatus::Active->value);
        $manager->persist($internal);
        $this->addReference(self::DOMAIN_INTERNAL_REFERENCE, $internal);

        $manager->flush();
    }
}
