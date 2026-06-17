# SPEC — Asset naming & provenance guard

Status: **accepted — implemented in v0.3.4** · Applies to: every
`StorageInterface` adapter (LocalFilesystem, S3, CloudflareImages,
InMemoryStorage, future backends).

> **Implementation note (v0.3.4).** Shipped as designed below. The shared
> `DefaultUploadResolver` is wired into `LocalFilesystem`, `S3` and
> `InMemoryStorage`; `CloudflareImages` inherits it via its wrapped object
> store. `Entry` gained `?ImageMeta $image` (trailing, optional → no break).
>
> One refinement from the original draft: the canonical extension map is **not**
> images-only. The default allowlist covers the realistic CMS upload surface —
> images, office/OpenDocument documents, PDF/RTF/text, common archives, and
> audio/video — because consumers store documents, not just images. A detected
> type still outside the map is rejected (the allowlist guarantee holds), and
> `DefaultUploadResolver`'s constructor accepts a custom `$extensionMap` so a
> site can widen or narrow the storable set without forking the package.
>
> Caveat: container formats (e.g. OOXML `.docx`/`.xlsx`) are ZIP at the byte
> level, so `finfo` may report them as `application/zip` and store them as
> `.zip`. That is provenance-correct (the bytes *are* a zip); a site needing
> finer fidelity injects a custom resolver/map.

## Why

Two properties must hold for **every** stored asset, on **every** adapter:

- **Provenance** — a stored reference can always be traced and trusted to point
  at exactly what it claims to be. You can look at a stored key and know its
  type from the key itself.
- **Predictability** — public URLs and derived variant keys are deterministically
  constructable from the stored name. No guessing, no ambiguity.

These are not "nice to have". They are the contract callers (and CDNs, and
admin UIs, and migration tooling) depend on.

> **Failure mode this closes.** Today a caller can store an asset whose key
> carries **no extension**, or an extension taken verbatim from an untrusted
> client filename. Both break provenance: the original may 404, the stored MIME
> may disagree with the bytes, and any variant-key derivation that splits on
> the last `.` lands in the wrong place when the human part of the name itself
> contains dots. The storage layer currently has no guard against this — it
> trusts whatever the caller hands it. This spec adds one.

## Principles (the contract)

1. **Clean name.** The stored basename is a slug: `[a-z0-9]` plus single
   hyphens as separators. No spaces, no raw dots inside the name, no unsafe or
   non-ASCII characters. Runs collapse to one hyphen; leading/trailing hyphens
   are trimmed.

2. **Normalised extension, derived from the DETECTED type.** The extension is
   chosen from the file's *detected* type — sniffed from the source bytes
   (`getimagesize`/`getimagesizefromstring` for images, `finfo`/
   `mime_content_type` otherwise) — and mapped to one canonical extension
   (`jpeg`→`jpg`, `x-png`→`png`, etc.). The client-supplied filename extension
   and the client-supplied MIME are **never trusted** for this. A stored
   reference is therefore **never extensionless**.

3. **Dimensions are captured at store time.** For images, width + height are
   read once during `store()` and surfaced to the caller so they can be
   persisted alongside the row. They are first-class provenance metadata, not
   something to re-derive later from the bytes.

4. **Stored names are immutable.** Slugification + extension resolution happen
   exactly once, at store time. A stored object is never silently re-slugified
   or renamed on a subsequent store. The only mutations are explicit
   `rename()` and `delete()`.

