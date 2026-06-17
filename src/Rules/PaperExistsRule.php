<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class PaperExistsRule implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
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
        $query = $this->model::query();
        $exists = $query->where($this->column, $value)->exists();

        if (! $exists) {
            $fail('validation.exists')->translate();
        }
    }
}
