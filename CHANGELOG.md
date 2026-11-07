# Changelog

## 1.0.2

- Enom calls are POST bodies. The reseller password is not put in the URL.
- A response with no `ErrCount` is rejected instead of treated as success.
- Purchase, Extend, TP_CreateOrder, and ModifyNS require `Done=true` when that field is present, and an `RRPCode` of 200 or 210 when that field is present.
- Transfers send `OrderType`, `DomainCount`, and indexed `SLD1` / `TLD1` / `AuthInfo1`.
- Registration no longer fills a missing country, state, or postal code.
- `GetDomainInfo` asks for XML and reads nameservers from the documented `dnsserver` entries.
- The registrar form no longer has its own sandbox checkbox. FOSSBilling test mode selects the OTE host.

## 1.0.1

- First packaged registrar adapter.
