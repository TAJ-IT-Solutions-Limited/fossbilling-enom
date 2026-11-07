<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fossbilling-0.8.9/support-stubs.php';
require __DIR__ . '/fossbilling-0.8.9/Exception.php';
require __DIR__ . '/fossbilling-0.8.9/Contact.php';
require __DIR__ . '/fossbilling-0.8.9/Domain.php';
require __DIR__ . '/fossbilling-0.8.9/AdapterAbstract.php';
require dirname(__DIR__) . '/src/library/Registrar/Adapter/Enom.php';

function enom_test_contact(array $fields = []): Registrar_Domain_Contact
{
    $fields = array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'address1' => '1 Example Road',
        'city' => 'Abu Dhabi',
        'state' => 'AZ',
        'zip' => '00001',
        'country' => 'AE',
        'telCc' => '971',
        'tel' => '500000000',
    ], $fields);

    $contact = new Registrar_Domain_Contact();
    $contact->setName($fields['name']);
    $contact->setEmail($fields['email']);
    $contact->setAddress1($fields['address1']);
    $contact->setCity($fields['city']);
    $contact->setState($fields['state']);
    $contact->setZip($fields['zip']);
    $contact->setCountry($fields['country']);
    $contact->setTelCc($fields['telCc']);
    $contact->setTel($fields['tel']);

    return $contact;
}

function enom_test_domain(?Registrar_Domain_Contact $contact = null): Registrar_Domain
{
    $domain = new Registrar_Domain();
    $domain->setSld('example');
    $domain->setTld('com');
    $domain->setEpp('epp-code');
    $domain->setNs1('ns1.example.net');
    $domain->setNs2('ns2.example.net');
    $domain->setContactRegistrar($contact ?? enom_test_contact());

    return $domain;
}
