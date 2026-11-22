<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'messages' => ['required', 'array', 'min:1'],
            'runId' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->userPrompt() === '') {
                $validator->errors()->add('messages', 'The last user message must contain non-empty text.');
            }
        });
    }

    public function conversationId(): ?string
    {
        $id = $this->input('id');

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    public function userPrompt(): string
    {
        $messages = $this->input('messages');
        if (! is_array($messages)) {
            return '';
        }

        $lastUserMessage = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (is_array($msg) && ($msg['role'] ?? '') === 'user') {
                $lastUserMessage = $msg;
                break;
            }
        }

        if ($lastUserMessage === null) {
            return '';
        }

        if (isset($lastUserMessage['content']) && is_string($lastUserMessage['content'])) {
            return trim($lastUserMessage['content']);
        }

        if (isset($lastUserMessage['parts']) && is_array($lastUserMessage['parts'])) {
            $texts = [];
            foreach ($lastUserMessage['parts'] as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text']) && is_string($part['text'])) {
                    $texts[] = $part['text'];
                }
            }

            return trim(implode("\n", $texts));
        }

        if (isset($lastUserMessage['text']) && is_string($lastUserMessage['text'])) {
            return trim($lastUserMessage['text']);
        }

        return '';
    }
}
