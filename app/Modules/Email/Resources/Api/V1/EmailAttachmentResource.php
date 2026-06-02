<?php

namespace App\Modules\Email\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'filename' => $this->filename,
            'content_type' => $this->content_type,
            'size_bytes' => $this->size_bytes,
            'is_inline' => $this->is_inline,
            'cid' => $this->cid,
            'checksum_sha1' => $this->checksum_sha1,
        ];
    }
}
