<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_tag');
    }

    /** Find-or-create tags from a comma separated string or an array of names. */
    public static function fromNames(string|array|null $names): array
    {
        $names = is_array($names) ? $names : explode(',', (string) $names);
        $ids = [];
        foreach ($names as $name) {
            $name = trim(mb_substr(trim((string) $name), 0, 40), " #\t\n\r");
            if ($name === '') {
                continue;
            }
            $slug = Str::slug($name) ?: Str::random(6);
            $ids[] = static::firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        return array_values(array_unique($ids));
    }
}
