# Enom registrar for FOSSBilling

Enom reseller adapter for [FOSSBilling](https://fossbilling.org). FOSSBilling does not ship an Enom adapter. This one registers, transfers, renews, and syncs domains through Enom's reseller API.

The reseller UID and password are entered in the registrar form and stored by FOSSBilling. They are not in this repository. The tests replay recorded responses and do not call Enom.

Built and maintained by [TAJHOST](https://tajhost.com).

## Features

- Domain availability check (`Check`).
- Registration (`Purchase`) using the client profile as the registrant contact.
- Inbound transfer (`TP_CreateOrder`) with the auth (EPP) code.
- Renewal (`Extend`).
- Expiry and registration-date sync (`GetDomainInfo`).
- Nameserver updates (`ModifyNS`).
- Auth-code retrieval (`GetAuthCode`).
- Connection test (`GetBalance`), which does not purchase a domain.
- Replies may be Enom's key=value text (including the `;URL Interface` preamble), XML, HTML, or JSON. An Enom error is returned as the registrar error text.

## Requirements

- FOSSBilling **0.8.0** or newer.
- PHP 8.3+, which FOSSBilling already requires.
- An Enom reseller account, with the billing server's public IP allowed in Enom's API settings.

## Installation

FOSSBilling's extension installer places modules, themes, translations, and payment gateways. It does not place a domain-registrar file, so this adapter is installed by copying one file and then installing it from the domain-registration page.

### Copy the adapter, then install it

1. Copy `src/library/Registrar/Adapter/Enom.php` from this repository to the same path inside FOSSBilling: `src/library/Registrar/Adapter/Enom.php`.
2. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.
3. Enter the reseller UID and password, then use **Test connection**.

### Manually from the release archive

1. Download `fossbilling-enom-1.0.1.zip` from the [Releases](https://github.com/TAJ-IT-Solutions-Limited/fossbilling-enom/releases/tag/v1.0.1) page.
2. The archive has no wrapper folder. `Enom.php` is already at `src/library/Registrar/Adapter/Enom.php` inside the zip. Copy that file to the same path in the FOSSBilling installation.
3. In the admin panel, go to **System → Domain registration → Registrars → Enom → Install**.

## Configuration

Go to **System → Domain registration → Registrars → Enom**.

| Field | Description |
|---|---|
| Enom UID | Reseller account ID (the login username). Required. |
| Enom PW | API password. Required. It is not written to logs. |
| Use test (OTE) environment | When on, commands go to `https://resellertest.enom.com/interface.asp` instead of `https://reseller.enom.com/interface.asp`. |

FOSSBilling's own **Enable Test Mode** also sends commands to the test host.

In the Enom account, open the API settings and whitelist the public IP of the server that runs FOSSBilling. Enom checks that server IP. The visitor's address is sent separately as `EndUserIP` and does not replace the whitelist.

## Supported commands

| FOSSBilling action | Enom command | Result |
|---|---|---|
| Availability | `Check` | Available when `RRPCode` is 210, otherwise the `Available` flag |
| Transfer eligibility | `Check` | Treated as eligible when the name is not available for a new registration |
| Register | `Purchase` | Registrant fields are taken from the client profile |
| Transfer | `TP_CreateOrder` | Requires the auth (EPP) code |
| Renew | `Extend` | Uses the registration period on the order |
| Sync details | `GetDomainInfo` | Expiry is read from `ExpiryDate`, or from `GetDomainInfo > status > expiration` |
| Nameservers | `ModifyNS` | Up to four nameservers |
| Auth code | `GetAuthCode` | Returned to FOSSBilling as the EPP code |
| Test connection | `GetBalance` | Checks the UID, password, and that the reply parses. No purchase |

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
