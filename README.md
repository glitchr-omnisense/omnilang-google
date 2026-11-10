# omnilang/google

[omnisense/omnilang](https://github.com/glitchr-omnisense/omnilang)'s Google Cloud Translation,
under any PHP host: **Cloud Translation - Advanced (v3)**, the edition with glossaries and document
translation (v2, Basic, has neither).

- **translate** (`:translateText`): up to 1,024 texts and about 30,000 code points a request,
  plain or HTML, the source detected (`detectedLanguageCode`), a model, a glossary; the supported
  languages;
- **detect** (`:detectLanguage`): a language, its confidence, the alternatives;
- **glossaries**: listed, read (their entries), applied, deleted. Google builds a glossary from a
  TSV, CSV or TMX file in Cloud Storage, so `createGlossary()` is not supported;
- **documents** (`:translateDocument`, in a region): PDF, DOCX, PPTX, XLSX up to 20 MB, at once.

```php
use Omnilang\Google\GoogleFactory;
use Omnilang\Model\TranslationQuery;

$google = (new GoogleFactory($httpClient))->create([
    'project' => 'my-project',
    'access_token' => 'env:GOOGLE_TRANSLATE_TOKEN',   // an OAuth 2.0 token: v3 takes no API key
    'location' => 'us-central1',                      // "global" by default; a region for glossaries and documents
]);
echo $google->translate(TranslationQuery::html('<p>Réservez maintenant</p>', 'ja'))[0]->text;
```

Options: `project`, `access_token` (required; by reference - the application gets the token from
its service account or Application Default Credentials and renews it, about hourly), `location`,
`model` (`general/nmt`, `general/translation-llm`, a custom model's id), `quota_project`
(`x-goog-user-project`), `base_uri` (`https://translation.googleapis.com`), `timeout`; `policy`,
`usage`.

**Policy** ([policy.json](policy.json), read on 2026-11-10): 6,000 v3 requests a minute per project
and 600 for the supported languages (default quotas), 6,000,000 characters a minute, a quota
exceeded answering 403; **attribution required** - the "powered by Google Translate" graphic beside
every translation, linked to translate.google.com; content held in memory only, never used to
train Google's translation. The pricing page could not be read: no free-tier figure is declared.

See [docs/index.md](docs/index.md). MIT licence.
