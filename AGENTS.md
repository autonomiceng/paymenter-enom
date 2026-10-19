# Developer guide

Read [CONTRIBUTING](CONTRIBUTING.md) for setup, test and release commands; [testing](docs/testing.md) for suite boundaries; and [extension conventions](docs/paymenter-extension-conventions.md) before native integration or packaging changes.
For domain behavior, read [catalogue and pricing](docs/design/catalogue-and-pricing.md), [ordering](docs/design/ordering.md) or [lifecycle](docs/design/domain-lifecycle.md) for the area being changed.
Runtime belongs in `Enom/`; keep native core and table definitions upstream. Preserve payment and request evidence, current ownership, encrypted credentials and unresolved provider outcomes. Use synthetic fixtures and disposable databases; keep secrets and customer records out of artifacts and logs.
Paymenter must stay upgradable without extension releases. Follow [Keeping Paymenter upgradable](docs/paymenter-extension-conventions.md#keeping-paymenter-upgradable): no container rebinding, native route remaps, global hooks or version gates.
