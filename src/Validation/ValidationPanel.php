<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use Closure;
use DateTimeImmutable;

final class ValidationPanel
{
    /**
     * @param  list<Validator>  $validators
     */
    public function __construct(
        private readonly array $validators,
    ) {}

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public static function installed(Closure $clock, array $scenario): self
    {
        $validators = [
            new PyHankoValidator(new ProcessRunner, $clock),
            new PdfsigValidator(new ProcessRunner, $clock),
            new OpenSslCmsValidator(new ProcessRunner, $clock),
            new QpdfCheckValidator(new ProcessRunner, $clock),
            new InputPreservationValidator(new ProcessRunner, $clock),
            new PriorSignaturesValidator(new ProcessRunner, $clock),
            new SignatureCoverageValidator(new ProcessRunner, $clock),
        ];
        $placement = $scenario['signing']['placement'] ?? null;
        if (is_array($placement)) {
            $validators[] = new VisibleAppearanceValidator(new ProcessRunner, $clock, $placement);
        }

        return new self($validators);
    }

    /**
     * @return list<array{id: string, revision: array{kind: 'version', value: string}}>
     */
    public function analyzers(): array
    {
        return array_map(static fn (Validator $validator): array => $validator->analyzer(), $this->validators);
    }

    /**
     * @return list<array>
     */
    public function observe(ValidationSubject $subject): array
    {
        return array_map(static fn (Validator $validator): array => $validator->observe($subject), $this->validators);
    }

    /**
     * @return list<array>
     */
    public function baseline(BaselineSubject $subject): array
    {
        $baselineValidators = array_filter($this->validators, static fn (Validator $validator): bool => $validator instanceof BaselineValidator);

        return array_values(array_map(static fn (BaselineValidator $validator): array => $validator->baseline($subject), $baselineValidators));
    }
}
