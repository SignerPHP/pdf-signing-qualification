# Contributing

All work runs inside the project container; see [AGENTS.md](AGENTS.md) for the commands. Commits must carry a `Signed-off-by` trailer (`git commit -s`).

## Fixture policy

This repository versions acquisition code, configuration, schemas and analysis logic. It does not version PDF corpora.

- **PDFs reported by users are never committed**, not even redacted or truncated. They may contain personal data and their distribution rights are unknown.
- **Upstream inputs have an established free license.** An input is selected only when evidence establishes a free license for the document itself, not only for the repository that holds it. Its license and that evidence are recorded in [`config/inputs.json`](config/inputs.json); an input whose license is unresolved (`NOASSERTION`) is not selected.
- **Upstream PDFs are never mirrored.** An upstream input is referenced by URL, immutable revision and SHA-256, acquired into the local input cache (`var/cache/inputs/`) and verified before use. The cache is ignored by Git and can be rebuilt.
- **A regression case uses one of two forms:**
  - a minimal synthetic reproduction that triggers the same behavior without carrying any content from the original document; or
  - an upstream reference (URL, revision, SHA-256) to a public file that reproduces it.
- **Synthetic fixtures have documented ownership.** They live in [`tests/Fixtures/Synthetic/`](tests/Fixtures/Synthetic/) and each one is listed in that directory's README with its owner, how it was produced and its purpose.
- **Private keys and passwords are never committed.** Signing credentials for tests are generated at run time.

Reviewers check the origin of every committed fixture against this policy.

## Pull requests

- Link the issue the change resolves.
- Add or update a test that fails without the change.
- Run `composer check` in the container before pushing; CI runs the same checks.
