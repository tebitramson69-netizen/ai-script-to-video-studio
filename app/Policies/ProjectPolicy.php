<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * v1 is a single-owner tool (NG2), so ownership is the whole rule. It is still
 * enforced rather than assumed: §14 requires authentication and closed
 * generation endpoints even for one user, and a tool that grows a second account
 * should not silently expose every project when it does.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    /**
     * Anything that spends money or dispatches generation work.
     */
    public function generate(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }
}
