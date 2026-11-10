<?php

namespace Omnilang\Google\Tests;

use Omnilang\Exception\InvalidConfigException;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\Google\GoogleFactory;
use Omnilang\Google\GoogleProvider;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\Language;
use Omnilang\Model\TextFormat;
use Omnilang\Model\TranslationQuery;
use Omnilang\Testing\MockHttp;
use PHPUnit\Framework\TestCase;

/**
 * Cloud Translation v3's answers written from its discovery document
 * (translate.googleapis.com/$discovery/rest?version=v3, read on
 * 2026-11-10: TranslateTextResponse, DetectLanguageResponse,
 * SupportedLanguages, ListGlossariesResponse, ListGlossaryEntriesResponse,
 * TranslateDocumentResponse); no call made (a token is needed). Every test
 * checks the exact request.
 */
final class GoogleProviderTest extends TestCase
{
    private const TOKEN = 'ya29.not-a-real-google-token';
    private const FIXTURES = __DIR__.'/Fixtures/';
    private const GLOBAL = 'https://translation.googleapis.com/v3/projects/my-project/locations/global';
    private const REGION = 'https://translation.googleapis.com/v3/projects/my-project/locations/us-central1';

    public function testTextsTranslatedTheirSourceDetected(): void
    {
        $http = self::http();
        $translations = self::provider($http)->translate(TranslationQuery::text(['Chambre double vue jardin', 'Petit-déjeuner compris'], 'en'));

        self::assertSame(['Double room with garden view', 'Breakfast included'], array_map('strval', $translations));
        self::assertSame(['fr', null, 'google'], [$translations[0]->detected?->code, $translations[0]->glossary, $translations[0]->provider]);
        self::assertSame(['POST '.self::GLOBAL.':translateText'], $http->lines());
        self::assertSame('Bearer '.self::TOKEN, $http->header('Authorization'));
        self::assertNull($http->header('x-goog-user-project'));
        self::assertSame(['contents' => ['Chambre double vue jardin', 'Petit-déjeuner compris'], 'mimeType' => 'text/plain', 'targetLanguageCode' => 'en'], $http->json());
    }

    public function testHtmlThroughARegionalGlossaryAndModel(): void
    {
        $http = self::http();
        $provider = self::provider($http, ['location' => 'us-central1', 'model' => 'general/nmt', 'quota_project' => 'my-project']);
        $translation = $provider->translate(new TranslationQuery("<p>Chambre d'hôtes</p>", 'en', 'fr', TextFormat::Html, glossaries: 'projects/my-project/locations/us-central1/glossaries/rooms'))[0];

        self::assertSame(['<p>Bed and breakfast</p>', 'fr', 'projects/my-project/locations/us-central1/glossaries/rooms'], [$translation->text, $translation->sourceLanguage()?->code, $translation->glossary], 'the glossary\'s translation');
        self::assertSame(['POST '.self::REGION.':translateText'], $http->lines());
        self::assertSame('my-project', $http->header('x-goog-user-project'));
        self::assertSame([
            'contents' => ["<p>Chambre d'hôtes</p>"], 'mimeType' => 'text/html', 'sourceLanguageCode' => 'fr', 'targetLanguageCode' => 'en',
            'model' => 'projects/my-project/locations/us-central1/models/general/nmt',
            'glossaryConfig' => ['glossary' => 'projects/my-project/locations/us-central1/glossaries/rooms'],
        ], $http->json());
        self::assertTrue($provider->capabilities()->glossaryInTranslation);
        self::assertFalse(self::provider($http)->capabilities()->glossaryInTranslation, 'no glossary in "global"');

        $this->expectException(NotSupportedException::class);
        self::provider($http)->translate(new TranslationQuery('Bonjour', 'en', 'fr', glossaries: 'rooms'));
    }

    public function testLanguagesAndDetection(): void
    {
        $http = self::http();
        $provider = self::provider($http);

        self::assertSame(['en', 'zh-CN', 'ja'], array_map(static fn ($l) => $l->language->code, $provider->languages()));
        $detections = $provider->detect(['Guten Morgen', 'Gute Nacht']);
        self::assertSame(['de', 0.98, 'nl', 'google'], [$detections[0]->language->code, $detections[0]->confidence, $detections[0]->alternatives[0]->language->code, $detections[0]->provider]);
        self::assertSame([
            'GET '.self::GLOBAL.'/supportedLanguages?displayLanguageCode=en',
            'POST '.self::GLOBAL.':detectLanguage',
            'POST '.self::GLOBAL.':detectLanguage',
        ], $http->lines(), 'one text a detection');
        self::assertSame(['content' => 'Gute Nacht', 'mimeType' => 'text/plain'], $http->json());
    }

