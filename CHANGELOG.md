# Changelog

## 1.0.2

- Enom calls are POST bodies. The API token is not put in the URL. POST itself is not in the Enom docs, so release waits for a read-only check.
- A response with no `ErrCount` is rejected instead of treated as success.
- Purchase, Extend, TP_CreateOrder, and ModifyNS require `Done=true` when that field is present. `RRPCode` 200, 210, and 1300 are success. 1300 is a queued registration.
- A purchase always sends the full registrant set. An empty state uses the client city. Countries AE, HK, and QA with no postal code send `POSTAL_CODE_NONE` (`00000`), which OTE still has to confirm. Ireland is not in that list.
- A transfer sends the registrant block only when every core field is already present. `statusid` 100 or "Pending consent" is success.
- Transfers send `OrderType`, `DomainCount`, and indexed `SLD1` / `TLD1` / `AuthInfo1`.
- `GetDomainInfo` asks for XML and reads nameservers from the documented `dnsserver` entries.
- The registrar form no longer has its own sandbox checkbox. FOSSBilling test mode selects the OTE host.

## 1.0.1

- First packaged registrar adapter.
