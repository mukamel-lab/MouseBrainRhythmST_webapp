#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
TMP=$(mktemp -d)
PORT=${PORT:-8913}
SERVER_PID=''

cleanup() {
  if [[ -n "$SERVER_PID" ]]; then kill "$SERVER_PID" 2>/dev/null || true; fi
  rm -rf "$TMP"
}
trap cleanup EXIT

command -v php >/dev/null
command -v python3 >/dev/null
command -v curl >/dev/null

if ! php -r 'exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);'; then
  echo 'PDO SQLite is not enabled for command-line PHP.' >&2
  exit 1
fi

find "$ROOT" -name '*.php' -not -path '*/node_modules/*' -type f -print0 \
  | xargs -0 -n1 php -l >/dev/null

python3 "$ROOT/tests/make_fixture_sqlite.py" "$TMP/db"

(
  cd "$ROOT"
  exec env DIURNAL_DB_DIR="$TMP/db" php -S "127.0.0.1:$PORT" >"$TMP/server.log" 2>&1
) &
SERVER_PID=$!

for _ in $(seq 1 50); do
  if curl -fsS "http://127.0.0.1:$PORT/api/index.php?route=health" >/dev/null 2>&1; then break; fi
  sleep 0.1
done

python3 - "$PORT" "$TMP" <<'PY'
import json
import pathlib
import sys
import urllib.request

port = int(sys.argv[1])
tmp = pathlib.Path(sys.argv[2])
base = f"http://127.0.0.1:{port}"

def get(path):
    with urllib.request.urlopen(base + path, timeout=15) as r:
        return r.status, r.headers.get_content_type(), r.read()

def get_json(path):
    status, ctype, body = get(path)
    assert status == 200, (path, status, body[:200])
    assert ctype == "application/json", (path, ctype)
    return json.loads(body)

health = get_json('/api/index.php?route=health')
assert health['status'] == 'ok'
assert health['backend'] == 'PHP/SQLite'

metadata = get_json('/api/index.php?route=metadata')
assert metadata['defaults']['gene'] == 'Dbp'
assert metadata['defaults']['include_region'] == ['L23']
assert metadata['defaults']['include_age'] == ['7 months', '14 months']
assert metadata['defaults']['include_sex'] == ['F', 'M']
assert metadata['defaults']['include_genotype'] == ['NTG']
assert metadata['defaults']['color_by'] == 'region'
assert metadata['defaults']['split_by'] == []
assert metadata['hippocampus_dv']['default_gene'] == 'Lct'
assert metadata['hippocampus_dv']['split_by_default'] == 'none'
assert metadata['rostral_caudal']['available'] is True
assert metadata['rostral_caudal']['default_gene'] == 'Dbp'
assert metadata['rostral_caudal']['default_cluster'] == 'L23'

default_plot = get_json('/api/index.php?route=plot-data&gene=Dbp')
assert default_plot['filters'] == {
    'region': ['L23'],
    'age': ['7 months', '14 months'],
    'sex': ['F', 'M'],
    'genotype': ['NTG'],
}
assert default_plot['colorBy'] == 'region'
assert default_plot['splitBy'] == []

genes = get_json('/api/index.php?route=genes&q=Db')
assert 'Dbp' in genes['genes']

resolve = get_json('/api/index.php?route=genes/resolve&q=Dbp')
assert resolve['found'] and resolve['gene'] == 'Dbp'

rhythm = get_json('/api/index.php?route=rhythmicity&gene=Dbp&limit=5')
assert rhythm['found'] and rhythm['count'] >= 1
assert rhythm['rows'][0]['detail_display']

basic = get_json('/api/index.php?route=rhythmicity/basic&gene=Dbp&clusters=L23')
assert basic['found'] and basic['rows']

dv = get_json('/api/index.php?route=hippocampus-dv&gene=Lct&cluster=DGsg&split_by=none')
assert dv['found'] and dv['analysis_group'] == 'WT only'
assert dv['split_by_label'] == 'Combined'

spatial = get_json('/api/index.php?route=spatial&gene=Dbp')
assert spatial['panels'] and 'log2(normalized counts)' in spatial['legend']

plot = get_json('/api/index.php?route=plot-data&gene=Dbp&include_region=L23,DGsg&color_by=genotype&split_by=age,sex,region')
assert plot['gene'] == 'Dbp'
assert plot['colorBy'] == 'genotype'
assert plot['splitBy'] == ['age', 'sex', 'region']
assert plot['counts']['observations'] == 96
assert plot['counts']['coefficients'] == 16
assert len(plot['observations']) == 96
assert len(plot['coefficients']) == 16
assert [entry['label'] for entry in plot['dimensions']['sex']] == ['Female', 'Male']
assert [entry['value'] for entry in plot['dimensions']['genotype'][:2]] == ['APP23', 'NTG']
assert [entry['color'] for entry in plot['dimensions']['genotype'][:2]] == ['#BC3C29', '#0072B5']
assert plot['axisLabels']['x'] == 'Zeitgeber Time (double plotted)'
assert all({'sampleKey', 'ZT', 'normExpr', 'region', 'age', 'sex', 'genotype'} <= row.keys() for row in plot['observations'])


rc_genes = get_json('/api/index.php?route=rostral-caudal/genes&q=Db')
assert 'Dbp' in rc_genes['genes']

rc = get_json('/api/index.php?route=rostral-caudal&gene=Dbp&cluster=L23')
assert rc['found'] and rc['cluster'] == 'L23'
assert rc['subtitle'] == 'Cortex Layer 2/3'
assert rc['plot']['x_label'] == 'Zeitgeber Time (double plotted)'
assert rc['plot']['y_label'] == 'Dbp'
assert rc['plot']['legend_title'] == ''
assert [region['label'] for region in rc['plot']['regions']] == ['Rostral', 'Intermediate', 'Caudal']
assert rc['point_count'] == 72
assert rc['plotted_point_count'] == 132
assert rc['summary_count'] == 33
assert rc['model_count'] == 3
assert max(point['x'] for point in rc['plot']['points']) == 40
assert any(point['x'] >= 24 for point in rc['plot']['points'])
assert all({'region', 'x', 'y', 'sample_key', 'jitter_key'} <= point.keys() for point in rc['plot']['points'])
assert len(rc['plot']['curves']) == 3
assert all(len(curve['points']) == 160 for curve in rc['plot']['curves'])

for path, outfile, phrase in [
    ('/api/index.php?route=hippocampus-dv/plot.svg&gene=Lct&cluster=DGsg&split_by=none', 'dv.svg', 'log2(normalized counts)'),
]:
    status, ctype, body = get(path)
    assert status == 200 and ctype == 'image/svg+xml', (path, status, ctype)
    text = body.decode('utf-8')
    assert phrase in text, (path, phrase)
    (tmp / outfile).write_bytes(body)

status, ctype, front = get('/')
assert status == 200 and ctype == 'text/html'
front_text = front.decode('utf-8')
assert 'Diurnal Brain Transcriptome Atlas' in front_text
assert './assets/' in front_text

print('PHP/SQLite smoke tests passed.')
PY
