<?php

namespace Omnilang\Google;

use Omnilang\Capabilities;
use Omnilang\DetectorInterface;
use Omnilang\DocumentsInterface;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\GlossaryInterface;
use Omnilang\Http\Data;
use Omnilang\Http\JsonClient;
use Omnilang\Model\Detection;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentJob;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\DocumentStatus;
use Omnilang\Model\Glossary;
use Omnilang\Model\Language;
use Omnilang\Model\LanguagePair;
use Omnilang\Model\SupportedLanguage;
use Omnilang\Model\Translation;
use Omnilang\Model\TranslationQuery;
use Omnilang\TranslatorInterface;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * Cloud Translation - Advanced (v3), the edition with glossaries and
 * document translation (docs.cloud.google.com/translate/docs/editions, and
 * its discovery document, read on 2026-11-10): texts and HTML
 * (:translateText, up to 1,024 texts and about 30,000 code points a
 * request), detection (:detectLanguage, one text a request), the
 * supported languages, glossaries listed, read, deleted and applied (a
 * glossary is built from a Cloud Storage file, so createGlossary() is not
 * here), documents translated at once (:translateDocument: PDF, DOCX,
 * PPTX, XLSX up to 20 MB). Every request carries the OAuth 2.0 access
 * token as "Authorization: Bearer"; v3 takes no API key.
 */
final class GoogleProvider implements TranslatorInterface, DetectorInterface, GlossaryInterface, DocumentsInterface, DeclaresUsagePolicyInterface
{
    public const POLICY = __DIR__.'/policy.json';

    /** :translateDocument's types (application/pdf and the three Office formats). */
    public const DOCUMENT_TYPES = ['pdf', 'docx', 'pptx', 'xlsx'];

