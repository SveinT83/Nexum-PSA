<?php

namespace App\Modules\Intake\Models;

use App\Modules\Intake\Support\IntakeFormFieldInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeFormField extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_URL = 'url';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_CONSENT = 'consent';
    public const TYPE_FILE = 'file';

    public const VISIBILITY_MODE_ALWAYS = 'always';
    public const VISIBILITY_MODE_CONDITIONAL = 'conditional';

    public const VISIBILITY_MATCH_ALL = 'all';
    public const VISIBILITY_MATCH_ANY = 'any';

    public const VISIBILITY_OPERATOR_HAS_VALUE = 'has_value';
    public const VISIBILITY_OPERATOR_EQUALS = 'equals';
    public const VISIBILITY_OPERATOR_NOT_EQUALS = 'not_equals';
    public const VISIBILITY_OPERATOR_CONTAINS = 'contains';
    public const VISIBILITY_OPERATOR_CHECKED = 'checked';
    public const VISIBILITY_OPERATOR_UNCHECKED = 'unchecked';

    public const FIELD_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_EMAIL,
        self::TYPE_PHONE,
        self::TYPE_URL,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_CONSENT,
        self::TYPE_FILE,
    ];

    public const VISIBILITY_MODES = [
        self::VISIBILITY_MODE_ALWAYS,
        self::VISIBILITY_MODE_CONDITIONAL,
    ];

    public const VISIBILITY_MATCH_MODES = [
        self::VISIBILITY_MATCH_ALL,
        self::VISIBILITY_MATCH_ANY,
    ];

    public const VISIBILITY_OPERATORS = [
        self::VISIBILITY_OPERATOR_HAS_VALUE,
        self::VISIBILITY_OPERATOR_EQUALS,
        self::VISIBILITY_OPERATOR_NOT_EQUALS,
        self::VISIBILITY_OPERATOR_CONTAINS,
        self::VISIBILITY_OPERATOR_CHECKED,
        self::VISIBILITY_OPERATOR_UNCHECKED,
    ];

    public const MAP_COMPANY_NAME = 'company_name';
    public const MAP_CONTACT_NAME = 'contact_name';
    public const MAP_CONTACT_EMAIL = 'contact_email';
    public const MAP_CONTACT_PHONE = 'contact_phone';
    public const MAP_SUBJECT = 'subject';
    public const MAP_MESSAGE = 'message';
    public const MAP_ORG_NO = 'org_no';
    public const MAP_WEBSITE = 'website';
    public const MAP_CONSENT = 'consent';

    public const MAP_TARGETS = [
        self::MAP_COMPANY_NAME,
        self::MAP_CONTACT_NAME,
        self::MAP_CONTACT_EMAIL,
        self::MAP_CONTACT_PHONE,
        self::MAP_SUBJECT,
        self::MAP_MESSAGE,
        self::MAP_ORG_NO,
        self::MAP_WEBSITE,
        self::MAP_CONSENT,
    ];

    protected $fillable = [
        'intake_form_id',
        'key',
        'label',
        'field_type',
        'maps_to',
        'help_text',
        'placeholder',
        'options',
        'is_required',
        'is_active',
        'sort_order',
        'max_files',
        'max_file_size_kb',
        'allowed_mime_types',
        'metadata',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'max_files' => 'integer',
        'max_file_size_kb' => 'integer',
        'allowed_mime_types' => 'array',
        'metadata' => 'array',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(IntakeForm::class, 'intake_form_id');
    }

    public function isFileField(): bool
    {
        return $this->field_type === self::TYPE_FILE;
    }

    public function allowedMimeTypes(): array
    {
        return $this->allowed_mime_types ?: ($this->form?->allowedMimeTypes() ?? IntakeForm::DEFAULT_ALLOWED_MIME_TYPES);
    }

    public function maxFiles(): int
    {
        return (int) ($this->max_files ?: ($this->form?->max_files ?: 5));
    }

    public function maxFileSizeKb(): int
    {
        return (int) ($this->max_file_size_kb ?: ($this->form?->max_file_size_kb ?: 20480));
    }

    public function layoutWidth(): int
    {
        $width = (int) data_get($this->metadata, 'layout.width', 12);

        return in_array($width, IntakeFormFieldInput::LAYOUT_WIDTHS, true) ? $width : 12;
    }

    public function layoutColumnClass(): string
    {
        return 'col-12 col-md-'.$this->layoutWidth();
    }

    public function visibility(): array
    {
        $visibility = data_get($this->metadata, 'visibility', []);
        $mode = (string) ($visibility['mode'] ?? self::VISIBILITY_MODE_ALWAYS);

        if ($mode !== self::VISIBILITY_MODE_CONDITIONAL) {
            return [
                'mode' => self::VISIBILITY_MODE_ALWAYS,
                'match' => self::VISIBILITY_MATCH_ALL,
                'rules' => [],
            ];
        }

        $match = (string) ($visibility['match'] ?? self::VISIBILITY_MATCH_ALL);

        if (! in_array($match, self::VISIBILITY_MATCH_MODES, true)) {
            $match = self::VISIBILITY_MATCH_ALL;
        }

        $rules = collect($visibility['rules'] ?? [])
            ->filter(fn ($rule) => is_array($rule))
            ->map(function (array $rule): array {
                $operator = (string) ($rule['operator'] ?? self::VISIBILITY_OPERATOR_HAS_VALUE);

                if (! in_array($operator, self::VISIBILITY_OPERATORS, true)) {
                    $operator = self::VISIBILITY_OPERATOR_HAS_VALUE;
                }

                return [
                    'source_key' => trim((string) ($rule['source_key'] ?? '')),
                    'operator' => $operator,
                    'value' => trim((string) ($rule['value'] ?? '')),
                ];
            })
            ->filter(fn (array $rule): bool => $rule['source_key'] !== '')
            ->values()
            ->all();

        if ($rules === []) {
            return [
                'mode' => self::VISIBILITY_MODE_ALWAYS,
                'match' => self::VISIBILITY_MATCH_ALL,
                'rules' => [],
            ];
        }

        return [
            'mode' => self::VISIBILITY_MODE_CONDITIONAL,
            'match' => $match,
            'rules' => $rules,
        ];
    }

    public function hasConditionalVisibility(): bool
    {
        return $this->visibility()['mode'] === self::VISIBILITY_MODE_CONDITIONAL;
    }
}
