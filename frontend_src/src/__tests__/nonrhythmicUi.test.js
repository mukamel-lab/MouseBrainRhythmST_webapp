import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  buildNonrhythmicRequest,
  comparisonFamilyLabel,
  contrastOptionLabel,
  contrastSelectLabel,
  effectDirection,
  foldRatio,
  visibleContrastCatalog,
} from '../nonrhythmicUi.js';

const equalOverall = {
  code: 'genotype_marginal_age_sex_equal',
  family: 'genotype',
  age_mode: 'marginal',
  sex_mode: 'marginal',
  weighting: 'equal',
};

test('question families use biological language', () => {
  assert.equal(comparisonFamilyLabel('genotype'), 'Is expression different between APP23 and NTG?');
  assert.equal(comparisonFamilyLabel('interaction'), 'Does the APP23–NTG difference change with age or sex?');
  assert.equal(comparisonFamilyLabel('age'), 'Does expression change with age within one genotype?');
  assert.equal(comparisonFamilyLabel('sex'), 'Does expression differ by sex within one genotype?');
});

test('comparison labels explain weighting and model constraints without jargon', () => {
  assert.equal(
    contrastOptionLabel(equalOverall),
    'Overall APP23 vs. NTG — each age × sex group weighted equally',
  );
  assert.equal(contrastSelectLabel(equalOverall), 'Overall APP23 vs. NTG');
  assert.equal(
    contrastOptionLabel({
      code: 'cell',
      family: 'genotype',
      age_mode: '7 months',
      sex_mode: 'F',
      weighting: 'cell',
    }),
    'APP23 vs. NTG at 7 months in Female samples',
  );
  assert.equal(
    contrastOptionLabel({
      code: 'age_alt_vs_ref_ntg_refsex',
      family: 'age',
      label: '14 months vs 7 months in NTG, F',
    }),
    '14 months vs 7 months in NTG (modeled across both sexes)',
  );
  assert.equal(
    contrastOptionLabel({
      code: 'sex_alt_vs_ref_ntg_refage',
      family: 'sex',
      label: 'M vs F in NTG, 7 months',
    }),
    'Male vs Female in NTG (modeled across both ages)',
  );
});


test('statistical request is independent of plot faceting', () => {
  const state = {
    gene: 'humanAPP',
    cluster: 'L23',
    contrastCode: 'genotype_marginal_age_sex_equal',
    hypothesis: 'zero',
    threshold: 99,
  };
  const expected = {
    gene: 'humanAPP',
    cluster: 'L23',
    contrast: 'genotype_marginal_age_sex_equal',
    hypothesis: 'zero',
    threshold: 0,
  };
  assert.deepEqual(buildNonrhythmicRequest({ ...state, plotFacetMode: 'overall' }), expected);
  assert.deepEqual(buildNonrhythmicRequest({ ...state, plotFacetMode: 'age_sex' }), expected);
  assert.deepEqual(
    buildNonrhythmicRequest({
      ...state,
      customContrast: '0,0,0,1,0.5,0.5',
      hypothesis: 'greaterAbs',
      threshold: '1',
    }),
    {
      gene: 'humanAPP',
      cluster: 'L23',
      contrast: 'custom',
      c: '0,0,0,1,0.5,0.5',
      hypothesis: 'greaterAbs',
      threshold: 1,
    },
  );
  for (const threshold of [0, -1, Number.NaN, 101]) {
    assert.throws(
      () => buildNonrhythmicRequest({ ...state, hypothesis: 'greater', threshold }),
      RangeError,
    );
  }
});

test('effect summaries preserve direction and ratio magnitude', () => {
  assert.equal(foldRatio(1), 2);
  assert.equal(foldRatio(-1), 2);
  assert.equal(effectDirection(equalOverall, 1), 'APP23 higher than NTG');
  assert.equal(effectDirection(equalOverall, -1), 'APP23 lower than NTG');
  assert.equal(effectDirection({ family: 'sex' }, 1), 'Male higher than Female');
});

