# Reporting Security Issues

Please do not open a public issue or pull request for a security problem.

**Issues in this package** go to the Mage-OS security team at `security@mage-os.org`. There is
no paid bounty for Mage-OS modules.

**Issues that also affect Magento Open Source** — including anything in
`Magento\Framework\Filter\Template` itself, which this package exists to replace — should go to
Adobe first, via their [bug bounty program](https://hackerone.com/adobe) or `psirt@adobe.com`,
with `security@mage-os.org` copied. Fixes released through that program reach Magento as a
security patch and are then incorporated into Mage-OS.

Please include repro steps. For this package specifically, a template and a variable set that
demonstrate the behaviour are usually enough.

## Scope

The property this package claims is that a variable's value is never parsed as template
source, at any depth, in any mode, under any modifier. A way to defeat that is a security
issue in this package, and so is a way past `RenderPolicy`, `PathGuard`, or the pre-instantiation
type checks in `src/Magento/`.

Compatible mode deliberately reproduces some of the legacy filter's unsafe behaviour, such as
fail-open unknown modifiers, and this is documented in the README. Those are not bugs in this
package; they are the reason `strict` and `lenient` exist. A case where compatible mode is
*more* permissive than the legacy filter is a bug.
