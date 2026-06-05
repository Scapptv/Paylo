<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Audit H-6: Laravel 11-də base controller skeleton-u boşdur. Əvvəlki layihələrdə
 * istifadə olunan `$this->authorize(...)` və `$this->validate(...)` helper-ləri
 * trait-lərdən gəlir — bu sinifə əlavə edirik ki, hər controller-də manual
 * trait import-una ehtiyac qalmasın. FormRequest istifadə edən controller-lər
 * bu helper-lərə toxunmur, amma policy-əsaslı ad-hoc authorization (məs.
 * gələcək admin paneldə) artıq dəstəklənir.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
