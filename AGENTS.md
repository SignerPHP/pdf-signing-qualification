# AGENTS.md

Operational map for humans and AI agents working in this repository. The method itself is defined in the [RFC](https://github.com/SignerPHP/.github/pull/3); read it instead of inferring the method from code.

## Environment

Everything runs in the project container (`Dockerfile`, base images pinned by digest). CI uses the same image. Tools needed by later work (validators, signer dependencies) are added to that `Dockerfile`, never installed only on a host or only in CI.

```sh
docker compose build
docker compose run --rm app composer install
```

Validators are pinned there: Debian packages by exact version, and pyHanko with its dependencies by version and hash in `docker/pyhanko-requirements.txt`. To change a validator version, update the pin, rebuild the image and keep the hash lock regenerated, never loosened.

On Linux, export `HOST_UID=$(id -u) HOST_GID=$(id -g)` when your IDs are not 1000, so files written in the mount keep your ownership.

## Commands

All commands run as `docker compose run --rm app composer <script>`.

| Script | Purpose |
| ------ | ------- |
| `acquire` | Acquire the inputs declared in `config/inputs.json` into `var/cache/inputs/`; exits 1 if any is unavailable, 2 if the acquisition was aborted |
| `tests` | PHPUnit |
| `pint:check` / `pint:fix` | Code style (Pint, Laravel preset) |
| `psalm` | Static analysis |
| `check` | Everything CI runs: `composer validate --strict`, style, static analysis, tests |

## Layout

| Path | Contents |
| ---- | -------- |
| `bin/` | Command-line entry points |
| `config/inputs.json` | Versioned source configuration: upstream sources, revisions and the inputs selected from them |
| `docker/` | Files copied into the image, such as the hashed pyHanko requirements |
| `src/` | Library code, namespace `SignerPHP\PdfSigningQualification\` |
| `src/Validation/` | Validator adapters; each one writes its own observation, see the schemas README |
| `schemas/` | Versioned JSON Schemas for input sources, acquisitions, scenarios, attempts, observations and measurements; see its README |
| `tests/` | PHPUnit tests, namespace `SignerPHP\PdfSigningQualification\Tests\` |
| `tests/Fixtures/Synthetic/` | Original synthetic fixtures, each listed in the directory README |
| `var/cache/inputs/` | Acquired upstream inputs; ignored, reconstructible |
| `var/runs/` | Run outputs; ignored |

## Workflow

1. Write the test that expresses the expected behavior and watch it fail for the expected reason.
2. Implement the smallest change that makes it pass.
3. Run `composer check` in the container.

## Boundaries

- Never commit upstream or user-reported PDFs, private keys or passwords. Follow the fixture policy in [CONTRIBUTING.md](CONTRIBUTING.md).
- Never replace an unavailable or hash-mismatched input silently. Record it as unavailable.
- Never weaken a verdict, a validator or a test to make a run or a check pass. A disagreement between validators is a result to report, not to resolve by vote.
- Never record an unavailable measurement as zero.
- No production dependency is added to `pdf-core` or `pdf-signer` for this repository's needs.
