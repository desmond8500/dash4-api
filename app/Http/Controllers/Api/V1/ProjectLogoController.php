<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClientLogoRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

/** Logo d'un projet (mêmes règles que le logo d'un client : JPEG, PNG, WebP, 2 Mo). */
class ProjectLogoController extends Controller
{
    public function store(ClientLogoRequest $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $old = $project->logo_path;
        $path = $request->file('logo')->store('projects/logos', 'public');
        $project->forceFill(['logo_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return new ProjectResource($project->refresh()->load(['client:id,name', 'manager:id,firstname,lastname']));
    }

    public function destroy(Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        if ($project->logo_path) {
            Storage::disk('public')->delete($project->logo_path);
            $project->forceFill(['logo_path' => null])->save();
        }

        return new ProjectResource($project->refresh()->load(['client:id,name', 'manager:id,firstname,lastname']));
    }
}
