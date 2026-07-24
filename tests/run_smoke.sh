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
  exec env DIURNAL_DB_DIR="$TMP/db" DIURNAL_CACHE_DIR="$TMP/cache" php -S "127.0.0.1:$PORT" >"$TMP/server.log" 2>&1
) &
SERVER_PID=$!

for _ in $(seq 1 50); do
  if curl -fsS "http://127.0.0.1:$PORT/api/index.php?route=health" >/dev/null 2>&1; then break; fi
  sleep 0.1
done

python3 - "$PORT" "$TMP" <<'PY'
import json
import math
import pathlib
import sys
import urllib.error
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

def get_error(path):
    try:
        get(path)
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get_content_type(), error.read()
    raise AssertionError(f"Expected HTTP error for {path}")

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
assert metadata['nonrhythmic']['available'] is True
assert metadata['nonrhythmic']['schema_version'] == '3'
assert metadata['nonrhythmic']['default_gene'] == 'Idi1'
assert metadata['nonrhythmic']['default_cluster'] == 'L23'
assert metadata['nonrhythmic']['default_contrast'] == 'genotype_altage_marginal_sex_equal'
assert metadata['nonrhythmic']['gene_count'] == 4
assert metadata['nonrhythmic']['model_count'] == 2

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
assert plot['counts']['observations'] == 48
assert plot['counts']['coefficients'] == 8
assert len(plot['observations']) == 48
assert len(plot['coefficients']) == 8
assert [entry['label'] for entry in plot['dimensions']['sex']] == ['Female', 'Male']
assert [entry['value'] for entry in plot['dimensions']['genotype'][:2]] == ['APP23', 'NTG']
assert [entry['color'] for entry in plot['dimensions']['genotype'][:2]] == ['#BC3C29', '#0072B5']
assert plot['axisLabels']['x'] == 'Zeitgeber Time (double plotted)'
assert all({'sampleKey', 'ZT', 'normExpr', 'region', 'age', 'sex', 'genotype'} <= row.keys() for row in plot['observations'])


nr_metadata = get_json('/api/index.php?route=nonrhythmic/metadata')
assert nr_metadata['available'] is True
assert nr_metadata['default_threshold'] == 0.5
assert [cluster['id'] for cluster in nr_metadata['clusters']] == ['L23', 'DGsg']
assert len(nr_metadata['contrasts']) == 20
nr_default_contrast = next(row for row in nr_metadata['contrasts'] if row['code'] == 'genotype_altage_marginal_sex_equal')
assert nr_default_contrast['is_default'] is True
assert nr_default_contrast['vector'] == [0.0, 0.0, 0.0, 1.0, 1.0, 0.5]

nr_genes = get_json('/api/index.php?route=nonrhythmic/genes&q=human&cluster=L23')
assert nr_genes['query'] == 'human'
assert nr_genes['genes'] == ['humanAPP']

nr_resolve = get_json('/api/index.php?route=nonrhythmic/genes/resolve&q=humanAPP&cluster=L23')
assert nr_resolve['found'] is True
assert nr_resolve['gene'] == 'humanAPP'
assert nr_resolve['gene_id'] == 1

nr_cache_dir = tmp / 'cache' / 'nonrhythmic'
cache_before_expression = set(nr_cache_dir.glob('*.json')) if nr_cache_dir.exists() else set()
nr_expression = get_json('/api/index.php?route=nonrhythmic/expression&gene=humanapp&cluster=L23')
assert nr_expression['available'] and nr_expression['found']
assert nr_expression['gene'] == 'humanAPP'
assert nr_expression['gene_id'] == 1
assert nr_expression['expression_count'] == 16
assert len(nr_expression['expression']) == 16
assert len(nr_expression['cells']) == 8
assert 'wald' not in nr_expression and 'tested_gene_count' not in nr_expression
cache_after_expression = set(nr_cache_dir.glob('*.json')) if nr_cache_dir.exists() else set()
assert cache_after_expression == cache_before_expression

nr_results = get_json('/api/index.php?route=nonrhythmic/results&cluster=L23&contrast=genotype_marginal_age_sex_equal')
assert nr_results['available'] is True
assert nr_results['cluster'] == {'id': 'L23', 'label': 'Cortex Layer 2/3', 'sort_order': 1}
assert nr_results['contrast']['source'] == 'preset'
assert nr_results['hypothesis'] == {'id': 'zero', 'label': 'Effect differs from zero', 'threshold': 0.0}
assert len(nr_results['contrasts']) == 20
assert nr_results['row_count'] == nr_results['tested_gene_count'] == 4
assert nr_results['untestable_gene_count'] == 0
assert nr_results['invalid_variance_count'] == 0
assert all({'gene_id', 'gene', 'log2FoldChange', 'lfcSE', 'stat', 'pvalue', 'padj'} <= row.keys() for row in nr_results['rows'])
assert all(isinstance(row[field], (int, float)) for row in nr_results['rows'] for field in ('log2FoldChange', 'lfcSE', 'stat', 'pvalue', 'padj'))
assert [row['padj'] for row in nr_results['rows']] == sorted(row['padj'] for row in nr_results['rows'])
nr_results_humanapp = next(row for row in nr_results['rows'] if row['gene'] == 'humanAPP')
assert math.isclose(nr_results_humanapp['log2FoldChange'], 1.15, rel_tol=0, abs_tol=1e-12)
cache_after_results = set(nr_cache_dir.glob('*.json'))
assert len(cache_after_results - cache_before_expression) == 1

