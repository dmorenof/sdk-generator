# Canonical local schema references — v0.37.0

A local schema alias refers to the target type; it does not create another DTO,
enum, composition wrapper or inheritance layer. Chained aliases to objects,
enums, maps and existing compositions now reuse the canonical generated PHP
class. Scalar/array aliases keep their existing native/collection representation.
Aliases do not contribute artificial prefixes to canonical class names.

The shared resolver serves model emission, object parameter support, forms,
allOf inheritance and discriminator mappings. Recursive object references stop
at the object schema; pure alias cycles, unknown targets and chains over 128
reference steps fail explicitly. Heredity remains single-parent PHP where the
existing allOf compatibility rules permit it.

Discriminator branches may name aliases; explicit mappings may target an alias
or its canonical branch. Validation retains every original composition branch:
oneOf containing two equivalent aliases still matches twice and is invalid.
Mutating a hydrated canonical model retains the original validation boundaries.

Schema component references now reuse the existing JSON Pointer decoder for
URI fragment escapes and ~0/~1 tokens, in OpenAPI and Swagger definitions.
Source identifiers and wire names remain verbatim; only PHP symbols are mapped.

No runtime changes are needed: the emitted SDK uses stable sdk-runtime ^0.29.0.
General external/dynamic reference scopes, arbitrary schema locations, $ref
validation siblings and other unsupported schema compositions remain pending.
