<?php

namespace Omnilang\Google\Tests;

use Omnilang\Exception\UsageLimitReachedException;
use Omnilang\Google\GoogleFactory;
use Omnilang\Http\Data;
use Omnilang\Model\TranslationQuery;
use Omnilang\Registry;
use Omnilang\Testing\MockHttp;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;
use PHPUnit\Framework\TestCase;

/** Google's quotas and attribution as declared in policy.json, and a site's limit on top: a refused call is never sent. */
final class PolicyTest extends TestCase
{
    public function testTheDeclaredPolicy(): void
    {
        $policy = self::registry(MockHttp::routes([]))->policy('google');
        $composer = Data::of(json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame('policy.json', $composer->string('extra.omnisense.policy'));
        self::assertEquals(UsagePolicy::fromFile(__DIR__.'/../policy.json'), $policy);
        self::assertSame([[6000, Window::Minute, []], [600, Window::Minute, ['languages']]], array_map(static fn ($l) => [$l->max, $l->window, $l->operations], $policy->rateLimits ?? []));
        self::assertSame(2, \count($policy->limitsFor('languages')), 'both count the languages');
        self::assertSame(['powered by Google Translate', 'http://translate.google.com', true], [$policy->rights->attribution->text, $policy->rights->attribution->url, $policy->rights->attribution->required]);
        self::assertStringContainsString('never to train', (string) $policy->provenance->notes);
    }

    public function testASitesLimitOnTop(): void
    {
        $http = MockHttp::routes(['~:translateText$~' => [200, '{"translations": [{"translatedText": "Hello", "detectedLanguageCode": "fr"}]}']]);
        $registry = self::registry($http, ['rateLimits' => [['max' => 1, 'window' => 'hour', 'operations' => ['translate']]]]);

        self::assertSame('Hello', $registry->translator('google')->translate(TranslationQuery::text('Bonjour', 'en'))[0]->text);
        try {
            $registry->translator('google')->translate(TranslationQuery::text('Bonjour', 'en'));
            self::fail('The site allows one an hour.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('google', $e->provider);
        }
        self::assertCount(1, $http->requests, 'the refused call was never sent');
    }

    /** @param array<string, mixed> $policy */
    private static function registry(MockHttp $http, array $policy = []): Registry
    {
        return new Registry([new GoogleFactory($http->client)], ['google' => ['factory' => 'google', 'options' => ['project' => 'my-project', 'access_token' => 'not-a-real-token'], 'policy' => $policy]]);
    }
}
