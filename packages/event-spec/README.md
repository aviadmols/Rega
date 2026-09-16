# event-spec

JSON Schema for what the widget reports. The widget validates against it in its tests, the
API validates every incoming beacon against it, and both read the same files.

- `schema/beacon.v1.schema.json` — one batch sent with `navigator.sendBeacon`. One shop, one
  anonymous visitor, one session, up to 50 events.
- `schema/event.v1.schema.json` — one event, and the shared definitions.

## Event types

| Type | When | Must carry |
|---|---|---|
| `exposure` | The widget was really on screen (IntersectionObserver) | candidate, bank version, eligible set, bucket, visibility |
| `open` | The visitor opened the panel | candidate, bank version, eligible set, bucket |
| `click` | The visitor used a suggestion's action | candidate, bank version, eligible set, bucket |
| `dismiss` | The visitor closed it | candidate, bank version, eligible set, bucket, reason |
| `answer` | One-tap "what's stopping you?" answer | product, fixed answer |
| `add_to_cart` | Add to cart, seen by the widget | product, quantity |
| `chat_question` | A chat question was asked | length and where the answer came from |

Purchases are not widget events. They arrive from the store's order webhook.

## Privacy rules the schema enforces

- No free text anywhere. Chat text goes to the chat endpoint, never into events.
- Page paths only, never query strings or fragments.
- Visitor ids are anonymous `anon-…` tokens.
- Unknown fields are rejected, so nothing new is collected by accident.

## Changing the spec

Add a field in a backwards-compatible way, or create `*.v2.schema.json` next to v1. Every
change adds a valid or invalid example under `examples/` and a test in `test/`.

```sh
npm test --workspace @upsell/event-spec
```
