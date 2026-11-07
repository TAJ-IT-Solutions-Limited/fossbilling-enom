# OTE checklist before tagging a release

`tools/live_readonly_check.php` can only send `GetBalance`, `Check` and
`GetDomainInfo` by design. That means the write paths — the ones that cost money
and change a customer's domain — are never exercised by CI. Run this list on
`resellertest.enom.com` (FOSSBilling **Enable Test Mode** on the Enom registrar)
before every release, and record the date and the result in the release notes.

Do not run this against the production account.

| # | Test | Command under test | Expected |
|---|---|---|---|
| 1 | Connect | `GetBalance` | `ok`, balance returned, `parsed_as=text` |
| 2 | Availability, free name | `Check` | `Available` / `RRPCode=210` → the order page offers the domain |
| 3 | Availability, taken name | `Check` | `RRPCode=211` → the order page reports it as taken |
| 4 | Register a `.com` | `Purchase` | Domain appears in the Enom control panel; `registered_at`/`expires_at` filled |
| 5 | Register with only two nameservers | `Purchase` | Accepted; `NS3`/`NS4` are **not** sent (inspect the request log) |
| 6 | Register with a client profile that has no state (AE) | `Purchase` | `RegistrantStateProvince` = city, `RegistrantPostalCode` = `00000`, accepted |
| 7 | Register with a client profile that has no postal code (US) | `Purchase` | Refused locally with "Registrant PostalCode is required" — no call leaves the server |
| 8 | Renew for 1 year | `Extend` | `RRPCode=200`; expiry moves forward exactly one year, same calendar day |
| 9 | Change nameservers to two hosts | `ModifyNS` | Accepted; `NS3`/`NS4` absent from the request |
| 10 | Transfer in with a valid auth code | `TP_CreateOrder` | `transferorderid` returned, or status `Pending consent` |
| 11 | Transfer in with a wrong auth code | `TP_CreateOrder` | Enom error text surfaced to the client, order stays pending/failed (never "active") |
| 12 | Auth code retrieval | `GetAuthCode` | Either the code is returned, or the actionable `3004` message is shown. Record which one so the README stays accurate |
| 13 | Full sync | `GetDomainInfo` | Expiry unchanged on the same calendar day; `ns1`/`ns2` populated on the domain object; `registered_at` unchanged (with the core patch) |
| 14 | Failure path | any command with a wrong token | `unexpected response: check credentials / IP allow-list / WAF`, no order is created |

## Notes

- Test 11 matters most: before 1.0.2 a refused transfer could be recorded as
  successful. Confirm the order does **not** become active.
- For test 4 and 8, delete the test domain or let it expire in the OTE account.
  OTE domains cost nothing but clutter the panel.
- Keep the request log for tests 5 and 9: Enom accepts a padded request on most
  TLDs, so the only proof that the empty slots are gone is the outgoing body.
