<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Préférences de rappel d'un compte. Sans ligne, les valeurs proposées par défaut s'appliquent.
 */
class NotificationPreference extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['course_end_delay_minutes' => 5, 'task_day_before' => true, 'task_same_day' => true, 'task_reminder_time' => '08:00:00', 'task_before_minutes' => 60,
        'digest_enabled' => false, 'digest_time' => '07:00:00', 'favorite_first_days' => 7, 'favorite_second_days' => 1, 'lock_version' => 0];

    protected function casts(): array
    {
        return ['task_day_before' => 'boolean', 'task_same_day' => 'boolean', 'digest_enabled' => 'boolean', 'categories' => 'array'];
    }
}
