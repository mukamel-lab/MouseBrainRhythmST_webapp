export const DIMENSIONS = {
  region: [
    { value: 'L23', label: 'Cortex Layer 2/3', color: '#72A075' },
    { value: 'L4', label: 'Cortex Layer 4', color: '#B47746' },
    { value: 'DGsg', label: 'Dentate Gyrus granule layer', color: '#468FCD' },
    { value: 'CA1', label: 'CA1', color: '#519AC4' },
  ],
  age: [
    { value: '7 months', label: '7 months', color: '#FFFF99' },
    { value: '14 months', label: '14 months', color: '#D8B365' },
  ],
  sex: [
    { value: 'F', label: 'Female', color: '#E6A0C4' },
    { value: 'M', label: 'Male', color: '#C6CDF7' },
  ],
  genotype: [
    { value: 'APP23', label: 'APP23', color: '#BC3C29' },
    { value: 'NTG', label: 'NTG', color: '#0072B5' },
  ],
};

export const TARGET_REGION_VALUES = ['L23', 'L4'];

export function makePlotFixture() {
  const observations = [];
  const coefficients = [];
  let sampleIndex = 0;

  for (const region of DIMENSIONS.region.map((entry) => entry.value)) {
    for (const age of DIMENSIONS.age.map((entry) => entry.value)) {
      for (const sex of DIMENSIONS.sex.map((entry) => entry.value)) {
        for (const genotype of DIMENSIONS.genotype.map((entry) => entry.value)) {
          coefficients.push({
            n: 12,
            intercept: 5,
            sinCoef: genotype === 'APP23' ? 0.8 : 0.6,
            cosCoef: 0.2,
            region,
            age,
            sex,
            genotype,
          });

          for (const ZT of [0, 6, 12, 18]) {
            for (let replicate = 0; replicate < 3; replicate += 1) {
              observations.push({
                sampleKey: `sample-${sampleIndex += 1}`,
                ZT,
                normExpr: 5 + replicate * 0.1,
                region,
                age,
                sex,
                genotype,
              });
            }
          }
        }
      }
    }
  }

  return { observations, coefficients };
}