test('APP23 tab exposes exactly two question-mark help buttons and keeps all text in Arial', () => {
  const panel = readFileSync(new URL('../NonrhythmicPanel.jsx', import.meta.url), 'utf8');
  const result = readFileSync(new URL('../NonrhythmicResult.jsx', import.meta.url), 'utf8');
  const help = readFileSync(new URL('../NonrhythmicHelp.jsx', import.meta.url), 'utf8');
  const styles = readFileSync(new URL('../nonrhythmic.css', import.meta.url), 'utf8');
  const plot = readFileSync(new URL('../NonrhythmicExpressionPlot.jsx', import.meta.url), 'utf8');

  assert.equal((panel.match(/<HelpButton\b/g) || []).length, 2);
  assert.doesNotMatch(
    panel,
    /<h3>2\. Choose the biological question<\/h3>\s*<HelpButton/,
  );
  assert.match(panel, /<summary>Advanced: custom model contrast<\/summary>/);
  assert.match(panel, /label="Explain custom model contrasts"/);
  for (const coefficient of [
    'β_intercept',
    'β_sex',
    'β_age',
    'β_genotype',
    'β_genotype×age',
    'β_genotype×sex',
  ]) {
    assert.match(panel, new RegExp(coefficient));
  }
  assert.doesNotMatch(panel, /placeholder="0, 0, 0, 1, 0\.5, 0\.5"/);
  assert.match(panel, /type="number"/);
  assert.match(panel, /Active custom vector:/);
  assert.match(panel, /role="dialog"/);
  assert.match(panel, /aria-modal="true"/);
  assert.match(panel, /useState\('0\.1'\)/);
  assert.match(panel, /useState\('0\.2'\)/);
  assert.match(panel, /const FALLBACK_GENE = 'Idi1'/);
  assert.match(panel, /const FALLBACK_CONTRAST = 'genotype_altage_marginal_sex_equal'/);
  assert.match(panel, /useState\('age'\)/);
  assert.match(panel, />FDR threshold</);
  assert.match(panel, />Log2FC threshold</);
  assert.doesNotMatch(panel, /Filters the table by absolute Log2FC/);
  assert.match(panel, /hypothesis: 'zero'/);
  assert.match(panel, />Gene expression</);
  assert.match(panel, /Expression values are log2\(size-factor-normalized count \+ 1\)/);
  assert.doesNotMatch(panel, /Violin plots show kernel-density estimates/);
  assert.doesNotMatch(panel, /Selected statistical question/);
  assert.doesNotMatch(panel, /How to read the plot/);
  assert.doesNotMatch(panel, /What evidence should the test look for/);
  assert.match(result, />Log2 Fold Change</);
  assert.doesNotMatch(result, /Passes 5% FDR|Does not pass 5% FDR/);
  assert.match(help, /θ̂ = cᵀβ̂/);
  assert.match(help, /SE\(θ̂\) = √\(cᵀΣ̂c\)/);
  assert.match(help, /H₀: θ = 0/);
  assert.doesNotMatch(help, /Log2FC threshold|default T/);
  assert.match(help, /Benjamini–Hochberg/);
  assert.match(help, /Marginal comparisons give the\s+included age and sex groups equal\s+weight/);
  assert.match(help, /A custom contrast is a six-number vector/);
  assert.match(help, /coefficient\s+weights—not sample weights or expression values/);
  assert.match(help, /14 vs\. 7 months, averaged equally over NTG and APP23/);
  assert.match(help, /vector: '\[0, 0, 1, 0, 0\.5, 0\]'/);
  assert.match(help, /14 vs\. 7 months in NTG only/);
  assert.match(help, /vector: '\[0, 0, 1, 0, 0, 0\]'/);
  assert.match(help, /nr-coefficient-key/);
  assert.match(help, /nr-card-math-label/);
  assert.doesNotMatch(help, /Enter six values in this model-coefficient order/);
  assert.doesNotMatch(help, /β̂genotype|genotypeAPP23/);
  assert.doesNotMatch(help, /scientific target remains balanced|arithmetic count means/);
  assert.doesNotMatch(help, /Optional threshold tests|Weighted by sample count/);
  assert.match(styles, /\.nonrhythmic-panel[\s\S]*font-family: Arial, Helvetica, sans-serif/);
  assert.doesNotMatch(styles, /nr-question-banner|nr-fdr-status|nr-testing-options/);
  assert.match(plot, /const FONT = 'Arial,/);
});

test('public catalog hides observed-sample weighting', () => {
  const catalog = visibleContrastCatalog([
    { code: 'equal', weighting: 'equal' },
    { code: 'observed', weighting: 'observed' },
    { code: 'cell', weighting: 'cell' },
    { code: 'interaction', weighting: 'none' },
  ]);
  assert.deepEqual(catalog.map((contrast) => contrast.code), ['equal', 'cell', 'interaction']);
});