    public function testGlossariesListedReadAndDeleted(): void
    {
        $http = self::http();
        $provider = self::provider($http, ['location' => 'us-central1']);
        $glossaries = $provider->glossaries();

        self::assertSame(['rooms', 'brands'], array_map(static fn ($g) => $g->id, $glossaries), 'two pages');
        self::assertSame(['Rooms', 2, '2026-11-10T08:00:00+00:00'], [$glossaries[0]->name, $glossaries[0]->entries(), $glossaries[0]->createdAt?->format(\DATE_ATOM)]);
        self::assertTrue($glossaries[1]->covers('ja', 'fr'), 'an equivalent term set covers each direction');
        self::assertSame(["Chambre d'hôtes" => 'Bed and breakfast', 'Nakaya' => 'Nakaya'], $provider->glossaryEntries('rooms', Language::of('fr'), Language::of('en')));
        self::assertSame(['Nakaya' => 'なかや'], array_slice($provider->glossaryEntries('rooms', Language::of('fr'), Language::of('ja')), 1));
        self::assertNull($provider->glossary('missing'));
        $provider->deleteGlossary('rooms');

        self::assertSame([
            'GET '.self::REGION.'/glossaries?pageSize=100',
            'GET '.self::REGION.'/glossaries?pageSize=100&pageToken=page-2',
            'GET '.self::REGION.'/glossaries/rooms/glossaryEntries?pageSize=1000',
            'GET '.self::REGION.'/glossaries/rooms/glossaryEntries?pageSize=1000',
            'GET '.self::REGION.'/glossaries/missing',
            'DELETE '.self::REGION.'/glossaries/rooms',
        ], $http->lines());

        $this->expectException(NotSupportedException::class);
        $provider->createGlossary('Rooms', Language::of('fr'), Language::of('en'), ['a' => 'b']);
    }

    public function testADocumentTranslatedAtOnce(): void
    {
        $http = self::http();
        $job = self::provider($http, ['location' => 'us-central1'])->translateDocument(new DocumentQuery(new Document('%PDF-1.7 carte', 'carte.pdf'), 'en'));

        self::assertSame([true, 'fr', '%PDF-1.7 translated', 'carte.en.pdf', 'application/pdf'], [$job->isDone(), $job->detected?->code, $job->result?->content, $job->result?->filename, $job->result?->mimeType]);
        self::assertSame(['POST '.self::REGION.':translateDocument'], $http->lines());
        self::assertSame(['targetLanguageCode' => 'en', 'documentInputConfig' => ['content' => base64_encode('%PDF-1.7 carte'), 'mimeType' => 'application/pdf']], $http->json());
        self::assertSame([], self::provider($http)->capabilities()->documentTypes, 'no document in "global"');
    }

    public function testErrorsKeepTheTokenOut(): void
    {
        $http = MockHttp::routes(['~:translateText~' => [403, '{"error": {"code": 403, "message": "User Rate Limit Exceeded", "status": "PERMISSION_DENIED"}}']]);
        try {
            self::provider($http)->translate(TranslationQuery::text('Bonjour', 'en'));
            self::fail('A 403 is an error.');
        } catch (ProviderException $e) {
            self::assertSame(403, $e->status);
            self::assertStringContainsString('User Rate Limit Exceeded', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage().$e->getTraceAsString());
        }

        $this->expectException(InvalidConfigException::class);
        self::provider($http, ['location' => 'us central']);
    }

    private static function http(): MockHttp
    {
        $g = '~^%s https://translation\.googleapis\.com/v3/projects/my-project/locations/(global|us-central1)%s~';

        return MockHttp::routes([
            \sprintf($g, 'POST', ':translateText$') => static fn (string $method, string $url, string $body): array => [200, '@'.self::FIXTURES.(str_contains($body, 'glossaryConfig') ? 'translate-glossary.json' : 'translate.json')],
            \sprintf($g, 'GET', '/supportedLanguages\?') => [200, '@'.self::FIXTURES.'languages.json'],
            \sprintf($g, 'POST', ':detectLanguage$') => [200, '@'.self::FIXTURES.'detect.json'],
            \sprintf($g, 'GET', '/glossaries\?pageSize=100$') => [200, '@'.self::FIXTURES.'glossaries-1.json'],
            \sprintf($g, 'GET', '/glossaries\?pageSize=100&pageToken=page-2$') => [200, '@'.self::FIXTURES.'glossaries-2.json'],
            \sprintf($g, 'GET', '/glossaries/rooms/glossaryEntries\?') => [200, '@'.self::FIXTURES.'glossary-entries.json'],
            \sprintf($g, 'DELETE', '/glossaries/rooms$') => [200, '{"name": "projects/123456/locations/us-central1/operations/delete-1", "done": false}'],
            \sprintf($g, 'POST', ':translateDocument$') => [200, '@'.self::FIXTURES.'document.json'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(MockHttp $http, array $options = []): GoogleProvider
    {
        $provider = (new GoogleFactory($http->client))->create($options + ['project' => 'my-project', 'access_token' => self::TOKEN]);
        self::assertInstanceOf(GoogleProvider::class, $provider);

        return $provider;
    }
}
