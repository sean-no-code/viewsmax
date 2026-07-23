<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LibraryComponent extends Model
{
    use HasFactory;

    public const TYPE_HOOK = 'hook';
    public const TYPE_CTA = 'cta';
    public const TYPE_OUTRO = 'outro';
    public const TYPE_TRANSITION = 'transition';
    public const TYPE_STORY = 'story';
    public const TYPE_INTRO = 'intro'; // Added just in case, mapping to intro

    public static function getTypes(): array
    {
        return [
            self::TYPE_HOOK,
            self::TYPE_CTA,
            self::TYPE_OUTRO,
            self::TYPE_TRANSITION,
            self::TYPE_STORY,
            self::TYPE_INTRO,
        ];
    }

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tags()
    {
        return $this->belongsToMany(LibraryComponentTag::class, 'library_component_tag', 'library_component_id', 'library_component_tag_id');
    }

    public function scripts()
    {
        return $this->belongsToMany(Script::class, 'script_library_component');
    }
}
