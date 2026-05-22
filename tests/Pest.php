<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest avtomatik olaraq bu fayl daxilində verilmiş "uses()" çağırışlarını
| bütün testlərə tətbiq edir. Laravel app-ı bootstrap etmək üçün
| Tests\TestCase-i Feature və Unit qovluqlarına bağlayırıq.
|
*/

uses(Tests\TestCase::class)->in('Feature', 'Unit');
