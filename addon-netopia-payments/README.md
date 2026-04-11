# NETOPIA Payments — CS-Cart Addon (Distribution)

Self-contained CS-Cart addon package for NETOPIA Payments. This folder
mirrors the CS-Cart file layout and can be copied directly onto a CS-Cart
installation — no Composer step is required on the target server.

## Contents

```
addon-netopia-payments/
├── app/
│   ├── addons/netopia_payments/
│   │   ├── addon.xml
│   │   ├── init.php              # Registers the bundled PSR-4 autoloader
│   │   ├── autoload.php          # Stand-alone autoloader (no Composer)
│   │   ├── func.php              # Thin fn_netopia_* wrappers
│   │   ├── controllers/          # Backend controllers (payment link actions)
│   │   └── lib/                  # All PHP classes live here
│   │       ├── Bootstrap.php     # Service-container composition root
│   │       ├── Dto/              # Immutable DTOs (Address, CardData, …)
│   │       ├── Exception/        # Typed addon exceptions
│   │       ├── Http/             # ApiClient (cURL wrapper)
│   │       ├── Ipn/              # IpnVerifier + IpnHandler
│   │       ├── Key/              # KeyStorage (upload/read/delete)
│   │       ├── Log/              # CsCartLogger (PSR-3)
│   │       ├── Payment/          # PayloadBuilder, PaymentLinkService, …
│   │       ├── Session/          # ThreeDsSessionStore
│   │       ├── Status/           # StatusMapper
│   │       ├── Support/          # Clock, CountryCodes, Sanitizer
│   │       ├── ThreeDs/          # ThreeDsDataFactory, ThreeDsReturnHandler
│   │       ├── Sdk/              # Bundled SDK enums (Netopia\Payment2\Enum\*)
│   │       └── Psr/Log/          # Bundled psr/log interfaces + NullLogger
│   └── payments/
│       └── netopia_payments.php  # CS-Cart payment processor entry point
├── design/
│   ├── backend/
│   │   ├── mail/templates/addons/netopia_payments/
│   │   └── templates/...
│   └── themes/responsive/templates/addons/netopia_payments/
└── var/langs/en/addons/
    └── netopia_payments.po       # English translations
```

## Installation

1. Copy every file/folder under `addon-netopia-payments/` into your CS-Cart
   installation, preserving the directory layout (e.g. `app/addons/…` into
   the CS-Cart root's `app/addons/…`).
2. In CS-Cart admin, go to **Add-ons → Manage add-ons**, find
   **NETOPIA Payments**, and click **Install**.
3. Configure a payment method under **Administration → Payment methods**,
   set the processor to *NETOPIA Payments*, and fill in POS Signature,
   API key, and upload the sandbox/live public/private keys.

## Requirements

- CS-Cart 4.19.1 or later
- PHP 8.3 or later
- ext-curl, ext-json, ext-openssl

## Autoloading

The addon ships with a **stand-alone PSR-4 autoloader**
(`autoload.php`), registered from `init.php`. It maps:

| Namespace            | Directory          |
|----------------------|--------------------|
| `Netopia\CsCart\`    | `lib/`             |
| `Netopia\Payment2\`  | `lib/Sdk/`         |
| `Psr\Log\`           | `lib/Psr/Log/`     |

No Composer run is required on the target CS-Cart server — everything the
addon needs (including PSR-3 interfaces and the NETOPIA SDK enums) is
bundled inside `lib/`.
