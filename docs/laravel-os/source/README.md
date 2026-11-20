# The studio's documents that this admin's code names

**Written 20 November 2026** · master list T-174 · decision DL-043 · release report 39

The code of `platform/` came from another system: the Interior studio's Laravel application,
[woodex420/woodexinterior](https://github.com/woodex420/woodexinterior), at commit
`603428cca1e45f9da2ffd9c4a0d88eb741285339` (3 November 2026). Its comments say why a thing is so by naming that
system's records: "D-58", "docs/06 §4", "QUO-006". Until now a reader found none of them here. Three are kept in
this folder, unchanged:

| A comment says | Read | It is |
|---|---|---|
| `D-58`, `D-09` (a decision, two or three digits, up to D-104) | [07-DECISIONS.md](07-DECISIONS.md) | The studio's decision record: D-01 to D-104 |
| `QUO-006`, `CRM-014` (a requirement: letters and three digits) | [02-MASTER-PRD.md](02-MASTER-PRD.md) | The studio's requirements: 203 codes in 17 families |
| `docs/06`, `docs/06 §4` | [06-DEPLOYMENT-HOSTINGER.md](06-DEPLOYMENT-HOSTINGER.md) | The studio's instructions for installing on Hostinger |
| `docs/02`, `docs/07` | the first two files above | |

Each file begins with a note that says whose document it is and where this admin differs. **None of the three is a
document of this product**, and nothing in them binds it.

## Numbers that are this repository's own

| A comment says | Read |
|---|---|
| `DL-017` | [docs/woodex-os/12-DECISION-LOG.md](../../woodex-os/12-DECISION-LOG.md): the decisions taken for this admin |
| `D-8` (one digit, D-1 to D-10) | [the reuse plan](../00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md): the ten decisions that plan put to the owner before the code was taken over |
| `D-028` (three digits beginning with 0) | [the defect register](../../woodex-os/16-ACCEPTANCE-AND-RELEASE-GATES.md), or the owner's own log, [specification document 12](../../woodex-os/spec/12-DECISION-LOG.md), which numbers its decisions the same way |

## What still cannot be looked up here

[unresolved.json](unresolved.json) lists it, each name once and with the reason:

- **22 other documents of the studio** that the code names ("docs/09", "docs/audit/16"). They describe the
  studio's own system, define no number the code uses, and are not copied. They can be read in the studio's
  repository at the commit above.
- **Five decisions the studio never wrote down: D-107, D-111, D-112, D-115, D-116.** Its record ends at D-104, at
  that commit and at the newest one (read on 20 November 2026). What they decide can only be read from the
  comments that name them.
- **Four names that only look like a requirement code**: SHA-256, AES-256, and two sample values in tests.

No code was changed to make this list shorter.

## The check

`node scripts/verify-code-references.mjs` reads the code of `platform/` and fails, with the file and the line,
when it names a decision, a numbered document, a section of a kept document or a requirement that is neither
defined nor on the list; when an entry of the list has no reason, is no longer named, or can be looked up after
all; and when one of the three files no longer has the checksum of the studio's file. It runs in CI on every
push, with `scripts/test-verify-code-references.mjs`, which tries it one change at a time.

It does not judge whether a comment names the right decision, only that the decision exists.
