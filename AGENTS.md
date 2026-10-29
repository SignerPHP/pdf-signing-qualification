# AGENTS.md

Operational map for humans and AI agents working in this repository. The method itself is defined in the [RFC](https://github.com/SignerPHP/.github/pull/3); read it instead of inferring the method from code.

## Environment

Everything runs in the project container (`Dockerfile`, base images pinned by digest). CI uses the same image. Tools needed by later work (validators, signer dependencies) are added to that `Dockerfile`, never installed only on a host or only in CI.

```sh
docker compose build
docker compose run --rm app composer install
```

Validators are pinned there: Debian packages by exact version from a fixed `snapshot.debian.org` date, and pyHanko with its dependencies by version and hash in `docker/pyhanko-requirements.txt`. To change a validator version, update the snapshot date and the pin, rebuild the image and keep the hash lock regenerated, never loosened.

The pinned `pdf-signer` is installed at `/opt/implementations/pdf-signer` from an exact commit, with the dependencies locked by its own `composer.lock`. To change it, update the commit in the `Dockerfile` and the expected revision in the tests.

Two Compose services share the image. `app` has network access, for `composer install` and `acquire`. `sandbox` has no network interface other than loopback, a read-only root filesystem with 512 MiB `tmpfs` mounts at `/sandbox` and `/tmp`, 3 GiB of memory for the whole container, a process limit and no capabilities; attempts and the tests run there. The attempt runner refuses to start when another network interface is present or when its work directory is on a filesystem larger than the storage limit, so attempts work in `/sandbox`. Inside it each attempt runs in its own process group, with an empty environment, its own work directory, and `prlimit` bounds on address space per process, file size and open files; the whole group is killed at the timeout and as soon as the attempt's process ends. The `/app` mount stays writable.

On Linux, export `HOST_UID=$(id -u) HOST_GID=$(id -g)` when your IDs are not 1000, so files written in the mount keep your ownership.

## Commands

Commands run as `docker compose run --rm <service> composer <script>`, in `app` for `acquire` and in `sandbox` for the others.

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
| `config/scenarios/` | Versioned scenarios, one per file, following `scenario.schema.json` |
| `docker/` | Files copied into the image, such as the hashed pyHanko requirements |
| `src/` | Library code, namespace `SignerPHP\PdfSigningQualification\` |
| `src/Signing/` | Attempt runner and implementation adapters; an adapter only builds the command of an external process, see the schemas README |
| `src/Validation/` | Validator adapters; each one writes its own observation, see the schemas README |
| `schemas/` | Versioned JSON Schemas for input sources, acquisitions, scenarios, attempts, observations and measurements; see its README |
| `tests/` | PHPUnit tests, namespace `SignerPHP\PdfSigningQualification\Tests\` |
| `tests/Fixtures/Synthetic/` | Original synthetic fixtures, each listed in the directory README |
| `var/cache/inputs/` | Acquired upstream inputs; ignored, reconstructible |
| `var/runs/` | Run outputs; ignored |

## Workflow

1. Write the test that expresses the expected behavior and watch it fail for the expected reason.
2. Implement the smallest change that makes it pass.
3. Run `composer check` in the `sandbox` service.

## Boundaries

- Never commit upstream or user-reported PDFs, private keys or passwords. Follow the fixture policy in [CONTRIBUTING.md](CONTRIBUTING.md).
- Never replace an unavailable or hash-mismatched input silently. Record it as unavailable.
- Never weaken a verdict, a validator or a test to make a run or a check pass. A disagreement between validators is a result to report, not to resolve by vote.
- Never record an unavailable measurement as zero.
- No production dependency is added to `pdf-core` or `pdf-signer` for this repository's needs.
