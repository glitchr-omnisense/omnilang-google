# Changelog

## 1.x (unreleased)

- 2026-11-10: policy.json declares the general model's 6,000,000 characters a minute per project
  (`"unit": "characters"`, translate and detect: detection counts toward it, docs.cloud.google.com
  read again on 2026-11-10), counted by omnisense/omnilang's guard from each request's characters.
  Needs omnisense/contracts 5bac28b (the `unit` field; still `^1.0@dev`) and omnisense/omnilang
  with it. PolicyTest: the declared limits with their units, a site's character limit counted.
- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts, over Cloud
  Translation - Advanced (v3): translateText (plain, HTML, model, glossary), detectLanguage,
  supportedLanguages, glossaries (list with pages, get, entries with pages, delete; create not
  supported: Cloud Storage only), translateDocument (PDF and Office, in a region). The OAuth access
  token by reference, as a Bearer header. policy.json: the default quotas, the required
  attribution, data use. Tests on MockHttp over answers written from v3's discovery document, each
  asserting the exact request; PolicyTest, BareTest; PHPStan at max, baseline empty.
