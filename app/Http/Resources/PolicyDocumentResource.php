<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'            => $this->uuid,
            'title'           => $this->title,
            'description'     => $this->description,
            'category'        => $this->category,
            'file_name'       => $this->file_name,
            'file_size'       => $this->file_size,
            'mime_type'       => $this->mime_type,
            'is_downloadable' => $this->is_downloadable,
            'scope_type'      => $this->scope_type,
            'scope_ids'       => $this->scope_ids,
            'is_active'       => $this->is_active,
            'uploaded_by'     => $this->whenLoaded('uploader', fn () => [
                'uuid' => $this->uploader->uuid,
                'name' => $this->uploader->name,
            ]),
            'created_at'      => $this->created_at,
        ];
    }
}
