# Changelog

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
