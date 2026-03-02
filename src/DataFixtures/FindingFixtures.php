<?php

namespace App\DataFixtures;

use App\Entity\Finding;
use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\Severity;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class FindingFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [DomainFixtures::class, ScanRunFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $google    = $this->getReference(DomainFixtures::DOMAIN_GOOGLE_REFERENCE, \App\Entity\Domain::class);
        $github    = $this->getReference(DomainFixtures::DOMAIN_GITHUB_REFERENCE, \App\Entity\Domain::class);
        $expired   = $this->getReference(DomainFixtures::DOMAIN_EXPIRED_REFERENCE, \App\Entity\Domain::class);
        $internal  = $this->getReference(DomainFixtures::DOMAIN_INTERNAL_REFERENCE, \App\Entity\Domain::class);
        $runOk     = $this->getReference(ScanRunFixtures::SCAN_RUN_SUCCESS_REFERENCE, \App\Entity\ScanRun::class);
        $runPartial = $this->getReference(ScanRunFixtures::SCAN_RUN_PARTIAL_REFERENCE, \App\Entity\ScanRun::class);

        // google.com – OK
        $googleOk = new Finding();
        $googleOk->setDomain($google);
        $googleOk->setScanRun($runOk);
        $googleOk->setFindingType(FindingType::Ok->value);
        $googleOk->setSeverity(Severity::Ok->value);
        $googleOk->setStatus(FindingStatus::Resolved->value);
        $googleOk->setDetails([
            'protocol'       => 'TLSv1.3',
            'cipher_name'    => 'TLS_AES_256_GCM_SHA384',
            'cipher_bits'    => 256,
            'public_key_type' => 'EC',
            'public_key_bits' => 256,
            'valid_to'       => date('Y-m-d', strtotime('+365 days')),
            'days_remaining' => 365,
            'subject'        => 'CN=*.google.com',
        ]);
        $manager->persist($googleOk);

        // github.com – OK
        $githubOk = new Finding();
        $githubOk->setDomain($github);
        $githubOk->setScanRun($runOk);
        $githubOk->setFindingType(FindingType::Ok->value);
        $githubOk->setSeverity(Severity::Ok->value);
        $githubOk->setStatus(FindingStatus::Resolved->value);
        $githubOk->setDetails([
            'protocol'       => 'TLSv1.3',
            'cipher_name'    => 'TLS_AES_128_GCM_SHA256',
            'cipher_bits'    => 128,
            'public_key_type' => 'RSA',
            'public_key_bits' => 2048,
            'valid_to'       => date('Y-m-d', strtotime('+180 days')),
            'days_remaining' => 180,
            'subject'        => 'CN=github.com',
        ]);
        $manager->persist($githubOk);

        // expired.badssl.com – CERT_EXPIRY critical
        $expiredFinding = new Finding();
        $expiredFinding->setDomain($expired);
        $expiredFinding->setScanRun($runOk);
        $expiredFinding->setFindingType(FindingType::CertExpiry->value);
        $expiredFinding->setSeverity(Severity::Critical->value);
        $expiredFinding->setStatus(FindingStatus::New->value);
        $expiredFinding->setDetails([
            'expiry_date'    => '2015-04-12',
            'days_remaining' => -3972,
            'subject'        => 'CN=*.badssl.com',
        ]);
        $manager->persist($expiredFinding);

        // expired.badssl.com – TLS_VERSION high (old TLS 1.0)
        $tlsVersionFinding = new Finding();
        $tlsVersionFinding->setDomain($expired);
        $tlsVersionFinding->setScanRun($runPartial);
        $tlsVersionFinding->setFindingType(FindingType::TlsVersion->value);
        $tlsVersionFinding->setSeverity(Severity::High->value);
        $tlsVersionFinding->setStatus(FindingStatus::Known->value);
        $tlsVersionFinding->setDetails([
            'protocol' => 'TLSv1.0',
            'message'  => 'Veraltetes TLS-Protokoll erkannt',
        ]);
        $manager->persist($tlsVersionFinding);

        // intranet.example.local – UNREACHABLE
        $unreachableFinding = new Finding();
        $unreachableFinding->setDomain($internal);
        $unreachableFinding->setScanRun($runPartial);
        $unreachableFinding->setFindingType(FindingType::Unreachable->value);
        $unreachableFinding->setSeverity(Severity::Medium->value);
        $unreachableFinding->setStatus(FindingStatus::New->value);
        $unreachableFinding->setDetails([
            'error'   => 'Connection refused',
            'timeout' => 10,
        ]);
        $manager->persist($unreachableFinding);

        // github.com – RSA_KEY_LENGTH low (weak key in older scan)
        $rsaFinding = new Finding();
        $rsaFinding->setDomain($github);
        $rsaFinding->setScanRun($runPartial);
        $rsaFinding->setFindingType(FindingType::RsaKeyLength->value);
        $rsaFinding->setSeverity(Severity::Low->value);
        $rsaFinding->setStatus(FindingStatus::Resolved->value);
        $rsaFinding->setDetails([
            'key_bits' => 1024,
            'message'  => 'RSA-Schlüssellänge unter 2048 Bit',
        ]);
        $manager->persist($rsaFinding);

        $manager->flush();
    }
}
