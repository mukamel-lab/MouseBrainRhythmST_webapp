# Test results

Package: PHP/SQLite Brainome app with Rostral-Caudal Rhythmicity, Allen image caching, and adjusted main diurnal plot width.

Checks completed in this build environment:

- `npm ci --no-audit --no-fund` — passed.
- `npm run lint` — passed.
- `npm run build` — passed.
- PHP syntax checks for `api/index.php` and all `api/lib/*.php` files — passed under PHP CLI 8.4.16.
- Static string checks confirmed:
  - Rostral-caudal labels use `Rostral`, `Intermediate`, and `Caudal`.
  - No user-facing `Medial` label remains in the rostral-caudal code path.
  - The main diurnal plot display requests `width=860` and the compiled CSS limits the displayed plot width to `900px`.
  - Allen metadata and JPEG image caching code is present.

Smoke test status:

- `tests/run_smoke.sh` could not be completed in this container because PHP CLI here does not have the `pdo_sqlite` driver enabled.
- The same smoke test should be run on Brainome or any host where PHP reports PDO SQLite support.

Recommended live test after deploying with databases:

```bash
BASE='https://brainome.ucsd.edu/MouseBrainRhythmST3'

curl -fsS "$BASE/api/index.php?route=health"
curl -fsS "$BASE/api/index.php?route=rostral-caudal/metadata"
curl -fsS "$BASE/api/index.php?route=rostral-caudal/genes&q=Db"
curl -fsS "$BASE/api/index.php?route=rostral-caudal&gene=Dbp&cluster=L23"
curl -fsS "$BASE/api/index.php?route=rostral-caudal/plot.svg&gene=Dbp&cluster=L23" -o /tmp/rc_dbp.svg
curl -fsS "$BASE/api/index.php?route=allen/ish/image&section_image_id=101344407&view=ish&downsample=4" -o /tmp/allen_cached.jpg
```

Then open the app, hard-refresh, and check the new Rostral-Caudal Rhythmicity tab.