5. **The original format is preserved, not re-encoded.** The storage layer does
   not transcode originals (a PNG stays a PNG, a JPEG stays a JPEG); the
   extension reflects the *detected* original type. Any format normalisation
   (e.g. "always store originals as PNG") is an explicit, opt-in *variant /
   transform* concern of a specific profile — never a silent default of the
   store path. (This is the answer to "(a) canonical single format vs
   (b) preserve detected format": **(b)** at the library level.)

6. **Enforcement is code-level, not convention.** A single shared chokepoint
   enforces 1–4 for every adapter and every caller. If it cannot produce a
   clean slug + a confidently-detected extension (or, for an image, valid
   dimensions), it **throws** — it never falls back to client-supplied data
   and never writes an ambiguous key.

## Current state & gaps

| Concern | Today | Gap vs. spec |
|---|---|---|
| Name sanitising | `LocalFilesystem::sanitiseFilename()` **and** `S3::sanitiseFilename()` — duplicated per adapter | Should be one shared rule (DRY + uniform) |
| Extension | `extensionFor($clientMime, $rawClientExt)` — keyed off the **client** MIME, **falls back to the client's raw extension** | Must derive from the **detected** type; no client fallback |
| Detected type | `getimagesize` used only to decide *whether* to make variants; `clientMime` used for the stored MIME | Detected type must drive the **extension** and the persisted MIME |
| Dimensions | `imageMeta($path)` exists as a *separate* call; `store()` returns an `Entry` with `size`+`mime` but **no width/height** | `store()` must surface width/height |
| Immutability | `resolveCollision()` appends `-1`, `-2`… on collision (fine); no re-slugify path exists today | Keep; make the "derive once" guarantee explicit in the contract |
| Guard | none — adapters trust `UploadInput` | Add the shared guard |

`PathResolver` is explicitly a transitional legacy artefact (field-config
option-bag pyramids) and is slated for removal; its `sanitiseBasename()`/
`extensionFor()` should be superseded by the shared component below rather than
extended.

## Design

### 1. A shared, adapter-agnostic resolver

Introduce one component that turns an `UploadInput` (plus its readable source
file) into a **canonical stored name + detected metadata**, and have every
adapter delegate to it instead of its private `sanitiseFilename()`.

Proposed shape (final names open to bikeshedding):

```php
namespace Contenir\Storage;

final readonly class ResolvedUpload
{
    public function __construct(
        public string  $name,        // "my-photo.png"  (slug + canonical ext)
        public string  $mime,        // detected, normalised  "image/png"
        public ?ImageMeta $image,    // width/height/mime for images; null otherwise
    ) {}
}

interface UploadResolverInterface
{
    /**
     * @throws \Contenir\Storage\Exception\UnsupportedTypeException
     *         when the type cannot be detected/normalised.
     * @throws \Contenir\Storage\Exception\InvalidPathException
     *         when the slug empties out or traversal is attempted.
     */
    public function resolve(UploadInput $upload): ResolvedUpload;
}
```

`DefaultUploadResolver` implements it:
- **slug** = sanitise(`pathinfo(clientFilename, FILENAME)`) → reject if empty.
- **detected type**: `getimagesize($sourcePath)` first (gives MIME + width +
  height in one call); else `finfo_file`/`mime_content_type`.
- **extension** = canonical map of the *detected* MIME. Unknown/undetectable →
  throw `UnsupportedTypeException` (no client fallback).
- **image meta**: populated from `getimagesize` when the source is a raster
  image; `null` for non-images (PDFs, etc.).

### 2. Adapters delegate

`LocalFilesystem::store()` / `S3::store()` / `InMemoryStorage::store()` call
`$this->resolver->resolve($upload)` and use:
- `$resolved->name` for the object key (then `resolveCollision()` as today),
- `$resolved->mime` as the stored/`ContentType` MIME (replacing
  `clientMime ?? 'application/octet-stream'`),
- `$resolved->image` to populate the returned `Entry`'s dimensions.

The private `sanitiseFilename()`/`extensionFor()` methods are removed.

### 3. Surface dimensions from `store()`

Add width/height to what `store()` returns so callers can persist them without
a second round-trip. Either:
- extend `Entry` with `public readonly ?ImageMeta $image = null;` (backwards
  compatible — new optional trailing arg), **or**
- return a richer `StoreResult { Entry $entry; ?ImageMeta $image; }`.

Recommendation: extend `Entry` (smaller blast radius, and `Entry::isImage()`
already lives there).

### 4. Enforcement points

- `UploadResolverInterface::resolve()` is the **only** place names/extensions
  are derived. Adapters MUST NOT re-implement it.
- `resolve()` throws rather than degrade — an upload that can't be typed is a
  hard failure, surfaced to the caller, not a silently-written ambiguous key.
- Existing path-traversal rejection in adapters stays.

## Backwards compatibility

- Stored keys for *correctly* typed uploads are unchanged (a JPEG still lands
  at `name.jpg`). The behaviour change is: extension now follows the **bytes**,
  not the client filename — a `.jpg`-named PNG is stored as `.png`. That's the
  intended correctness fix.
- The client-extension fallback is removed; callers that relied on storing
  untyped blobs must supply a detectable file or a profile that permits a
  generic type.
- `Entry`'s new field is optional/trailing → no break for existing constructors
  if added last.

## Out of scope (separate work)

- **Re-encoding originals** (e.g. a profile that wants every original stored as
  PNG). That stays a profile/consumer concern; this spec only guarantees the
  extension matches the *detected* original type.
- **Fixing already-bad data** in any consumer's existing rows. A backfill that
  appends the correct detected extension is a downstream migration, informed by
  — but separate from — this guard.

## Test plan (additions under `tests/Unit`)

- Resolver: a `.jpg`-named PNG → `…​.png`; uppercase/duplicate-dot client names →
  clean slug; a client name whose human part contains dots → single canonical
  extension with the dots in the human part collapsed by the slug rule;
  undetectable type → throws; SVG/PDF/webp mappings; empty slug → throws.
- Each adapter: `store()` ignores a lying `clientMime`/extension and persists
  the detected one; the returned `Entry` carries width/height for an image and
  not for a non-image; immutability — re-`store()` of a same-named file
  collision-resolves and never rewrites the first object's key.