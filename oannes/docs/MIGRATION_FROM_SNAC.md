# Migration From Snac

## First Pass

Run:

```sh
php oannes/bin/oannes.php analyse-snac /ruta/a/la/instancia/snac
```

This reads Snac objects and checks existing `_c.idx` child indexes. Any child
whose `inReplyTo` does not point to the indexed parent is reported as invalid.

## Import

Run:

```sh
php oannes/bin/oannes.php import-snac /ruta/a/la/instancia/snac
php oannes/bin/oannes.php validate-threads
```

The import does not copy Snac `_c.idx`, `_p.idx`, `_a.idx`, or `_l.idx` files.
Oannes rebuilds all indexes from canonical JSON.

## Example Snac Bug Captured

For example, a Snac object can be a reply to one object while its child index
contains replies to another object. In that case, the object:

```text
object/xx/example.json
```

is a reply to:

```text
https://example.org/ap/objects/original-parent
```

but its Snac child index includes replies to:

```text
https://example.org/ap/objects/unrelated-parent
```

Oannes does not import that index and will not reproduce the error.
