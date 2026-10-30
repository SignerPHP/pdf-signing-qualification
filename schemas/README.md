# Result schemas

Machine-readable contracts written and read by every stage, as JSON Schema draft 2020-12 (RFC sections 6.3, 6.4, 6.6 and 7).

| Schema | Describes |
| ------ | --------- |
| [`v1/sources.schema.json`](v1/sources.schema.json) | Upstream sources pinned at a revision and the inputs selected from them |
| [`v1/acquisition.schema.json`](v1/acquisition.schema.json) | The outcome of acquiring one declared input: its provenance and licenses, or why it is unavailable |
| [`v1/scenario.schema.json`](v1/scenario.schema.json) | An explicit operation configuration, including digest and signature algorithms |
| [`v1/attempt.schema.json`](v1/attempt.schema.json) | One execution of a scenario by one implementation on one input |
| [`v1/observation.schema.json`](v1/observation.schema.json) | One analyzer's verdict on one property of an attempt's output |
| [`v1/measurement.schema.json`](v1/measurement.schema.json) | Resource usage of one attempt within a declared boundary |
| [`v1/common.schema.json`](v1/common.schema.json) | Shared definitions: hashes, artifacts, revisions, verdicts, metrics |

## Versioning

Every document carries a `schema` field equal to the `$id` of the schema it follows, so readers never guess the version. A change that makes a previously valid document invalid, or changes the meaning of a field, goes into a new directory (`v2/`) and leaves the previous version in place.

## Semantics

- **Sources.** A source is an upstream project at an immutable revision, with a `file_url` template in which `{revision}` and `{path}` are replaced to download one file. An input names its source, its path inside it, the SHA-256 and byte size it must have, and the document's own license. Its identity is `<source>:<path>`, the `source_id` used by attempts.
- **License.** An SPDX expression, or `NOASSERTION` when the evidence does not establish one, always with the URL of that evidence. The source project's license and the document's license are recorded separately, because a repository's license does not establish the license of each file in it.
- **Acquisition.** An available input records its retrieval time in UTC, its SHA-256 and byte size, and both licenses. A missing upstream file (HTTP 404 or 410) or a file whose hash or size differs from the declaration is recorded as `unavailable`, with the observed hash and size for a mismatch, and nothing is kept in the cache under its identity. Any other failure, such as an unreachable host, a server error, a timeout or a body shorter than its `Content-Length`, stops the acquisition instead of being recorded as missing.
- **Attempt.** `capability` (`supported`, `unsupported`, `expected_rejection`, `not_evaluated`) is what was expected of the implementation for this scenario and input; `execution.outcome` (`succeeded`, `failed`, `timed_out`, `not_run`) is what happened. They are recorded independently: a rejection that was expected is a failed execution that the capability keeps from being read as a defect, and an expected rejection that succeeded is kept as an observed result, not rewritten.
- **Execution.** A succeeded execution has exit code 0, no error and an output. A failed or timed out execution carries its error message and both timestamps. A `not_run` attempt has no exit code, timestamps, error or output, and is only allowed when the input is unavailable or the capability is `unsupported`; an attempt that started is never recorded as `not_run`.
- **Unavailable input.** A missing upstream file or a hash mismatch is recorded as `input.status: unavailable` with its reason; a mismatch also records the observed hash. Such an attempt is `not_run` with capability `not_evaluated` and no output, so it can never be counted as an implementation failure. `not_evaluated` is used for no other case.
- **Revision.** Implementations and analyzers are identified by a full commit hash or a released version. Branch names and moving labels such as `latest` are rejected.
- **Identifier.** Identifiers and input paths may be split into `/` segments; each segment starts with a letter or digit and does not end with a space, so `.`, `..`, empty and padded segments are rejected. A space inside a segment is kept, because upstream paths such as veraPDF's contain them.
- **Verdict.** `valid`, `invalid` and `inconclusive` are judgements about an output artifact and require both the input and the output artifact. `not_evaluated` means the analyzer did not judge it (for example, the input was unavailable or no output was produced), and the missing artifact is recorded as `null`; `not_applicable` means the property does not apply to the scenario. Each analyzer writes its own observation; there is no combined verdict.
- **Validators.** Each adapter runs one pinned tool on the output and writes one observation for one property, with the version the tool reports, its policy, exit code and raw output. A timeout, a crash or an output the adapter cannot read is `inconclusive`, never `valid`. The initial adapters are:

  | Analyzer | Property | Policy |
  | -------- | -------- | ------ |
  | `pyhanko` | `cms_signature` | Integrity and cryptographic validity of every embedded signature, with an empty trust store and no revocation lookup; an untrusted certificate does not change the verdict |
  | `pdfsig` | `cms_signature` | Poppler's validation of every signature over its signed ranges, with `-nocert`; the verdict comes from the reported state, because `pdfsig` exits with 0 on a digest mismatch |
  | `openssl-cms` | `cms_signature` | Every dictionary with a `/ByteRange`, direct or indirect and including those in object streams, is listed from qpdf's JSON output. The gap of each range must be exactly a hexadecimal string (PDF whitespace allowed inside); its bytes must decode as CMS (DER or BER) and are verified with `openssl cms -verify -noverify` over the bytes the range covers. A gap that is not a hexadecimal string, bytes that do not decode as CMS, and exit code 4 are `invalid` |
  | `qpdf` | `structure` | `qpdf --check`; exit 0 is `valid`, 2 is `invalid`, 3 (warnings only) is `inconclusive` |

  An output without any signature is `invalid` for the `cms_signature` adapters.
- **Implementations.** An adapter declares which scenarios it supports and builds the command line of an external process that reads the input and writes the output; the runner knows nothing else about it, so an implementation in any language is added without changing the runner. The runner copies the input into a fresh work directory and refuses it if its hash or size differs from the acquisition record, then runs the command with the limits described in `AGENTS.md`. A timeout is `timed_out`; a non-zero exit, a termination by signal, an exit 0 without an output file or an output that is not a regular file (such as a symbolic link) is `failed`, with the last 4 KiB of the process output in the error message and the credential password redacted from it. The output is copied and kept, under its SHA-256, only when the execution succeeded and the work directory was removed. Each run signs with a test certificate and RSA key generated for it, stored as a PKCS#12 outside the results and removed afterwards. The initial adapter is:

  | Implementation | Revision | Supported scenarios | Command |
  | -------------- | -------- | ------------------- | ------- |
  | `pdf-signer` | Commit read from the installation | SHA-256, RSA PKCS#1 v1.5, `adbe.pkcs7.detached`, invisible, without timestamp or revocation information | `bin/signer-sign` with `--no-default-appearance` and `--no-default-timestamp`, and PHP's `memory_limit` disabled so that the runner's limit applies |
- **Metric.** Either `{"status": "available", "value": ...}` or `{"status": "unavailable", "reason": ...}`. An unavailable metric has no value, so it cannot be mistaken for zero.
