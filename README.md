# surface/framebuffers

Where CPU pixel bytes live. Five buffer kinds (full, dirty, epaper, paged, ring),
nine packings, `PixelMapper`. Sketch talks `Framebuffer` contract. Driver owns
the store. PHP driver always here. Native driver is `jovian/fb` over `ext-fb`.
Both built against golden fixtures, never each other.

| Driver | Package | Store |
|---|---|---|
| `php` (default) | this package | PHP strings |
| `native` | `jovian/fb` (suggest) | C via `ext-fb` |

```
FRAMEBUFFER_DRIVER=php      # always available
FRAMEBUFFER_DRIVER=native   # needs ext-fb + jovian/fb; missing package = container not-found
```

Rebind a driver name in `config/framebuffers.php` `drivers.*.alias` without code.
`Framebuffers::driver()` is the MagicAlias.

## Pixel words

Both drivers use the same `int` conventions:

- mono `0` / `1` (`1` = lit / white)
- `ROW_MAJOR` colour = packed word of that depth (B12 `0xRGB`, B16 RGB565, B18 RGB888 with low two bits of each channel zero, B24 `0xRRGGBB`, B32 `0xRRGGBBAA`)
- `ROW_MAJOR` B2/B4/B8 with palette = the panel wire **code**
- B8 without palette = grey `0..255`
- `PLANAR` = channel **mask** (`1 << k` for palette channel `k`, `0` = paper)

Padding bits and bytes stay zero. Initial state is `fill(0)`. Store is always top-down; `BOTTOM_TO_TOP` is output-only.

## Fixtures are the contract

`tests/Framebuffers/fixtures/*.json` — 27 golden cases. A fixture failure is a layout-math bug until proven otherwise. Drivers do not read each other.
