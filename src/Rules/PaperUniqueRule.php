<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use JacobJoergensen\LaravelPaper\Contracts\PaperModel;

final class PaperUniqueRule implements ValidationRule
{
    private null|int|string $ignore = null;

    private ?string $ignoreColumn = null;

    /**
     * @param  class-string<PaperModel>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
    ) {}

    public function ignore(int|string $value, ?string $column = null): self
    {
        $this->ignore = $value;
        $this->ignoreColumn = $column;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value)) {
            return;
        }

        $query = $this->model::query();
        $query->where($this->column, $value);

        if ($this->ignore !== null) {
            $column = $this->ignoreColumn ?? (new $this->model)->getKeyName();
            $query->where($column, '!=', $this->ignore);
        }

        if ($query->exists()) {
            $fail('validation.unique')->translate();
        }
    }
}
