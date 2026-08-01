<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTypeResource;
use App\Models\DocumentType;

class DocumentTypeController extends Controller
{
    public function index()
    {
        return DocumentTypeResource::collection(DocumentType::query()->orderBy('name')->get());
    }

    public function show(DocumentType $documentType): DocumentTypeResource
    {
        return new DocumentTypeResource($documentType);
    }
}
