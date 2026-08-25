<?php

namespace App\Modules\Email\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailComposerDraftAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'filename' => $this->filename,
            'content_type' => $this->content_type,
            'size_bytes' => (int) $this->size_bytes,
            'position' => (int) $this->position,
        ];
    }
}
