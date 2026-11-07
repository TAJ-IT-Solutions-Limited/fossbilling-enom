# Enom registrar for FOSSBilling

Enom reseller adapter for [FOSSBilling](https://fossbilling.org). FOSSBilling does not ship an Enom adapter. This one registers, transfers, renews, and syncs domains through Enom's reseller API.

The reseller UID and API token are entered in the registrar form and stored by FOSSBilling. They are not in this repository. The tests replay recorded responses and do not call Enom.

Built and maintained by [TAJHOST](https://tajhost.com).

## Features

- Domain availability check (`Check`).
- Registration (`Purchase`) using the client profile as the registrant contact.
- Inbound transfer (`TP_CreateOrder`) with the auth (EPP) code.
- Renewal (`Extend`).
- Expiry and registration-date sync (`GetDomainInfo`).
- Nameserver updates (`ModifyNS`).
- Auth-code retrieval (`GetAuthCode`).
- Replies may be Enom's key=value text (including the `;URL Interface` preamble), XML, HTML, or JSON. A reply with no `ErrCount` is rejected. An Enom error is returned as the registrar error text.

## Requirements

- FOSSBilling 0.8.x.
- PHP 8.2 or newer.
- An Enom reseller account, with the billing server's public IP allowed in Enom's API settings.

Upgrading from 1.0.0 or 1.0.1: replace the single `Enom.php` file. The old sandbox checkbox is gone. Use FOSSBilling test mode. An Enom API token is recommended instead of the account password.

## Installation

Installation is manual. FOSSBilling's extension installer does not place a domain-registrar file.

### Copy the adapter, then install it

1. Copy `src/library/Registrar/Adapter/Enom.php` from this repository to `library/Registrar/Adapter/Enom.php` in the FOSSBilling installation (`src/library/Registrar/Adapter/Enom.php` if you run FOSSBilling from a source checkout). Keep a copy of the old file until you have tested.
2. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.
3. Enter the reseller UID and API token. Use FOSSBilling's test mode when the account is an OTE account.

### Manually from the release archive

1. Download `fossbilling-enom-1.0.2.zip` from the [v1.0.2 release](https://github.com/TAJ-IT-Solutions-Limited/fossbilling-enom/releases/tag/v1.0.2).
2. The archive has no wrapper folder. `Enom.php` is already at `src/library/Registrar/Adapter/Enom.php` inside the zip. Copy that file to `library/Registrar/Adapter/Enom.php` in the FOSSBilling installation (`src/library/...` for a source checkout).
3. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.

## Configuration

Go to **System → Domain registration → Registrars → Enom**.

| Field | Description |
|---|---|
| Enom UID | Reseller account ID (the login username). Required. |
| Enom API token | The `pw` value from the Enom account. Required. It is not written to logs. Requests are sent as a POST body (verified against the live Enom API), so the token is never in the URL. |

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
| Sync details | `GetDomainInfo` | XML reply. Expiry is read from `ExpiryDate`, or from `status > expiration`. Nameservers are read from `services/entry[name=dnsserver]/configuration/dns`. An empty registration date does not clear one already stored. |
| Nameservers | `ModifyNS` | Up to four nameservers |
| Auth code | `GetAuthCode` | Returned to FOSSBilling as the EPP code |

## Known limitations

- **No premium domains.** The adapter does not request or price premium names. A premium result from `Check` is not turned into a special price.
- **Contacts come from the client profile.** Registration sends the registrant name, email, address, and phone from that profile. Changing the contact later is not implemented.
- **Not implemented:** domain deletion, WHOIS privacy, and registry lock or unlock. Those actions return a clear registrar error.

## Troubleshooting

The adapter returns Enom's `Err1` text as the registrar error.

- **Domain name not found.** `GetDomainInfo` was asked for a name that is not in this reseller account. Confirm the domain is registered under the same Enom UID configured in FOSSBilling.
- **Open order.** Enom already has a purchase or transfer order open for that name. Wait until the order completes, or cancel it in Enom, then retry. The adapter does not rewrite this message.
- **IP not whitelisted.** Enom rejected the call because the billing server's public IP is not in the account's API allow list. Add that IP in Enom's API settings. The request is sent by the FOSSBilling server, not by the visitor's browser.

## Changelog

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
