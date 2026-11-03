<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReleaseArtifactService;
use App\Services\SupportCodexReleaseService;
use Illuminate\Http\Request;

class ClaudeSupportReleaseController extends Controller
{
    public function work(SupportCodexReleaseService $releases)
    {
        return response()->json($releases->work());
    }

    public function claim(Request $request, $id, SupportCodexReleaseService $releases, ReleaseArtifactService $github)
    {
        $data = $request->validate($this->rules() + [
            'draft_version_id' => 'present|nullable|integer|min:1',
            'draft_version' => $this->version_rules(),
        ]);

        return response()->json($releases->claim((int) $id, $data, $github));
    }

    public function publish(Request $request, $id, SupportCodexReleaseService $releases, ReleaseArtifactService $github)
    {
        $data = $request->validate($this->rules() + [
            'version_id' => 'required|integer|min:1',
            'version' => $this->version_rules(),
            'trigger_commits' => $this->repo_map(),
            'trigger_commits.*' => $this->sha_rules(),
            'tag_commits' => $this->repo_map(),
            'tag_commits.*' => $this->sha_rules(),
            'artifacts' => $this->repo_map(),
            'artifacts.*' => 'required|array:id,sha256,size,name',
            'artifacts.*.id' => 'required|integer|min:1',
            'artifacts.*.size' => 'required|integer|min:1',
            'artifacts.*.sha256' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'artifacts.*.name' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9.-]+\.zip\z/'],
            'title' => 'sometimes|required|string|max:200',
            'description' => 'sometimes|required|string|max:5000',
        ]);

        return response()->json($releases->publish((int) $id, $data, $github));
    }

    public function block(Request $request, $id, SupportCodexReleaseService $releases, ReleaseArtifactService $github)
    {
        $data = $request->validate([
            'agreement_id' => 'required|integer|min:1',
            'reason' => 'required|in:window_expired,gate_rejected,draft_conflict,workflow_failed,artifact_invalid,agreement_changed,unsupported_scope,release_blocked',
            'partial' => 'required|boolean',
        ]);

        return response()->json($releases->block((int) $id, $data, $github));
    }

    private function rules(): array
    {
        return [
            'agreement_id' => 'required|integer|min:1',
            'develop' => $this->repo_map(),
            'develop.*' => $this->sha_rules(),
        ];
    }

    private function repo_map(): string
    {
        return 'required|array:' . implode(',', SupportCodexReleaseService::REPOS) . '|size:2';
    }

    private function sha_rules(): array
    {
        return ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/'];
    }

    private function version_rules(): array
    {
        return ['required', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){2,}\z/'];
    }
}
