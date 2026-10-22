# FOSSBilling Enom registrar

Enom reseller adapter for FOSSBilling 0.8. FOSSBilling does not ship an Enom adapter. This one reads the reseller UID and password from the registrar form in the database. It does not contain a reseller login.

Copy `src/library/Registrar/Adapter/Enom.php` to the same path inside FOSSBilling.

## Commands

The adapter implements availability, register, transfer, renew, nameserver changes, lock and unlock, the EPP code, and expiry sync. Expiry is read from `GetDomainInfo > status > expiration` when Enom does not send a top-level date.

Replies may be Enom's key=value text (including the `;URL Interface` preamble), XML, HTML, or JSON. An Enom error such as an open order is returned as the registrar error text. Test mode sends commands to `https://resellertest.enom.com/interface.asp`.

## Tests

`php tests/EnomParseTest.php` replays recorded responses. It does not call Enom.

## Directory

`manifest.json` is prepared for the FOSSBilling directory. It has not been submitted.

## License

Apache-2.0
