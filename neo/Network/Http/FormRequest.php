<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Neo\Information\Validator\Validator;
use Neo\Network\Http\Exceptions\HttpException;

/**
 * Запрос с собственными правилами проверки (как Illuminate\Foundation\Http\FormRequest).
 *
 *   class StorePostRequest extends FormRequest
 *   {
 *       public function rules(): array { return ['title' => 'required|string|max:255']; }
 *   }
 *
 *   public function store(StorePostRequest $request) { $data = $request->validated(); }
 *
 * Роутер сам создаёт такой запрос из текущего и вызывает {@see validateResolved()} ДО тела
 * контроллера: при ошибке бросается ValidationException (422 JSON или редирект назад).
 */
abstract class FormRequest extends Request
{
    /** @var array<string, mixed>|null */
    protected ?array $validatedData = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string|list<mixed>> */
    abstract public function rules(): array;

    /** @return array<string, string> */
    public function messages(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [];
    }

    protected function prepareForValidation(): void {}

    protected function passedValidation(): void {}

    /**
     * @throws HttpException 403, если authorize() вернул false
     * @throws \Neo\Information\Validator\ValidationException
     */
    public function validateResolved(): void
    {
        $this->prepareForValidation();

        if (!$this->authorize()) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        $this->validatedData = Validator::make(
            $this->all(),
            $this->rules(),
            $this->messages(),
            $this->attributes(),
        )->validate();

        $this->passedValidation();
    }

    /**
     * Только проверенные данные (поля без правил отбрасываются).
     *
     * @return array<string, mixed>|mixed
     */
    public function validated(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->validatedData ?? [];

        return $key === null ? $data : (\Neo\Information\Structures\Arr::get($data, $key, $default));
    }
}
