<?php

class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                match ($rule) {
                    'required' => $this->checkRequired($field, $value),
                    'email' => $value ? $this->checkEmail($field, $value) : null,
                    'min' => $value ? $this->checkMin($field, $value, (int) $params[0]) : null,
                    'max' => $value ? $this->checkMax($field, $value, (int) $params[0]) : null,
                    'numeric' => $value !== null && $value !== '' ? $this->checkNumeric($field, $value) : null,
                    'min_value' => $value !== null && $value !== '' ? $this->checkMinValue($field, $value, (float) $params[0]) : null,
                    'max_value' => $value !== null && $value !== '' ? $this->checkMaxValue($field, $value, (float) $params[0]) : null,
                    'in' => $value ? $this->checkIn($field, $value, $params) : null,
                    'date' => $value ? $this->checkDate($field, $value) : null,
                    default => null,
                };
            }
        }

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    private function checkRequired(string $field, $value): void
    {
        if ($value === null || $value === '') {
            $this->addError($field, "$field alani zorunludur");
        }
    }

    private function checkEmail(string $field, $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "Gecerli bir e-posta adresi giriniz");
        }
    }

    private function checkMin(string $field, $value, int $min): void
    {
        if (strlen((string) $value) < $min) {
            $this->addError($field, "$field en az $min karakter olmalidir");
        }
    }

    private function checkMax(string $field, $value, int $max): void
    {
        if (strlen((string) $value) > $max) {
            $this->addError($field, "$field en fazla $max karakter olmalidir");
        }
    }

    private function checkNumeric(string $field, $value): void
    {
        if (!is_numeric($value)) {
            $this->addError($field, "$field sayisal bir deger olmalidir");
        }
    }

    private function checkIn(string $field, $value, array $allowed): void
    {
        if (!in_array($value, $allowed)) {
            $this->addError($field, "$field gecerli bir deger olmalidir: " . implode(', ', $allowed));
        }
    }

    private function checkDate(string $field, $value): void
    {
        // ISO format (Y-m-d) ise checkdate() ile gerçek takvim doğrulaması yap
        // (strtotime("2025-02-31") → Mart 3 döndürür, yanlış geçer)
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $this->addError($field, "$field gecerli bir takvim tarihi degildir");
            }
        } elseif (!strtotime($value)) {
            $this->addError($field, "$field gecerli bir tarih olmalidir");
        }
    }

    private function checkMinValue(string $field, $value, float $min): void
    {
        if ((float) $value < $min) {
            $this->addError($field, "$field en az $min olmalidir");
        }
    }

    private function checkMaxValue(string $field, $value, float $max): void
    {
        if ((float) $value > $max) {
            $this->addError($field, "$field en fazla $max olmalidir");
        }
    }
}
