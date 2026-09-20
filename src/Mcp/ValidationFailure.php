<?php

namespace NovaMcp\Mcp;

use Illuminate\Validation\ValidationException;
use RuntimeException;

class ValidationFailure extends RuntimeException
{
    public function __construct(public array $fields)
    {
        parent::__construct('Validation failed.');
    }

    public static function from(ValidationException $exception, array $visible): self
    {
        $fields = [];
        $failed = $exception->validator->failed();
        foreach ($exception->errors() as $attribute => $messages) {
            if (! in_array($attribute, $visible, true)) {
                $fields['_'] = ['An unavailable field or resource requirement failed validation.'];

                continue;
            }
            $safe = [];
            foreach (array_keys($failed[$attribute] ?? []) as $rule) {
                $safe[] = match ($rule) {
                    'Required' => 'This field is required.',
                    'RequiredIf', 'RequiredUnless', 'RequiredWith', 'RequiredWithout', 'RequiredWithAll', 'RequiredWithoutAll' => 'This field is required for the current combination of values.',
                    'Max' => 'The value exceeds the allowed maximum.',
                    'Min' => 'The value is below the allowed minimum.',
                    'Between' => 'The value is outside the allowed range.',
                    'Email' => 'Enter a valid email address.',
                    'Url' => 'Enter a valid URL.',
                    'Uuid' => 'Enter a valid UUID.',
                    'Date', 'DateFormat' => 'Enter a valid date in the described format.',
                    'Unique' => 'This value is already in use.',
                    'In', 'Enum' => 'Choose one of the allowed values.',
                    'Boolean' => 'Enter a boolean value.',
                    'Integer' => 'Enter an integer.',
                    'Numeric' => 'Enter a numeric value.',
                    'Declined', 'DeclinedIf' => 'This value must be false for the current combination of values.',
                    'Accepted', 'AcceptedIf' => 'This value must be accepted.',
                    default => 'This value does not satisfy an application validation rule.',
                };
            }
            $fields[$attribute] = array_values(array_unique($safe ?: ['Invalid value. Check this field’s schema and application requirements.']));
        }

        return new self($fields ?: ['_' => ['Validation failed. Check the supplied arguments and resource requirements.']]);
    }
}
