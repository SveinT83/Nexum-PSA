<?php

namespace App\Modules\Intake\Actions;

use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreIntakeAttachment
{
    public function handle(IntakeSubmission $submission, IntakeFormField $field, UploadedFile $file): IntakeSubmissionAttachment
    {
        $disk = 'local';
        $filename = $this->safeFilename($file->getClientOriginalName());
        $path = $this->path($submission->id, $filename);

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        return IntakeSubmissionAttachment::query()->create([
            'intake_submission_id' => $submission->id,
            'intake_form_field_id' => $field->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'content_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum_sha1' => sha1_file($file->getRealPath()),
            'metadata' => [
                'field_key' => $field->key,
                'field_label' => $field->label,
            ],
        ]);
    }

    private function path(int $submissionId, string $filename): string
    {
        return 'intake/submissions/'.$submissionId.'/'.Str::uuid().'-'.$filename;
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['/', '\\'], '-', $filename));

        return $filename !== '' ? $filename : 'attachment';
    }
}
