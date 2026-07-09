# Applying this full updated app to the GitHub clone

This package is a complete no-data application tree for the Brainome PHP/SQLite deployment. It includes the current frontend, PHP API, Allen Brain Atlas cache support, the Rostral-Caudal Rhythmicity tab, and the rostral-caudal SQLite exporter.

It does **not** include unpublished SQLite data. Keep/copy these files separately:

```text
data-private/diurnal.sqlite
data-private/dorsal_ventral.sqlite
data-private/supplemental.sqlite
data-private/rostral_caudal.sqlite
```

## Apply to a local Git clone

```bash
cd /path/to/MouseBrainRhythmST_webapp

git pull
git status

git checkout -b add-latest-functionality-rostral-caudal-aba-caching

# From inside the clone, copy the full app contents over the clone.
# Replace /path/to/extracted/MouseBrainRhythmST_webapp_full_updated with the extracted package path.
rsync -a --delete \
  --exclude='.git/' \
  --exclude='data-private/*.sqlite' \
  --exclude='cache/allen/' \
  --exclude='cache/allen-images/' \
  /path/to/extracted/MouseBrainRhythmST_webapp_full_updated/ \
  ./

git status
git diff --stat

git add .
git commit -m "add latest functionality, rostral-caudal rhyth + ABA caching"
git push -u origin add-latest-functionality-rostral-caudal-aba-caching
```

If you want to update `main` directly after reviewing:

```bash
git checkout main
git merge add-latest-functionality-rostral-caudal-aba-caching
git push origin main
```

## Run the new rostral-caudal export

On the analysis machine:

```bash
cd /home/agelber/desp1/precast/precast_final_with_ros_caud/analysis2

Rscript /path/to/MouseBrainRhythmST_webapp/export/export_rostral_caudal_sqlite.R \
  --output-dir=/home/agelber/desp1/precast/precast_final_with_ros_caud/analysis2/new_data_output2 \
  --overwrite
```

Copy the output:

```text
/home/agelber/desp1/precast/precast_final_with_ros_caud/analysis2/new_data_output2/rostral_caudal.sqlite
```

to the deployed app as:

```text
data-private/rostral_caudal.sqlite
```

Do not overwrite `diurnal.sqlite` for this tab.

## Brainome smoke tests

```bash
BASE='https://brainome.ucsd.edu/agelber/MouseBrainRhythmST4'

curl -fsS "$BASE/api/index.php?route=health"
curl -fsS "$BASE/api/index.php?route=rostral-caudal/metadata"
curl -fsS "$BASE/api/index.php?route=rostral-caudal/genes&q=Db"
curl -fsS "$BASE/api/index.php?route=rostral-caudal&gene=Dbp&cluster=L23"
curl -fsS "$BASE/api/index.php?route=rostral-caudal/plot.svg&gene=Dbp&cluster=L23" -o /tmp/rc_dbp.svg
```

Then open the app with a cache-busting query string:

```text
https://brainome.ucsd.edu/agelber/MouseBrainRhythmST4/?v=rc-final
```
