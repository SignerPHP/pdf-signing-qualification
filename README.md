# pdf-signing-qualification

Reproducible, implementation-neutral qualification and benchmarking of PDF signing solutions. [WIP]

## Purpose

Unit and golden tests inside a signing library show that it behaves as its authors expect. They do not show that its output is accepted by independent tools, across PDFs from many generators, or how it performs on a known population of documents. This repository provides that evidence:

- **Traceable inputs.** Public PDFs are acquired from their upstream projects by URL, revision and SHA-256. They are never copied into this repository.
- **Independent verdicts.** Signed outputs are judged by independent validators, not by the implementation's own success result.
- **Qualification before performance.** Timing is only compared over inputs that every compared implementation signed and that were independently validated.

The method is described in the RFC [Reproducible PDF signing qualification and benchmarking](https://github.com/SignerPHP/.github/pull/3).

## Any signing solution can be qualified

Implementations are integrated through adapters. An adapter runs one signing solution, in any language or as an external process, against the same inputs and scenarios, and the same validators judge every output. The first implementation under test is [`signerphp/pdf-signer`](https://github.com/SignerPHP/pdf-signer); other solutions are qualified by adding an adapter, without changes to the inputs or validators.

## Getting started

Requirements: Docker with Compose.

```sh
docker compose build
docker compose run --rm app composer install
docker compose run --rm sandbox composer check
```

See [AGENTS.md](AGENTS.md) for the repository layout and workflow, and [CONTRIBUTING.md](CONTRIBUTING.md) for the fixture policy.

## License

[MIT](LICENSE)
