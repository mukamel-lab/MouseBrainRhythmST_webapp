# Brainome PHP/SQLite application test results

Tested in the build environment on 2026-06-20.

## Passed

- `npm run lint`
- `npm run build`
- PHP syntax checks for all application PHP files
- SQLite fixture generation matching the schemas produced by `export_brainome_sqlite.R`
- API health and metadata endpoints
- Main gene search and exact gene resolution
- Diurnal SVG generation
- Spatial map and legend payload generation
- Supplemental rhythmicity search and compact display formatting
- Basic NTG/APP23 rhythmicity summary
- WT dorsal/ventral data and DESeq2/FDR payload
- WT dorsal/ventral SVG barplot generation
- Static frontend delivery using relative asset URLs
- Clean-package smoke test via `tests/run_smoke.sh`

The generated diurnal and dorsal/ventral SVGs were also rasterized and visually inspected for valid axes, labels, curves/bars, error bars, and individual points.

## Not live-tested here

The build environment blocks external DNS/network access, so a live request to the Allen Brain Atlas could not be completed. The Allen endpoint was syntax-checked and is designed to fail gracefully when outbound HTTPS is unavailable. Test it on Brainome with:

```text
api/index.php?route=allen/ish&gene=Dbp
```

The user's full production SQLite databases were not uploaded into this environment. Runtime tests used fixture databases with the same schema. On Brainome, `check.php` and `api/index.php?route=health` verify that the actual databases can be opened and pass SQLite `quick_check`.
