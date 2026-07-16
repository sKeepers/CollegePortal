<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueAccessTokenRequest;
use App\Http\Resources\AccessPassTokenResource;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class AccessTokenController extends Controller
{
    public function issue(IssueAccessTokenRequest $request, AccessControlService $service): AccessPassTokenResource
    {
        return new AccessPassTokenResource($service->issueToken($request->user(), $request->validated(), $request));
    }

    public function refresh(IssueAccessTokenRequest $request, AccessControlService $service): AccessPassTokenResource
    {
        return new AccessPassTokenResource($service->issueToken($request->user(), $request->validated(), $request));
    }
}
