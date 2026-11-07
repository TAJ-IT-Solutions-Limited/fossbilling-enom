<?php

declare(strict_types=1);

class Registrar_Exception extends Exception
{
}

class Registrar_AdapterAbstract
{
    public function enableTestMode()
    {
        return true;
    }

    public function getLog(): object
    {
        return new class {
            public function debug(string $message): void
            {
            }

            public function err(string $message): void
            {
            }

            public function info(string $message): void
            {
            }
        };
    }
}

class Registrar_Domain_Contact
{
    public function __construct(
        public string $name = 'Ada Lovelace',
        public string $email = 'ada@example.com',
        public string $address1 = '1 Example Road',
        public string $city = 'Abu Dhabi',
        public string $state = 'AZ',
        public string $zip = '00001',
        public string $country = 'AE',
        public string $telCc = '971',
        public string $tel = '500000000',
    ) {
    }

    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getAddress1(): string { return $this->address1; }
    public function getAddress2(): string { return ''; }
    public function getCity(): string { return $this->city; }
    public function getState(): string { return $this->state; }
    public function getZip(): string { return $this->zip; }
    public function getCountry(): string { return $this->country; }
    public function getTelCc(): string { return $this->telCc; }
    public function getTel(): string { return $this->tel; }
}

class Registrar_Domain
{
    public ?int $registrationTime = 1710000000;
    public ?int $expirationTime = null;
    public ?string $status = null;
    public array $nameservers = [];

    public function __construct(public Registrar_Domain_Contact $contact = new Registrar_Domain_Contact())
    {
    }

    public function getSld(): string { return 'example'; }
    public function getTld(bool $withDot = true): string { return $withDot ? '.com' : 'com'; }
    public function getEpp(): string { return 'epp-code'; }
    public function getRegistrationPeriod(): int { return 1; }
    public function getContactRegistrar(): Registrar_Domain_Contact { return $this->contact; }
    public function getNs1(): string { return 'ns1.example.com'; }
    public function getNs2(): string { return 'ns2.example.com'; }
    public function getNs3(): string { return ''; }
    public function getNs4(): string { return ''; }
    public function setRegistrationTime(?int $ts): void { $this->registrationTime = $ts; }
    public function setNs1(string $value): void { $this->nameservers[1] = $value; }
    public function setNs2(string $value): void { $this->nameservers[2] = $value; }
    public function setNs3(string $value): void { $this->nameservers[3] = $value; }
    public function setNs4(string $value): void { $this->nameservers[4] = $value; }
    public function setExpirationTime(int $ts): void { $this->expirationTime = $ts; }
    public function setStatus(string $status): void { $this->status = $status; }
}

require dirname(__DIR__) . '/src/library/Registrar/Adapter/Enom.php';
