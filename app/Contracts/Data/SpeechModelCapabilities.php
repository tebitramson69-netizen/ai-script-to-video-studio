<?php

namespace App\Contracts\Data;

use InvalidArgumentException;

/**
 * Everything the pipeline needs to know about one text-to-speech model.
 *
 * The sibling of ModelCapabilities, kept separate rather than generalised: a
 * video model is priced by the second and constrained by a clip-length ladder,
 * a speech model is priced by the character and constrained by language. One
 * shared class would carry half its fields as null for every caller.
 *
 * `endpoints` exists because language selection is sometimes ENDPOINT
 * selection. Kokoro ships a different model id per language
 * (fal-ai/kokoro/american-english, fal-ai/kokoro/french) while ElevenLabs takes
 * one endpoint for all of them. Modelling that as a map means a bilingual
 * project — which matters in Cameroon, where English and French are both
 * official — is a config entry rather than a special case in the adapter.
 */
readonly class SpeechModelCapabilities
{
    /**
     * @param  array<string, string>  $endpoints  language code => provider model id
     * @param  list<string>  $payloadParameters  optional request fields this model is known to accept
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $endpoint,
        public array $endpoints,
        public float $costPer1kCharactersUsd,
        public ?string $defaultVoice = null,
        public array $payloadParameters = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            label: $config['label'] ?? $key,
            endpoint: $config['endpoint'] ?? null,
            endpoints: array_map('strval', $config['endpoints'] ?? []),
            costPer1kCharactersUsd: (float) ($config['cost_per_1k_characters_usd'] ?? 0.0),
            defaultVoice: $config['default_voice'] ?? null,
            payloadParameters: array_values(array_map('strval', $config['payload_parameters'] ?? [])),
        );
    }

    /**
     * The model id to call for this language.
     *
     * Throws rather than quietly falling back to another language's endpoint:
     * narration in the wrong language is not a degraded result, it is a wrong
     * one, and it would be paid for before anyone noticed.
     */
    public function endpointFor(string $language): string
    {
        $language = strtolower(trim($language)) ?: 'en';

        // Exact match first, then the base tag — so fr-CA falls back to fr but
        // never to en.
        foreach ([$language, explode('-', $language)[0]] as $candidate) {
            if (isset($this->endpoints[$candidate])) {
                return $this->endpoints[$candidate];
            }
        }

        if ($this->endpoints !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s has no endpoint for language '%s'. It speaks: %s.",
                $this->label,
                $language,
                implode(', ', array_keys($this->endpoints)),
            ));
        }

        if ((string) $this->endpoint === '') {
            throw new InvalidArgumentException("Speech model '{$this->key}' declares no endpoint.");
        }

        return (string) $this->endpoint;
    }

    public function speaks(string $language): bool
    {
        if ($this->endpoints === []) {
            return true;
        }

        $language = strtolower(trim($language)) ?: 'en';

        return isset($this->endpoints[$language])
            || isset($this->endpoints[explode('-', $language)[0]]);
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_keys($this->endpoints);
    }

    public function sendsParameter(string $name): bool
    {
        return in_array($name, $this->payloadParameters, true);
    }

    public function costForCharacters(int $characters): float
    {
        return round(($characters / 1000) * $this->costPer1kCharactersUsd, 6);
    }
}
