<?php declare(strict_types=1);

use Laraplus\Data\Translatable;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use Translatable;

    protected $translatable = ['title'];

    public function posts()
    {
        return $this->belongsToMany(Post::class);
    }

    public function children()
    {
        return $this->hasMany(static::class, 'parent_id', 'id');
    }
}
