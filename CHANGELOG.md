# Changelog

## 1.0.4

- Purchase, Extend, TP_CreateOrder, and ModifyNS fail closed unless Enom reports completion. A transfer order id still counts as completion when Done is omitted.
- A non-2xx HTTP status is a transport error and is not parsed as success.
- A timeout during Purchase or Extend looks the domain up with GetDomainInfo once and logs the OrderID. The paid command is not sent again.
- **EndUserIP for cron** is required. Cron and command-line purchases and renewals send that address. A browser request uses the visitor IP. After upgrading, fill that field in the Enom registrar settings, or cron renewals fail.
- A purchase with fewer than two nameservers sends `UseDNS=default`. A delegation update with fewer than two nameservers is refused.
- `php bin/package.php` builds `enom-<version>.zip` for the extensions directory: `library/Registrar/Adapter/Enom.php` at the archive root, documentation under `docs/`, and a generic `icon.svg`. There is no `src/` prefix and no wrapping folder. Directory entries are mode `0755` and files are mode `0644`.
- `GetAuthCode` failures are logged as warnings. The client still sees the contact-support message.
- Notes that FOSSBilling#4656 is fixed upstream for releases after 0.8.9. The 0.8.x workaround stays.

## 1.0.3

- Availability: reads Enom's indexed keys (`RRPCode1`, `Available1`) as well as the plain ones, so an account that always indexes cannot make every name look taken.
- Nameservers: empty slots are no longer sent (`NS3=` / `NS4=`); `modifyNs` refuses a request that has no nameservers at all, so an empty delegation can never reach the registry.
- Transfers: the registrant country is uppercased, matching the registration path.
- Auth code: when the code cannot be retrieved, the client sees a contact-support message and error code `3004`. Staff see the provider error and the panel or `SynchAuthInfo` hint in the log.
- CI: lint every PHP file and run `composer validate`, in addition to PHPUnit.
- A purchase with no nameservers sends `UseDNS=default`, because Enom requires `UseDNS` or `NSx`.
- Corrects two 1.0.2 notes (registration-date and nameserver persistence depend on FOSSBilling core, see Known gaps).

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
