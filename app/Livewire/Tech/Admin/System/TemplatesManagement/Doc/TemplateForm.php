<?php

namespace App\Livewire\Tech\Admin\System\TemplatesManagement\Doc;

use App\Models\Doc\DocumentationTemplate;
use App\Models\System\Category;
use Livewire\Component;

class TemplateForm extends Component
{
    public $templateId;
    public $name;
    public $category_id;
    public $is_active = true;
    public $fields = [];

    protected $rules = [
        'name' => 'required|string|max:255',
        'category_id' => 'required|exists:categories,id',
        'is_active' => 'boolean',
        'fields' => 'array',
        'fields.*.layout' => 'nullable|string',
        'fields.*.labelName' => 'required|string',
        'fields.*.Name' => 'nullable|required_without:fields.*.layout|string',
        'fields.*.type' => 'nullable|required_without:fields.*.layout|string',
    ];

    public function mount($templateId = null)
    {
        if ($templateId) {
            $template = DocumentationTemplate::findOrFail($templateId);
            $this->templateId = $template->id;
            $this->name = $template->name;
            $this->category_id = $template->category_id;
            $this->is_active = $template->is_active;
            $this->fields = $template->fields ?? [];
        } else {
            // Default field if new
            $this->fields = [
                ['layout' => 'rowStart', 'labelName' => 'New Section']
            ];
        }
    }

    public function addRow()
    {
        $this->fields[] = ['layout' => 'rowStart', 'labelName' => 'New Section'];
    }

    public function addField($index)
    {
        // Insert a field after the row/field at $index
        $newField = ['Name' => '', 'labelName' => 'New Field', 'type' => 'text'];
        array_splice($this->fields, $index + 1, 0, [$newField]);
    }

    public function removeField($index)
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function moveUp($index)
    {
        if ($index > 0) {
            $prev = $this->fields[$index - 1];
            $this->fields[$index - 1] = $this->fields[$index];
            $this->fields[$index] = $prev;
        }
    }

    public function moveDown($index)
    {
        if ($index < count($this->fields) - 1) {
            $next = $this->fields[$index + 1];
            $this->fields[$index + 1] = $this->fields[$index];
            $this->fields[$index] = $next;
        }
    }

    public function save()
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'category_id' => $this->category_id,
            'is_active' => $this->is_active,
            'fields' => $this->fields,
        ];

        if ($this->templateId) {
            DocumentationTemplate::find($this->templateId)->update($data);
            session()->flash('success', 'Template updated successfully.');
        } else {
            DocumentationTemplate::create($data);
            session()->flash('success', 'Template created successfully.');
            return redirect()->route('admin.system.templatesManagement.doc.index');
        }
    }

    public function render()
    {
        return view('livewire.tech.admin.system.templates-management.doc.template-form', [
            'categories' => Category::all()
        ]);
    }
}
