# Reporting Security Issues

Please do not open a public issue or pull request for a security problem.

**Issues in this package** should be reported privately through GitHub's
["Report a vulnerability"](../../security/advisories/new) form on this repository. There is no
paid bounty.

**Issues that also affect Magento Open Source** — including anything in
`Magento\Framework\Filter\Template` itself, which this package exists to replace — should go to
Adobe first, via their [bug bounty program](https://hackerone.com/adobe) or `psirt@adobe.com`,
with `security@mage-os.org` copied so Mage-OS can track the downstream fix. Fixes released
through that program reach Magento as a security patch and are then incorporated into Mage-OS.
Please report those there even if you found them through this package.

Please include repro steps. For this package, a template and a variable set that demonstrate
the behaviour are usually enough.

## Scope

The property this package claims is that a variable's value is never parsed as template
source, at any depth, in any mode, under any modifier. A way to defeat that is a security
issue here, and so is a way past `RenderPolicy`, `PathGuard`, or the pre-instantiation type
checks in `src/Magento/`.

Compatible mode deliberately reproduces some of the legacy filter's unsafe behaviour, such as
fail-open unknown modifiers. That is documented in the README and is the reason `strict` and
`lenient` exist; it is not a bug in this package. A case where compatible mode is *more*
permissive than the legacy filter is a bug.
