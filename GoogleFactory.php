<?php

namespace Omnilang\Google;

use Omnilang\Config;
use Omnilang\Exception\InvalidConfigException;
use Omnilang\Http\JsonClient;
use Omnilang\ProviderFactory;
use Omnilang\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "google": Cloud Translation - Advanced (v3). Options project (required:
 * the Google Cloud project's id or number), access_token (required, by
 * reference: "env:GOOGLE_TRANSLATE_TOKEN", or the value the application
 * gets from its service account or Application Default Credentials - v3
 * takes no API key; a token lasts about an hour, the application renews
 * it), location ("global" by default; a region such as "us-central1" for
 * glossaries and documents), model (a model of the location: "general/nmt",
 * "general/translation-llm"; the general NMT model when not given),
 * quota_project (sent as x-goog-user-project, for user credentials),
 * base_uri (https://translation.googleapis.com), timeout (seconds); policy,
 * usage.
 */
final class GoogleFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilang.factory_name' => 'google',
            'omnilang.factory_title' => 'Google Cloud Translation',
            'omnilang.required_options' => ['project', 'access_token'],
            'location' => 'global',
            'model' => null,
            'quota_project' => null,
            'base_uri' => 'https://translation.googleapis.com',
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $token = (string) $c->secret('access_token');
        $base = rtrim((string) $c->string('base_uri'), '/');
        if (!preg_match('~^https?://[^/\s]+~', $base)) {
            throw new InvalidConfigException(\sprintf('The "google" provider needs a base_uri such as https://translation.googleapis.com, not "%s".', $base));
        }
        foreach (['project' => '~^[a-z0-9][a-z0-9:.\-]*$~', 'location' => '~^[a-z0-9\-]+$~'] as $option => $pattern) {
            if (!preg_match($pattern, (string) $c->string($option))) {
                throw new InvalidConfigException(\sprintf('The "google" provider\'s %s is not "%s".', $option, (string) $c->string($option)));
            }
        }
        $model = $c->string('model');
        if (null !== $model && !preg_match('~^[A-Za-z0-9_\-]+/[A-Za-z0-9_\-]+$~', $model)) {
            throw new InvalidConfigException(\sprintf('The "google" provider\'s model is written "general/nmt", "general/translation-llm" or "<model-id>"\'s path, not "%s".', $model));
        }
        $headers = ['Authorization' => 'Bearer '.$token];
        if (null !== $quota = $c->string('quota_project')) {
            $headers['x-goog-user-project'] = $quota;
        }
        $http = $this->http ?? HttpClient::create(['timeout' => 60]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }

        return new GoogleProvider(new JsonClient($http, 'Google Cloud Translation', $headers, $c->backoff(), [$token]), $base, (string) $c->string('project'), (string) $c->string('location'), $model);
    }
}
