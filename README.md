# Enom registrar for FOSSBilling

Enom reseller adapter for [FOSSBilling](https://fossbilling.org). FOSSBilling does not ship an Enom adapter. This one registers, transfers, renews, and syncs domains through Enom's reseller API.

The reseller UID and API token are entered in the registrar form and stored by FOSSBilling. They are not in this repository. The tests replay recorded responses and do not call Enom.

Built and maintained by [TAJHOST](https://tajhost.com).

## Features

- Domain availability check (`Check`).
- Registration (`Purchase`) using the client profile as the registrant contact.
- Inbound transfer (`TP_CreateOrder`) with the auth (EPP) code.
- Renewal (`Extend`).
- Expiry sync (`GetDomainInfo`). A registration date is written only when Enom returns one, which it currently does not — see "Known gaps".
- Nameserver updates (`ModifyNS`). Empty slots are omitted, and a request with no nameservers is refused.
- Auth-code retrieval (`GetAuthCode`). The client sees a contact-support message and staff see the details in the log.
- Replies may be Enom's key=value text (including the `;URL Interface` preamble), XML, HTML, or JSON. A reply with no `ErrCount` is rejected. An Enom error is returned as the registrar error text.

## Requirements

- FOSSBilling 0.8.x.
- PHP 8.2 or newer.
- An Enom reseller account, with the billing server's public IP allowed in Enom's API settings.

Upgrading from 1.0.0, 1.0.1 or 1.0.2: replace the single `Enom.php` file. The old sandbox checkbox is gone. Use FOSSBilling test mode. An Enom API token is recommended instead of the account password.

## Installation

Installation is manual. FOSSBilling's extension installer does not place a domain-registrar file.

### Copy the adapter, then install it

1. Copy `src/library/Registrar/Adapter/Enom.php` from this repository to `library/Registrar/Adapter/Enom.php` in the FOSSBilling installation (`src/library/Registrar/Adapter/Enom.php` if you run FOSSBilling from a source checkout). Keep a copy of the old file until you have tested.
2. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.
3. Enter the reseller UID and API token. Use FOSSBilling's test mode when the account is an OTE account.

### Manually from the release archive

1. Download `fossbilling-enom-1.0.3.zip` from the [v1.0.3 release](https://github.com/TAJ-IT-Solutions-Limited/fossbilling-enom/releases/tag/v1.0.3).
2. The archive has no wrapper folder. Copy `src/library/Registrar/Adapter/Enom.php` to `library/Registrar/Adapter/Enom.php` in a packaged FOSSBilling installation (`src/library/Registrar/Adapter/Enom.php` for a source checkout). README, CHANGELOG, LICENSE, and the other documentation are under `docs/` so extracting the zip at the FOSSBilling root does not overwrite core files.
3. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.

## Configuration

Go to **System → Domain registration → Registrars → Enom**.

| Field | Description |
|---|---|
| Enom UID | Reseller account ID (the login username). Required. |
| Enom API token | The `pw` value from the Enom account. Required. It is not written to logs. Requests are sent as a POST body (verified against the live Enom API for `GetBalance`, `Check`, `GetDomainInfo` and a real `Extend`; the other write commands are covered by recorded-response tests. `docs/ote-checklist.md` lists the OTE run to do when test access is available), so the token is never in the URL. |

FOSSBilling's **Enable Test Mode** sends commands to `https://resellertest.enom.com/interface.asp`. There is no separate sandbox checkbox.

In the Enom account, open the API settings and whitelist the public IP of the server that runs FOSSBilling. Enom checks that server IP. The visitor's address is sent separately as `EndUserIP` and does not replace the whitelist.

## For developers (repository only, not in the release zip)

### Read-only live check

`tools/live_readonly_check.php` lets you check an installation against the live Enom API without changing anything. It can send only `GetBalance`, `Check`, and `GetDomainInfo`. `Purchase`, `Extend`, `TP_CreateOrder`, and `ModifyNS` are refused before a request is made.

Put the UID and API token in the environment for that command. Do not pass them as arguments. Then clear the shell history:

```bash
ENOM_UID='the-uid' ENOM_TOKEN='the-token' php tools/live_readonly_check.php /path/to/fossbilling name.tld existing.name.tld
history -c
```

`name.tld` is a name to check for availability. `existing.name.tld` is a name already in the reseller account. The script loads the FOSSBilling installation before the adapter and does not load `config.php`. It prints `balance_ok`, `check`, `expiry`, `ns`, and `registered_at_kept`, never the balance amount or the token.

## Supported commands

| FOSSBilling action | Enom command | Result |
|---|---|---|
| Availability | `Check` | Available when `RRPCode` is 210, otherwise the `Available` flag |
| Transfer eligibility | `Check` | Treated as eligible when the name is not available for a new registration |
| Register | `Purchase` | Registrant fields are taken from the client profile |
| Transfer | `TP_CreateOrder` | Requires the auth (EPP) code |
| Renew | `Extend` | Uses the registration period on the order |
| Sync details | `GetDomainInfo` | XML reply. Expiry is read from `ExpiryDate`, or from `status > expiration`. Nameservers are read from `services/entry[name=dnsserver]/configuration/dns` and set on the domain object; FOSSBilling 0.8.x does not persist them yet (see "Known gaps"). |
| Nameservers | `ModifyNS` | Up to four nameservers; empty slots are omitted |
| Auth code | `GetAuthCode` | Returned to FOSSBilling as the EPP code. When it cannot be retrieved, the client sees a contact-support message and error code `3004`. Staff see the details in the log |

## Known limitations

- **No premium domains.** The adapter does not request or price premium names. A premium result from `Check` is not turned into a special price.
- **Contacts come from the client profile.** Registration sends the registrant name, email, address, and phone from that profile. Changing the contact later is not implemented.
- **Not implemented:** domain deletion, WHOIS privacy, and registry lock or unlock. Those actions return a clear registrar error.

## Known gaps

These are limitations of the FOSSBilling core or of Enom's API, not of the adapter. They are listed so nobody has to rediscover them in production.

| Gap | Why | Workaround |
|---|---|---|
| `registered_at` is cleared on every sync | `ServiceDomain\Service::syncWhois()` writes `formatRegistrarTimestamp($whois->getRegistrationTime())` unconditionally, and Enom's `GetDomainInfo` has no creation date, so the adapter returns `null` and the stored date is overwritten with `null`. | Proposed upstream fix only, in `docs/fossbilling-core-registered-at.patch`. It is for FOSSBilling maintainers. Do not apply it to a production billing install from this package. Reported upstream as [FOSSBilling#4656](https://github.com/FOSSBilling/FOSSBilling/issues/4656). Fixed upstream in FOSSBilling#4656; resolved in the first FOSSBilling release after 0.8.9. |
| Nameservers are not persisted from a sync | The adapter fills `setNs1()`…`setNs4()`, but `syncWhois()` never reads them. Delegation changes made in the Enom control panel therefore stay invisible in FOSSBilling. | Update nameservers from FOSSBilling, which writes both sides. A core change would be needed before a sync can store them. Reported upstream as [FOSSBilling#4656](https://github.com/FOSSBilling/FOSSBilling/issues/4656). Fixed upstream in FOSSBilling#4656; resolved in the first FOSSBilling release after 0.8.9. |
| `GetAuthCode` coverage | Enom exposes the auth code over the API for some accounts and TLDs only; the command is not in Enom's public command catalog. | The client sees a contact-support message and error code `3004`. Staff see the provider error and the panel or `SynchAuthInfo` hint in the log. |
| Write commands are not covered by the live check | `tools/live_readonly_check.php` refuses `Purchase`, `Extend`, `TP_CreateOrder` and `ModifyNS` by design, so those paths are only exercised against OTE. | Recorded-response tests in CI. Run `docs/ote-checklist.md` when OTE access is available. |

## Troubleshooting

The adapter returns Enom's `Err1` text as the registrar error.

- **Domain name not found.** `GetDomainInfo` was asked for a name that is not in this reseller account. Confirm the domain is registered under the same Enom UID configured in FOSSBilling.
- **Open order.** Enom already has a purchase or transfer order open for that name. Wait until the order completes, or cancel it in Enom, then retry. The adapter does not rewrite this message.
- **IP not whitelisted.** Enom rejected the call because the billing server's public IP is not in the account's API allow list. Add that IP in Enom's API settings. The request is sent by the FOSSBilling server, not by the visitor's browser.

## Changelog

### 1.0.4

- A purchase with fewer than two nameservers sends `UseDNS=default` and no `NSx`, and logs the domain and the count.
- A delegation update with fewer than two nameservers is refused and makes no request.
- `GetAuthCode` failures are logged as warnings. The client still sees the contact-support message.
- Notes that FOSSBilling#4656 is fixed upstream for releases after 0.8.9. The 0.8.x workaround stays.

### 1.0.3

- Availability also reads Enom's indexed keys (`RRPCode1`, `Available1`).
- Nameserver requests omit empty slots; a request with no nameservers is refused.
- Transfers uppercase the registrant country.
- A refused `GetAuthCode` tells the client to contact support and returns error code `3004`. Staff see the details in the log.
- CI lints every PHP file and runs `composer validate`.
- A purchase with no nameservers sends `UseDNS=default`, because Enom requires `UseDNS` or `NSx`.
- Corrects two 1.0.2 notes (registration-date and nameserver persistence depend on FOSSBilling core, see Known gaps).

### 1.0.2 — Security and reliability update. All users should upgrade.

- Credentials are sent in the POST body, never in the URL.
- Replies that are not from Enom (no `ErrCount`) are rejected instead of treated as success.
- Purchase, renew, transfer and nameserver changes check Enom's `Done` and `RRPCode` (200/210/1300; 1300 is a queued registration).
- Transfers now work (correct `TP_CreateOrder` fields: `OrderType`, `DomainCount`, `SLD1`, `TLD1`, `AuthInfo1`).
- Registrations always send the client's full registrant details, so the reseller is never set as the owner. An empty state uses the client city; for AE, HK and QA an empty postal code is sent as `00000`.
- Nameserver and expiry-date sync fixed (no one-day shift; an empty registration date no longer clears a stored one).
- Supports Enom API tokens (recommended instead of the account password). The separate sandbox checkbox is gone; use FOSSBilling test mode.
- Compatible with the FOSSBilling 0.8.9 registrar contract (`getHttpClient()` signature).

Upgrade: replace the single `Enom.php` file.

### 1.0.1

- README aligned with the directory listing: installation, configuration, the command table, limitations, troubleshooting, and the company line below.
- Release archive places `Enom.php` at `src/library/Registrar/Adapter/Enom.php`, with no wrapper folder.
- `manifest.json` uses type `domain-registrar` and sets `download_url` to this release.
- Adapter behavior is unchanged from 1.0.0.

### 1.0.0

- First public release of the adapter.

## About TAJHOST

[TAJHOST](https://tajhost.com) provides cPanel hosting, domain registration, SSL certificates and business email, together with IT infrastructure and security services. TAJHOST is a brand of TAJ IT Solutions Limited (registered in Malta, company no. C 117054). For questions or bug reports, open an issue or contact us through [tajhost.com](https://tajhost.com).

## License

Apache License 2.0. See [LICENSE](LICENSE).
