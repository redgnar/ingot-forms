# 23 — an address and a number, as types

**Planned 2026-09-09.** The last row of [10](10-what-a-vendor-offers.md)'s catalogue table that is
neither built nor refused: *semantic fields* — "all reachable through `text` + `pattern`, but
without a ready rule, browser hint or phone keyboard. **Email and phone are the plausible
candidates for types with rules of their own.**" This is that entry, taken at its word and no
further: two types, not eight.

## Whether they earn a type at all

The rule they have to clear is the catalogue's own: *a type exists when it brings rules of its
own, and never when it would only tell a frontend which widget to draw. A type with no rules of
its own is a second name for one we already have, and every rule it copies is a rule that can
drift.*

- **`email` clears it on a word JSON Schema already has.** `format: email` is a keyword; a `text`
  item cannot say it, whatever pattern an author writes. So the type publishes something a
  client's own validator understands, rather than a regex it has to trust.
- **`phone` clears it on a standard rather than a keyword.** JSON Schema has no phone format, so
  what the type brings is **E.164 and nothing else**: `+` and 7 to 15 digits, one canonical shape
  for every country. That is a rule, and the reason it is worth owning is that an author writing
  it by hand writes it differently every time.

What neither of them brings is a widget, and that is the half to keep saying out loud: `type=email`
and `type=tel` on a page are a *consequence* of the item's rule, not the reason for it.

## What is published, and why `email` publishes two rules

Measured before deciding, because this is the shape of mistake `multipleOf` already made here —
a keyword the two sides of a contract compute differently:

| Reader | How it reads `format: email` |
|---|---|
| this server (opis) | `FILTER_VALIDATE_EMAIL` |
| a client with `ajv-formats` | its own regex — different in the fringes |
| a client with plain Ajv | **ignores it entirely** |

So the derived schema says `format: email` **and** a pattern, exactly as `datetime` says
`format: date-time` and a pattern for the offset. The pattern is not decoration: it is chosen so
that **what it accepts is what the filter accepts** — measured over 28 strings, including
`a..b@example.com`, `.a@example.com`, `a@-b.com`, `user@localhost`, `"a b"@example.com` and
`ünïcode@example.com`, with **zero divergences**. A client that reads only the pattern, only the
format, or both, gets this server's verdict either way.

```
"format": "email"
"pattern": "^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(\\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$"
```

`phone` publishes one rule, `^\+[1-9]\d{6,14}$`, and no `format` — there is none to name, and a
plain regex is the one thing every implementation computes identically.

**Neither adds `minLength` when required.** A `text` item does, because "required means non-empty";
here the pattern already refuses the empty string, and two published rules saying one thing is two
places for it to drift. (A page never sends an empty string anyway — the collector leaves the
member out — so an unanswered obligation is `form.value.required` as it is for everything else.)

**The second gate adds nothing.** Every rule of both types is in the published contract and
enforced by the first gate; a Symfony `Regex` beside it would be a second reading of the same
pattern, which is what that stage exists *not* to do.

## The options they take

- **`email`**: `maxLength`, because storage is a real concern and the pattern says nothing about
  length. Nothing else.
- **`phone`**: **none**. E.164 caps itself at fifteen digits, so there is nothing left to
  configure — and a type whose whole rule is the standard is a type with no knobs.
- **Neither takes `pattern`.** A type that lets an author restate its own shape is a type with two
  rules that can disagree. Somebody who wants a national format — `123 456 789` — writes `text`
  with a pattern, and that is the honest half of this decision rather than a gap in it.

## On a page

One widget each, in both kits, named after the type: `email` draws
`type="email" inputmode="email" autocomplete="email"`, `phone` draws
`type="tel" inputmode="tel" autocomplete="tel"`, and both carry the type's own `pattern` in the
markup — the same constant the deriver publishes, so there is one place per rule. The browser's own
keyboard on a telephone is the whole visible point, and `autocomplete` is the rest of it: an
address somebody has typed a hundred times should be one tap.

## Steps

1. `EmailField`, `PhoneField` and their place in the union; the meta-schema; the pattern as a
   constant on each class, since the deriver and the page both need it.
2. The deriver and the second gate (which adds nothing, and says so).
3. Both engines and both kits' templates.
4. The two batteries per type: what a definition may carry, and a table of values with the pointer
   and code each must produce — on both sides of every boundary, the accepted side included.
5. The documents: `configuring-forms.md` (the item table, the two rows, the refusal codes),
   `kits.md` (four entries), `CLAUDE.md` (the model paragraph), `README.md` if the catalogue line
   names types.

## Deliberately absent

Normalization of any kind (this service never parses, trims or reformats what somebody sent),
national phone formats and a `region` option, `libphonenumber`, MX or deliverability checks (a
gate that calls the world while validating is a different class of system), a second "confirm your
address" field (a page's business, not a document's), and the six other semantic fields the vendor
ships — `url`, `currency`, `tags`, `address`, `day`, `time` — none of which brings a rule this
model cannot already state.
