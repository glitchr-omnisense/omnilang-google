# Changelog

## 1.x (unreleased)

- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts, over Cloud
  Translation - Advanced (v3): translateText (plain, HTML, model, glossary), detectLanguage,
  supportedLanguages, glossaries (list with pages, get, entries with pages, delete; create not
  supported: Cloud Storage only), translateDocument (PDF and Office, in a region). The OAuth access
  token by reference, as a Bearer header. policy.json: the default quotas, the required
  attribution, data use. Tests on MockHttp over answers written from v3's discovery document, each
  asserting the exact request; PolicyTest, BareTest; PHPStan at max, baseline empty.
