---
title: Google Cloud Translation
order: 0
---

# omnilang/google

Read on 2026-11-10: docs.cloud.google.com/translate (editions, authentication, the v3 and v2 REST
references, quotas, attribution, data usage) and v3's discovery document
(`translate.googleapis.com/$discovery/rest?version=v3`: its root is
`https://translation.googleapis.com/`, its schemas TranslateTextResponse, DetectLanguageResponse,
SupportedLanguages, Glossary, ListGlossaryEntriesResponse, TranslateDocumentResponse).

**Why v3**: the editions page gives glossaries, document and batch translation to Advanced only;
Basic (v2) is plain NMT. v3 takes no API key ("The Cloud Translation - Advanced API (v3) does not
support API keys"): every request carries an OAuth 2.0 access token.

## Options

| Option | Default | |
|---|---|---|
| `project` | required | the project's id or number |
| `access_token` | required | by reference; scope `cloud-translation` or `cloud-platform` |
| `location` | `global` | glossaries, custom models and documents need a region (`us-central1`) |
| `model` | the general NMT model | `general/nmt`, `general/translation-llm`, a custom model's id |
| `quota_project` | none | sent as `x-goog-user-project` (user credentials) |
| `base_uri` | `https://translation.googleapis.com` | |
| `timeout` | 60 s | |

## Calls

| Method | Request | Read |
|---|---|---|
| `translate()` | `POST /v3/projects/{p}/locations/{l}:translateText`: `contents[]`, `mimeType` (`text/plain`, `text/html`), `sourceLanguageCode`, `targetLanguageCode`, `model`, `glossaryConfig.glossary` (a region and a source language required) | `translations[]` (`glossaryTranslations[]` with a glossary): `translatedText`, `detectedLanguageCode` |
| `languages()` | `GET …/supportedLanguages?displayLanguageCode=en&model` | `languages[]`: `languageCode`, `displayName`, `supportSource`, `supportTarget` |
| `detect()` | `POST …:detectLanguage`, one text a request: `content`, `mimeType` | `languages[]`: `languageCode`, `confidence` |
| `glossaries()` | `GET …/glossaries?pageSize=100&pageToken` | `glossaries[]`: `name` (its last segment is the id), `displayName`, `languagePair` or `languageCodesSet`, `entryCount`, `submitTime`; `nextPageToken` |
| `glossary()` | `GET …/glossaries/{id}` (404: null) | as above |
| `glossaryEntries()` | `GET …/glossaries/{id}/glossaryEntries?pageSize=1000&pageToken` | `termsPair.sourceTerm.text` / `targetTerm.text`, or `termsSet.terms[]` by language |
| `deleteGlossary()` | `DELETE …/glossaries/{id}` | a long-running operation, not followed |
| `translateDocument()` | `POST …:translateDocument`: `documentInputConfig` (`content` in base64, `mimeType`), the languages, `model`, `glossaryConfig` | `documentTranslation` (`glossaryDocumentTranslation`): `byteStreamOutputs[0]`, `mimeType`, `detectedLanguageCode` |

`createGlossary()`: `glossaries.create` requires `inputConfig.gcsSource.inputUri`, a file in
Cloud Storage; make the glossary there and use its id.

## Not verified

- The pricing page (only its title could be read): no free-tier figure.
- Formality: not offered in v3's request fields.
- The docs' reference pages write the host `translate.googleapis.com`, the discovery document
  `translation.googleapis.com`: the latter is the default; `base_uri` takes either.
