# Security policy

This policy covers this repository: the acquisition, the attempt runner and its isolation, the adapters, the analysis and the published results. It also says how a vulnerability that a run finds in an implementation or a validator is handled. The other SignerPHP repositories are outside it.

## Supported versions

Only the default branch is supported. The `results-*` releases carry result bundles, not code, and a fix is never backported to them.

## Reporting a vulnerability in this repository

Report it privately through GitHub's private vulnerability reporting: open the repository's **Security** tab and choose **Report a vulnerability**, or go to <https://github.com/SignerPHP/pdf-signing-qualification/security/advisories/new>. Do not open a public issue, pull request or discussion about it, and do not mention it in a commit.

Examples of what to report this way:

- an attempt or a validator that reads or writes outside the boundary [AGENTS.md](AGENTS.md) describes, or reaches the network from the `sandbox` service;
- an input accepted although its SHA-256 does not match its declaration;
- a private key, a password or data of the host written to a run record or to a published bundle;
- a published report or bundle that contains something the [publication contract](docs/results-publication.md#what-is-never-published) excludes.

Include:

- the revision of this repository, the command and the scenario;
- the component involved and what you observed against what you expected;
- the steps that reproduce it, and the records of the run that show it, without any PDF.

## Sensitive PDFs

Never attach a PDF reported by a user, or any PDF whose content or distribution rights are not public, to a report, an advisory or an issue. Reproduce the problem instead with one of the forms the [fixture policy](CONTRIBUTING.md#fixture-policy) accepts:

- a minimal synthetic PDF that triggers the same behavior without carrying any content of the original; or
- an upstream reference to a public file: URL, revision and SHA-256.

When neither is possible yet, send the SHA-256 of the file, its size, the generator it names and the diagnostics with any personal data removed, and say that only that file reproduces it. The maintainers then agree with you, in the private advisory, how to proceed. Never send a private key or a password, not even a test one; signing credentials are generated at run time.

## Vulnerabilities found in an implementation or a validator

A run can find an exploitable problem in a signing implementation, such as `pdf-signer` or JSignPdf, or in a validator, such as Poppler, qpdf or pyHanko: a crash, a hang or memory exhaustion on a crafted input, or a signature reported as valid when it is not.

- Report it to that project through its own security policy or private channel. When it publishes none, ask its maintainers privately for one; a public issue is never the channel.
- Do not open a public issue about it here or upstream, and do not describe it in a pull request, a document of `docs/` or a commit.
- Do not publish a report or a result bundle that would disclose it until the upstream project has released a fix or agreed to the disclosure. The run records stay in the local `var/`, which Git ignores.
- When the finding belongs to the qualification itself, such as a regression case or an expected rejection, it is added after the disclosure, under the [fixture policy](CONTRIBUTING.md#fixture-policy).

When you are unsure whether a finding is exploitable, or which project it belongs to, report it here privately and the maintainers forward it, with your agreement.
