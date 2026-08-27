import { readEnvValue } from './lib/env.mjs'

/**
 * Lists the models this API key can reach, so image generation targets a model that
 * actually exists for this account rather than one assumed from documentation.
 */
const key = readEnvValue('GOOGLE_AI_API_KEY')

const response = await fetch(
  `https://generativelanguage.googleapis.com/v1beta/models?key=${key}&pageSize=200`,
)

if (!response.ok) {
  // Never echo the key, even on failure.
  console.error('Request failed:', response.status, response.statusText)
  console.error((await response.text()).slice(0, 400))
  process.exit(1)
}

const { models = [] } = await response.json()

const imageCapable = models.filter(
  (model) =>
    /image|imagen/i.test(model.name)
    || (model.supportedGenerationMethods ?? []).some((m) => /predict/i.test(m)),
)

console.log(`total models: ${models.length}`)
console.log('\n--- image capable ---')
for (const model of imageCapable) {
  console.log(`${model.name}  [${(model.supportedGenerationMethods ?? []).join(', ')}]`)
}

// Every model that can answer a generateContent call, not only the image ones. Room
// analysis sends a photograph *and* asks for text back, so the text models matter too.
console.log('\n--- all generateContent models ---')

for (const model of models) {
  if ((model.supportedGenerationMethods ?? []).includes('generateContent')) {
    console.log(model.name)
  }
}
