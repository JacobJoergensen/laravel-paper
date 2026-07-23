<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;

final readonly class PaperExistsRule implements ValidationRule
{
    /**
     * @param  class-string<PaperModel>  $model
     */
    public function __construct(
        private string $model,
        private string $column,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = is_scalar($value)
            && $this->model::query()->where($this->column, $value)->exists();

        if (! $exists) {
            $fail('validation.exists')->translate();
        }
    }
}
