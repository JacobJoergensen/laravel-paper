<?php

declare(strict_types=1);

namespace JacobJoergensen\LaravelPaper\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

final class PaperUniqueRule implements ValidationRule
{
    private string|int|null $ignore = null;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
    ) {}

    public function ignore(string|int $value): self
    {
        $this->ignore = $value;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = $this->model::query();
        $query->where($this->column, $value);

        if ($this->ignore !== null) {
            $query->where('slug', '!=', $this->ignore);
        }

        if ($query->exists()) {
            $fail("The $attribute has already been taken.");
        }
    }
}
