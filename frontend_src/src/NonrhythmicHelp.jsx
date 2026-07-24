const CONTRAST_COEFFICIENTS = [
  { position: '1', subscript: '0', meaning: 'Intercept: NTG, 7 months, Female' },
  { position: '2', subscript: 'sex', meaning: 'Male − Female in NTG' },
  { position: '3', subscript: 'age', meaning: '14 − 7 months in NTG' },
  { position: '4', subscript: 'genotype', meaning: 'APP23 − NTG at 7 months in Female' },
  { position: '5', subscript: 'genotype × age', meaning: 'Change in APP23 − NTG at 14 vs. 7 months' },
  { position: '6', subscript: 'genotype × sex', meaning: 'Change in APP23 − NTG in Male vs. Female' },
];

const CONTRAST_EXAMPLES = [
  {
    title: 'Overall APP23 vs. NTG',
    target: 'APP23 − NTG, averaged equally over 7/14 months and Female/Male.',
    vector: '[0, 0, 0, 1, 0.5, 0.5]',
    terms: [{ subscript: 'genotype' }, { subscript: 'genotype × age', half: true }, { subscript: 'genotype × sex', half: true }],
    isDefault: true,
  },
  {
    title: '14 vs. 7 months, averaged equally over NTG and APP23',
    target: 'The model-based age contrast, with both genotypes contributing equally.',
    vector: '[0, 0, 1, 0, 0.5, 0]',
    terms: [{ subscript: 'age' }, { subscript: 'genotype × age', half: true }],
  },
  {
    title: '14 vs. 7 months in NTG only',
    target: 'The model-based age contrast restricted to NTG.',
    vector: '[0, 0, 1, 0, 0, 0]',
    terms: [{ subscript: 'age' }],
  },
  {
    title: 'APP23 vs. NTG at 14 months',
    target: 'APP23 − NTG at 14 months, averaged equally over Female and Male.',
    vector: '[0, 0, 0, 1, 1, 0.5]',
    terms: [{ subscript: 'genotype' }, { subscript: 'genotype × age' }, { subscript: 'genotype × sex', half: true }],
  },
];

function Beta({ subscript }) {
  return <var className="nr-beta">β<sub>{subscript}</sub></var>;
}

function HalfWeight() {
  return (
    <span className="nr-fraction" aria-label="one half">
      <sup>1</sup>&frasl;<sub>2</sub>
    </span>
  );
}

function ContrastExpression({ terms }) {
  return (
    <span>
      <var>Δ</var> =
      {terms.map(({ subscript, half }, index) => (
        <span key={subscript}>
          {index === 0 ? ' ' : ' + '}
          {half ? <HalfWeight /> : null}
          <Beta subscript={subscript} />
        </span>
      ))}
    </span>
  );
}

function ContrastCard({ title, target, vector, terms, isDefault = false }) {
  return (
    <article className={`nr-contrast-card${isDefault ? ' is-default' : ''}`}>
      <header>
        <h4>{title}</h4>
        {isDefault ? <span className="nr-default-badge">Default</span> : null}
      </header>
      <p>{target}</p>
      <div className="nr-card-math">
        <div>
          <span className="nr-card-math-label">Vector</span>
          <span><var>c</var> = {vector}</span>
        </div>
        <div>
          <span className="nr-card-math-label">Effect</span>
          <ContrastExpression terms={terms} />
        </div>
      </div>
    </article>
  );
}

