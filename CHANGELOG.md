# Changelog

## 1.0.3

- Availability: reads Enom's indexed keys (`RRPCode1`, `Available1`) as well as the plain ones, so an account that always indexes cannot make every name look taken.
- Nameservers: empty slots are no longer sent (`NS3=` / `NS4=`); `modifyNs` refuses a request that has no nameservers at all, so an empty delegation can never reach the registry.
- Transfers: the registrant country is uppercased, matching the registration path.
- Auth code: a refused `GetAuthCode` now returns an actionable message (Enom control panel, or send it with `SynchAuthInfo`) and error code `3004`, instead of the raw Enom error.
- Docs: corrected the 1.0.2 notes about the registration date and the nameserver sync. Neither can be fixed by the adapter alone — see "Known gaps" in the README and `docs/fossbilling-core-registered-at.patch`.
- CI: lint every PHP file and run `composer validate`, in addition to PHPUnit.

## 1.0.2 — Security and reliability update. All users should upgrade.

- Credentials are sent in the POST body, never in the URL.
- Replies that are not from Enom (no `ErrCount`) are rejected instead of treated as success.
- Purchase, renew, transfer and nameserver changes check Enom's `Done` and `RRPCode` (200/210/1300; 1300 is a queued registration).
- Transfers now work (correct `TP_CreateOrder` fields: `OrderType`, `DomainCount`, `SLD1`, `TLD1`, `AuthInfo1`).
- Registrations always send the client's full registrant details, so the reseller is never set as the owner. An empty state uses the client city; for AE, HK and QA an empty postal code is sent as `00000`.
- Nameserver and expiry-date sync fixed (no one-day shift; an empty registration date no longer clears a stored one).
- Supports Enom API tokens (recommended instead of the account password). The separate sandbox checkbox is gone; use FOSSBilling test mode.
- Compatible with the FOSSBilling 0.8.9 registrar contract (`getHttpClient()` signature).

Upgrade: replace the single `Enom.php` file.

## 1.0.1

- First packaged registrar adapter.
