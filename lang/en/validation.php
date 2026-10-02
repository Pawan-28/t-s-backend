<?php

/*
| Validation messages worded like Django REST Framework (the Next.js frontend shows the first message of a
| {field: [messages]} 400 body verbatim, e.g. the register form). Only keys listed here override the framework
| defaults; controllers that pass their own messages are unaffected.
*/

return [
    'required' => 'This field is required.',
    'filled' => 'This field may not be blank.',
    'email' => 'Enter a valid email address.',
    'string' => 'Not a valid string.',
    'integer' => 'A valid integer is required.',
    'numeric' => 'A valid number is required.',
    'boolean' => 'Must be a valid boolean.',
    'url' => 'Enter a valid URL.',
    'uuid' => 'Must be a valid UUID.',
    'array' => 'Expected a list of items.',
    'date' => 'Datetime has wrong format. Use one of these formats instead: YYYY-MM-DDThh:mm[:ss[.uuuuuu]][+HH:MM|-HH:MM|Z].',
    'unique' => 'This field must be unique.',
    'regex' => 'This value does not match the required pattern.',
    'in' => '":input" is not a valid choice.',
    'exists' => 'Invalid pk ":input" - object does not exist.',
    'image' => 'Upload a valid image. The file you uploaded was either not an image or a corrupted image.',
    'file' => 'The submitted data was not a file. Check the encoding type on the form.',
    'max' => [
        'string' => 'Ensure this field has no more than :max characters.',
        'numeric' => 'Ensure this value is less than or equal to :max.',
        'array' => 'Ensure this field has no more than :max elements.',
        'file' => 'The submitted file is too large (maximum :max kilobytes).',
    ],
    'min' => [
        'string' => 'Ensure this field has at least :min characters.',
        'numeric' => 'Ensure this value is greater than or equal to :min.',
        'array' => 'Ensure this field has at least :min elements.',
        'file' => 'The submitted file is too small (minimum :min kilobytes).',
    ],
];