    public function __construct(
        private readonly JsonClient $json,
        private readonly string $baseUri,
        private readonly string $project,
        private readonly string $location = 'global',
        private readonly ?string $model = null,
    ) {
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getTitle(): string
    {
        return 'Google Cloud Translation';
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        $regional = 'global' !== $this->location;

        return new Capabilities(
            $this->usagePolicy(),
            translate: true,
            detect: true,
            glossary: true,
            documents: $regional,
            html: true,
            glossaryInTranslation: $regional,
            detectsSource: true,
            documentTypes: $regional ? self::DOCUMENT_TYPES : [],
            maxTexts: 1024,
            maxCharacters: 30000,
        );
    }

    public function translate(TranslationQuery $query): array
    {
        $glossary = $this->glossaryConfig($query->glossaryFor($this->getName()), null !== $query->source);
        $body = array_filter([
            'contents' => $query->texts,
            'mimeType' => $query->isHtml() ? 'text/html' : 'text/plain',
            'sourceLanguageCode' => $query->source?->code,
            'targetLanguageCode' => $query->target->code,
            'model' => $this->modelPath(),
            'glossaryConfig' => $glossary,
        ], static fn ($value) => null !== $value);
        $data = $this->json->request('POST', $this->parent().':translateText', ['json' => $body]) ?? throw ProviderException::answered('Google Cloud Translation', 'POST :translateText', 404);
        $answers = $data->list(null === $glossary ? 'translations' : 'glossaryTranslations');
        if (\count($answers) !== \count($query->texts)) {
            throw ProviderException::unexpected('Google Cloud Translation', 'POST :translateText', \sprintf('%d translations for %d texts.', \count($answers), \count($query->texts)));
        }

        return array_map(fn (Data $one) => new Translation(
            \is_string($text = $one->get('translatedText')) ? $text : '',
            $query->target,
            'google',
            $query->source,
            null === $query->source ? Language::tryOf($one->string('detectedLanguageCode')) : null,
            $query->format,
            null,
            null === $glossary ? null : $query->glossaryFor($this->getName()),
        ), $answers);
    }

    public function languages(): array
    {
        $data = $this->json->get($this->parent().'/supportedLanguages', ['displayLanguageCode' => 'en', 'model' => $this->modelPath()]);
        $languages = [];
        foreach ($data?->list('languages') ?? [] as $one) {
            if (null !== $language = Language::tryOf($one->string('languageCode'))) {
                $languages[] = new SupportedLanguage($language, $one->string('displayName'), true === $one->bool('supportSource'), true === $one->bool('supportTarget'));
            }
        }

        return $languages;
    }

    public function detect(array $texts): array
    {
        return array_map(function (string $text): Detection {
            $data = $this->json->request('POST', $this->parent().':detectLanguage', ['json' => ['content' => $text, 'mimeType' => 'text/plain']]);
            $found = [];
            foreach ($data?->list('languages') ?? [] as $one) {
                if (null !== $language = Language::tryOf($one->string('languageCode'))) {
                    $found[] = new Detection($language, 'google', $one->float('confidence'));
                }
            }

            return [] === $found ? throw ProviderException::unexpected('Google Cloud Translation', 'POST :detectLanguage', 'No language.') : new Detection($found[0]->language, 'google', $found[0]->confidence, \array_slice($found, 1));
        }, $texts);
    }

    public function createGlossary(string $name, Language $source, Language $target, array $entries): Glossary
    {
        throw NotSupportedException::call('Google Cloud Translation', 'build a glossary from entries: it reads a TSV, CSV or TMX file from Cloud Storage (glossaries.create, inputConfig.gcsSource); make it there, then use its id');
    }

    public function glossaries(): array
    {
        $glossaries = [];
        $token = null;
        do {
            $data = $this->json->get($this->parent().'/glossaries', ['pageSize' => 100, 'pageToken' => $token]);
            foreach ($data?->list('glossaries') ?? [] as $one) {
                $glossaries[] = $this->readGlossary($one);
            }
            $token = $data?->string('nextPageToken');
        } while (null !== $token);

        return $glossaries;
    }

    public function glossary(string $id): ?Glossary
    {
        $data = $this->json->get($this->glossaryPath($id));

        return null === $data ? null : $this->readGlossary($data);
    }

    public function glossaryEntries(string $id, Language $source, Language $target): array
    {
        $entries = [];
        $token = null;
        do {
            $data = $this->json->get($this->glossaryPath($id).'/glossaryEntries', ['pageSize' => 1000, 'pageToken' => $token]);
            foreach ($data?->list('glossaryEntries') ?? [] as $entry) {
                [$from, $to] = self::terms($entry, $source, $target);
                if (null !== $from && null !== $to) {
                    $entries[$from] = $to;
                }
            }
            $token = $data?->string('nextPageToken');
        } while (null !== $token);

        return $entries;
    }

    public function deleteGlossary(string $id): void
    {
        $this->json->request('DELETE', $this->glossaryPath($id));
    }

    public function translateDocument(DocumentQuery $query): DocumentJob
    {
        if ('global' === $this->location) {
            throw NotSupportedException::call('Google Cloud Translation', 'translate documents in the "global" location: configure a region (location: us-central1)');
        }
        $glossaryId = $query->glossaryFor($this->getName());
        $glossary = $this->glossaryConfig($glossaryId, null !== $query->source);
        $body = array_filter([
            'sourceLanguageCode' => $query->source?->code,
            'targetLanguageCode' => $query->target->code,
            'documentInputConfig' => ['content' => base64_encode($query->document->content), 'mimeType' => $query->document->mimeType],
            'model' => $this->modelPath(),
            'glossaryConfig' => $glossary,
        ], static fn ($value) => null !== $value);
        $data = $this->json->request('POST', $this->parent().':translateDocument', ['json' => $body]) ?? throw ProviderException::answered('Google Cloud Translation', 'POST :translateDocument', 404);
        $translated = $data->data(null === $glossary ? 'documentTranslation' : 'glossaryDocumentTranslation');
        $bytes = base64_decode((string) ($translated->strings('byteStreamOutputs')[0] ?? ''), true);
        if (false === $bytes || '' === $bytes) {
            throw ProviderException::unexpected('Google Cloud Translation', 'POST :translateDocument', 'No byteStreamOutputs.');
        }
        $result = new Document($bytes, $query->translatedName(), $translated->string('mimeType') ?? $query->document->mimeType);
        $id = 'google-'.substr(hash('sha256', $query->document->content.$query->target->code), 0, 16);

        return new DocumentJob($id, 'google', DocumentStatus::Done, $query->target, $query->source, filename: $result->filename, result: $result, detected: Language::tryOf($translated->string('detectedLanguageCode')));
    }

    public function documentStatus(DocumentJob $job): DocumentJob
    {
        return $job;
    }

    public function documentResult(DocumentJob $job): Document
    {
        return $job->result ?? throw NotSupportedException::call('Google Cloud Translation', 'keep a document: :translateDocument answers it at once');
    }

    private function parent(): string
    {
        return $this->baseUri.'/v3/projects/'.$this->project.'/locations/'.$this->location;
    }

    private function modelPath(): ?string
    {
        return null === $this->model ? null : 'projects/'.$this->project.'/locations/'.$this->location.'/models/'.$this->model;
    }

    private function glossaryPath(string $id): string
    {
        return $this->parent().'/glossaries/'.rawurlencode(self::glossaryId($id));
    }

    /** @return ?array{glossary: string} */
    private function glossaryConfig(?string $id, bool $source): ?array
    {
        if (null === $id) {
            return null;
        }
        if ('global' === $this->location) {
            throw NotSupportedException::call('Google Cloud Translation', 'apply a glossary in the "global" location: a glossary lives in a region (location: us-central1)');
        }
        if (!$source) {
            throw NotSupportedException::call('Google Cloud Translation', 'apply a glossary without the source language');
        }

        return ['glossary' => 'projects/'.$this->project.'/locations/'.$this->location.'/glossaries/'.self::glossaryId($id)];
    }

    /** The glossary's own id, from its id or its full name ("projects/p/locations/l/glossaries/rooms"). */
    private static function glossaryId(string $id): string
    {
        return str_contains($id, '/glossaries/') ? substr($id, (int) strrpos($id, '/') + 1) : $id;
    }

    private function readGlossary(Data $data): Glossary
    {
        $name = $data->string('name') ?? throw ProviderException::unexpected('Google Cloud Translation', 'a glossary', 'No name.');
        $pairs = [];
        $source = Language::tryOf($data->string('languagePair.sourceLanguageCode'));
        $target = Language::tryOf($data->string('languagePair.targetLanguageCode'));
        if (null !== $source && null !== $target) {
            $pairs[] = new LanguagePair($source, $target, $data->int('entryCount'));
        }
        $set = array_values(array_filter(array_map(Language::tryOf(...), $data->strings('languageCodesSet.languageCodes'))));
        foreach ($set as $from) {
            foreach ($set as $to) {
                if ($from !== $to) {
                    $pairs[] = new LanguagePair($from, $to);
                }
            }
        }

        return new Glossary(self::glossaryId($name), $data->string('displayName') ?? self::glossaryId($name), 'google', $pairs, $data->dateTime('submitTime'));
    }

    /** @return array{0: ?string, 1: ?string} a glossary entry's source and target terms */
    private static function terms(Data $entry, Language $source, Language $target): array
    {
        if ($entry->has('termsPair')) {
            return [$entry->string('termsPair.sourceTerm.text'), $entry->string('termsPair.targetTerm.text')];
        }
        $from = $to = null;
        foreach ($entry->list('termsSet.terms') as $term) {
            $language = Language::tryOf($term->string('languageCode'));
            if (null !== $language && $language->sameLanguage($source)) {
                $from = $term->string('text');
            } elseif (null !== $language && $language->sameLanguage($target)) {
                $to = $term->string('text');
            }
        }

        return [$from, $to];
    }
}
