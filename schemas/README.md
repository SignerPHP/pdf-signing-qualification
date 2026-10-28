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

- **Attempt.** `capability` (`supported`, `unsupported`, `expected_rejection`, `not_evaluated`) and `execution.outcome` (`succeeded`, `failed`, `timed_out`, `not_run`) are separate fields: an expected rejection is still a failed execution, and it is the capability that keeps it from being read as a defect. A failed or timed out execution must carry its error message.
- **Unavailable input.** A missing upstream file or a hash mismatch is recorded as `input.status: unavailable` with its reason. Such an attempt is `not_run` with capability `not_evaluated` and no output, so it can never be counted as an implementation failure.
- **Revision.** An implementation is identified by a full commit hash or a released version. Branch names are rejected.
- **Verdict.** `valid`, `invalid` and `inconclusive` are judgements about an output artifact and require one. `not_evaluated` means the analyzer did not judge it (for example, no output was produced); `not_applicable` means the property does not apply to the scenario. Each analyzer writes its own observation; there is no combined verdict.
- **Metric.** Either `{"status": "available", "value": ...}` or `{"status": "unavailable", "reason": ...}`. An unavailable metric has no value, so it cannot be mistaken for zero.