nr_preset = get_json('/api/index.php?route=nonrhythmic&gene=humanAPP&cluster=L23&contrast=genotype_marginal_age_sex_equal&hypothesis=zero')
assert nr_preset['available'] and nr_preset['found'] and nr_preset['testable']
assert nr_preset['gene'] == 'humanAPP'
assert nr_preset['cluster'] == {'id': 'L23', 'label': 'Cortex Layer 2/3', 'sort_order': 1}
assert nr_preset['model']['references'] == {'age': '7 months', 'sex': 'F', 'genotype': 'NTG'}
assert nr_preset['model']['n_coefficients'] == 6
assert len(nr_preset['model']['coefficients']) == 6
assert nr_preset['contrast']['source'] == 'preset'
assert nr_preset['contrast']['vector'] == [0.0, 0.0, 0.0, 1.0, 0.5, 0.5]
assert nr_preset['hypothesis'] == {'id': 'zero', 'label': 'Effect differs from zero', 'threshold': 0.0}
assert math.isclose(nr_preset['wald']['log2FoldChange'], 1.15, rel_tol=0, abs_tol=1e-12)
assert math.isclose(nr_preset['wald']['lfcSE'], math.sqrt(0.06), rel_tol=0, abs_tol=1e-12)
assert nr_preset['wald']['pvalue'] < nr_preset['wald']['padj'] < 0.001
assert nr_preset['tested_gene_count'] == 4
assert nr_preset['untestable_gene_count'] == 0
assert nr_preset['invalid_variance_count'] == 0
assert nr_preset['expression_label'] == 'log2(size-factor-normalized count + 1)'
assert nr_preset['expression_count'] == 16
assert len(nr_preset['expression']) == 16
assert len(nr_preset['cells']) == 8
assert {row['sample_n'] for row in nr_preset['cells']} == {2}
assert math.isclose(nr_preset['wald']['padj'], nr_results_humanapp['padj'], rel_tol=0, abs_tol=1e-15)
assert set(nr_cache_dir.glob('*.json')) == cache_after_results

nr_custom = get_json('/api/index.php?route=nonrhythmic&gene=humanAPP&cluster=L23&c=0,0,0,1,0.5,0.5&hypothesis=zero')
assert nr_custom['contrast']['source'] == 'custom'
assert nr_custom['contrast']['vector'] == nr_preset['contrast']['vector']
for field in ('log2FoldChange', 'lfcSE', 'stat', 'pvalue', 'padj'):
    assert math.isclose(nr_custom['wald'][field], nr_preset['wald'][field], rel_tol=0, abs_tol=1e-15), field
assert nr_custom['tested_gene_count'] == nr_preset['tested_gene_count']

nr_equivalence = get_json('/api/index.php?route=nonrhythmic&gene=humanAPP&cluster=L23&c=0,0,0,1,0.5,0.5&hypothesis=lessAbs&threshold=1.5')
assert nr_equivalence['contrast']['source'] == 'custom'
assert nr_equivalence['hypothesis']['id'] == 'lessAbs'
assert nr_equivalence['hypothesis']['threshold'] == 1.5
assert math.isclose(nr_equivalence['wald']['log2FoldChange'], 1.15, rel_tol=0, abs_tol=1e-12)
assert 0.07 < nr_equivalence['wald']['pvalue'] < 0.09
assert nr_equivalence['tested_gene_count'] == 4

status, ctype, body = get_error('/api/index.php?route=nonrhythmic&gene=humanAPP&cluster=L23&c=0,0,0')
assert status == 400 and ctype == 'application/json', (status, ctype, body[:200])
nr_error = json.loads(body)
assert 'exactly six' in nr_error['error']['message'].lower()

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
assert rc['plotted_point_count'] == 72
assert rc['summary_count'] == 18
assert rc['model_count'] == 3
assert max(point['x'] for point in rc['plot']['points']) == 20
assert all(point['x'] < 24 for point in rc['plot']['points'])
assert all({'region', 'x', 'y', 'sample_key', 'jitter_key'} <= point.keys() for point in rc['plot']['points'])
assert len(rc['plot']['curves']) == 3
assert all(len(curve['points']) == 160 for curve in rc['plot']['curves'])

for path, outfile, phrase in [
    ('/api/index.php?route=hippocampus-dv/plot.svg&gene=Lct&cluster=DGsg&split_by=none', 'dv.svg', 'log2 Normalized mRNA Expression'),
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
