<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Models\User;

/**
 * Login sonrası hansı paneli açacağımızı müəyyən edən tək yer.
 * Bu fayl modullar arası "kontrakt"dır: yeni rol əlavə olunsa, redirect qaydası
 * UserRole::homeRoute() içində dəyişir.
 */
final class RoleRouter
{
    public function homeUrlFor(User $user): string
    {
        return route($user->role->homeRoute());
    }
}