export function NonrhythmicComparisonHelp() {
  return (
    <div className="nr-contrast-guide">
      <p className="nr-dialog-lead">
        This analysis compares expression within one anatomical cluster. It does not include a
        time-of-day term and does not test rhythmicity, phase, or amplitude.
      </p>

      <section className="nr-guide-section" aria-labelledby="nr-contrast-model-heading">
        <h3 id="nr-contrast-model-heading">From the fitted model to one comparison</h3>
        <p>DESeq2 fits a negative-binomial model to raw counts from the selected cluster:</p>
        <div className="nr-model-equations" aria-label="Negative binomial model and design">
          <span>
            <var>K<sub>ij</sub></var> ∼ NB(<var>μ<sub>ij</sub></var>, <var>α<sub>i</sub></var>)
          </span>
          <span>
            <var>μ<sub>ij</sub></var> = <var>s<sub>j</sub></var><var>q<sub>ij</sub></var>
          </span>
          <span>
            log<sub>2</sub>(<var>q<sub>ij</sub></var>) =
            {' '}<var>x<sub>j</sub></var><sup>T</sup><var>β<sub>i</sub></var>
          </span>
          <span className="nr-model-design">
            Design: ~ sex + age + genotype + genotype × age + genotype × sex
          </span>
        </div>
        <p>
          Reference levels are <strong>NTG</strong>, <strong>7 months</strong>, and
          {' '}<strong>Female</strong>. For gene <var>i</var>, the modeled Log2 Fold Change is:
        </p>
        <div className="nr-contrast-definition" aria-label="Contrast definition">
          <span>
            <var>Δ<sub>i</sub></var> =
            {' '}<var>c</var><sup>T</sup><var>β<sub>i</sub></var>
          </span>
        </div>
        <p>
          A custom contrast is a six-number vector, <var>c</var>. Read it from left to right:
          each number multiplies the fitted coefficient in the matching row below, and the six
          products are added to give <var>Δ<sub>i</sub></var>. These are coefficient
          weights—not sample weights or expression values. Marginal comparisons give the
          included age and sex groups equal weight.
        </p>
        <dl className="nr-weight-key" aria-label="Meaning of common contrast weights">
          <div><dt>0</dt><dd>exclude a coefficient</dd></div>
          <div><dt>1</dt><dd>add a coefficient</dd></div>
          <div><dt>−1</dt><dd>subtract a coefficient</dd></div>
          <div><dt><sup>1</sup>&frasl;<sub>2</sub></dt><dd>give half weight</dd></div>
        </dl>
      </section>

      <section className="nr-guide-section" aria-labelledby="nr-coefficient-key-heading">
        <h3 id="nr-coefficient-key-heading">Coefficient order</h3>
        <p>
          The first vector entry acts on position 1, the second on position 2, and so on.
        </p>
        <div className="nr-coefficient-table-wrap">
          <table className="nr-coefficient-key">
            <caption>Six positions in the custom contrast vector</caption>
            <thead>
              <tr>
                <th scope="col">Position</th>
                <th scope="col">Coefficient</th>
                <th scope="col">Reference meaning</th>
              </tr>
            </thead>
            <tbody>
              {CONTRAST_COEFFICIENTS.map(({ position, subscript, meaning }) => (
                <tr key={position}>
                  <th scope="row">{position}</th>
                  <td><Beta subscript={subscript} /></td>
                  <td>{meaning}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="nr-guide-section" aria-labelledby="nr-contrast-examples-heading">
        <h3 id="nr-contrast-examples-heading">Useful contrasts</h3>
        <div className="nr-contrast-card-grid">
          {CONTRAST_EXAMPLES.map((example) => (
            <ContrastCard key={example.title} {...example} />
          ))}
        </div>
        <p className="nr-direction-note">
          For the default APP23 − NTG contrast, positive values indicate higher modeled expression
          in APP23; negative values indicate higher modeled expression in NTG. For any custom
          contrast, multiplying every weight by −1 reverses its direction.
        </p>
      </section>

      <div className="nr-dialog-callout">
        <strong>The plot does not change the test.</strong>
        <span>
          The plot shows transformed sample-level observations. The estimate and Wald test come
          from the raw-count DESeq2 model; faceting only rearranges the displayed observations.
        </span>
      </div>
    </div>
  );
}

export function NonrhythmicStatisticsHelp() {
  return (
    <>
      <p>
        The selected comparison gives an estimate θ̂. Its uncertainty uses the fitted
        six-coefficient covariance matrix Σ̂:
      </p>
      <div className="nr-math-block" aria-label="Contrast estimate and standard error">
        <code>θ̂ = cᵀβ̂</code>
        <code>SE(θ̂) = √(cᵀΣ̂c)</code>
      </div>
      <p>
        The standard error describes model uncertainty; it is not the standard deviation of
        the plotted sample dots.
      </p>
      <h3>Two-sided Wald test</h3>
      <div className="nr-math-block" aria-label="Two-sided Wald test against zero">
        <code>H₀: θ = 0 &nbsp; versus &nbsp; H₁: θ ≠ 0</code>
        <code>z = θ̂ / SE(θ̂)</code>
        <code>p = 2[1 − Φ(|z|)]</code>
      </div>
      <p>Φ is the standard-normal cumulative distribution function.</p>
      <h3>Multiple testing</h3>
      <p>
        Raw P values are Benjamini–Hochberg adjusted across all <em>m</em> testable genes in
        this same cluster, comparison, and test:
      </p>
      <div className="nr-math-block" aria-label="Benjamini Hochberg adjusted P value">
        <code>p-adjusted(i) = minⱼ≥ᵢ min(1, m × p(j) / j)</code>
      </div>
      <p>
        Genes with nonfinite, invalid, or zero variance are excluded. No independent filtering
        or Cook&apos;s-distance cutoff is applied. The adjusted P value controls false-discovery
        rate; it is not the probability that the null hypothesis is true.
      </p>
    </>
  );
}
