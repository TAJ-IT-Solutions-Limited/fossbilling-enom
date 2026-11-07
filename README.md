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

- FOSSBilling **0.8.0** or newer.
- PHP 8.2 or newer.
- An Enom reseller account, with the billing server's public IP allowed in Enom's API settings.

## Installation

Installation is manual. FOSSBilling's extension installer does not place a domain-registrar file.

### Copy the adapter, then install it

1. Copy `src/library/Registrar/Adapter/Enom.php` from this repository to the same path inside FOSSBilling: `src/library/Registrar/Adapter/Enom.php`.
2. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.
3. Enter the reseller UID and API token. Use FOSSBilling's test mode when the account is an OTE account.

### Manually from the release archive

1. Download `fossbilling-enom-1.0.1.zip` from the [Releases](https://github.com/TAJ-IT-Solutions-Limited/fossbilling-enom/releases/tag/v1.0.1) page.
2. The archive has no wrapper folder. `Enom.php` is already at `src/library/Registrar/Adapter/Enom.php` inside the zip. Copy that file to the same path in the FOSSBilling installation.
3. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.

## Configuration

Go to **System → Domain registration → Registrars → Enom**.

| Field | Description |
|---|---|
| Enom UID | Reseller account ID (the login username). Required. |
| Enom API token | The `pw` value from the Enom account. Required. It is not written to logs. Requests are sent as a POST body, so the token is not placed in the URL. Enom's docs show the token in the query string and do not document POST. This release stays blocked until a read-only POST `GetBalance` check, or the OTE test, succeeds. |

FOSSBilling's **Enable Test Mode** sends commands to `https://resellertest.enom.com/interface.asp`. There is no separate sandbox checkbox.

In the Enom account, open the API settings and whitelist the public IP of the server that runs FOSSBilling. Enom checks that server IP. The visitor's address is sent separately as `EndUserIP` and does not replace the whitelist.

## Read-only live check

Simon runs this once on the billing server. It talks to the live Enom host only. The process can send `GetBalance`, `Check`, and `GetDomainInfo`. `Purchase`, `Extend`, `TP_CreateOrder`, and `ModifyNS` are refused before a request is made.

Put the UID and API token in the environment for that command. Do not pass them as arguments. Then clear the shell history:

```bash
ENOM_UID='the-uid' ENOM_TOKEN='the-token' php tools/live_readonly_check.php /path/to/fossbilling name.tld existing.name.tld
history -c
```

`name.tld` is the name to check for availability. `existing.name.tld` is a name already in the reseller account. The first argument is the FOSSBilling install, used only to find Symfony HttpClient. If that library is missing, the script uses curl.

The script prints `balance_ok`, `check`, `expiry`, `ns`, and `registered_at_kept`. An error line is Enom's message with the UID and token removed. It does not print the balance amount or the token.

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

### 1.0.1

- README aligned with the directory listing: installation, configuration, the command table, limitations, troubleshooting, and the company line below.
- Release archive places `Enom.php` at `src/library/Registrar/Adapter/Enom.php`, with no wrapper folder.
- `manifest.json` uses type `domain-registrar` and sets `download_url` to this release.
- Adapter behavior is unchanged from 1.0.0.

### 1.0.0

- First public release of the adapter.

## About TAJHOST

[TAJHOST](https://tajhost.com) provides cPanel hosting, domain registration, SSL certificates and business email, together with IT infrastructure and security services. TAJHOST is a brand of TAJ IT Solutions Limited (registered in Malta, company no. C 117054). For questions or bug reports, open an issue or contact us through [tajhost.com](https://tajhost.com).

This adapter's icon is `assets/icon.svg`. The HostAssistant product icon is separate: https://hostassistant.com/assets/fossbilling-icon.svg.

## License

Apache License 2.0. See [LICENSE](LICENSE).
