# Architecture

The store is organised as a modular DDD-oriented monolith.

- `app/Domain` contains business concepts, invariants, value objects, aggregates, and repository contracts. It has no Laravel or Eloquent dependencies.
- `app/Application` contains use cases. A use case coordinates domain objects and ports, but does not implement HTTP or persistence details.
- `app/Infrastructure` implements ports using Eloquent and Laravel services.
- `app/Http` is the delivery layer. Controllers validate/translate HTTP requests and invoke application use cases.

`PlaceOrder` is the first vertical slice following these boundaries. The `Order` aggregate owns pricing and delivery rules; the Eloquent repositories only translate it into database records. New functionality should follow this slice rather than add business rules to controllers or Eloquent models.
