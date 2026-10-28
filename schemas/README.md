# Result schemas

Machine-readable contracts written and read by every stage, as JSON Schema draft 2020-12 (RFC sections 6.4, 6.6 and 7).

| Schema | Describes |
| ------ | --------- |
| [`v1/scenario.schema.json`](v1/scenario.schema.json) | An explicit operation configuration, including digest and signature algorithms |
| [`v1/attempt.schema.json`](v1/attempt.schema.json) | One execution of a scenario by one implementation on one input |
| [`v1/observation.schema.json`](v1/observation.schema.json) | One analyzer's verdict on one property of an attempt's output |
| [`v1/measurement.schema.json`](v1/measurement.schema.json) | Resource usage of one attempt within a declared boundary |
| [`v1/common.schema.json`](v1/common.schema.json) | Shared definitions: hashes, artifacts, revisions, verdicts, metrics |

## Versioning

Every document carries a `schema` field equal to the `$id` of the schema it follows, so readers never guess the version. A change that makes a previously valid document invalid, or changes the meaning of a field, goes into a new directory (`v2/`) and leaves the previous version in place.

## Semantics

- **Attempt.** `capability` (`supported`, `unsupported`, `expected_rejection`, `not_evaluated`) is what was expected of the implementation for this scenario and input; `execution.outcome` (`succeeded`, `failed`, `timed_out`, `not_run`) is what happened. They are recorded independently: a rejection that was expected is a failed execution that the capability keeps from being read as a defect, and an expected rejection that succeeded is kept as an observed result, not rewritten.
- **Execution.** A succeeded execution has exit code 0, no error and an output. A failed or timed out execution carries its error message and both timestamps. A `not_run` attempt has no exit code, timestamps, error or output, and is only allowed when the input is unavailable or the capability is `unsupported`; an attempt that started is never recorded as `not_run`.
- **Unavailable input.** A missing upstream file or a hash mismatch is recorded as `input.status: unavailable` with its reason; a mismatch also records the observed hash. Such an attempt is `not_run` with capability `not_evaluated` and no output, so it can never be counted as an implementation failure. `not_evaluated` is used for no other case.
- **Revision.** Implementations and analyzers are identified by a full commit hash or a released version. Branch names and moving labels such as `latest` are rejected.
- **Identifier.** Identifiers may be split into `/` segments; each segment starts with a letter or digit, so `.`, `..` and empty segments are rejected.
- **Verdict.** `valid`, `invalid` and `inconclusive` are judgements about an output artifact and require both the input and the output artifact. `not_evaluated` means the analyzer did not judge it (for example, the input was unavailable or no output was produced), and the missing artifact is recorded as `null`; `not_applicable` means the property does not apply to the scenario. Each analyzer writes its own observation; there is no combined verdict.
- **Metric.** Either `{"status": "available", "value": ...}` or `{"status": "unavailable", "reason": ...}`. An unavailable metric has no value, so it cannot be mistaken for zero.
