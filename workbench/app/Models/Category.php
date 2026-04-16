<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Mozex\Searchable\Searchable;
use Workbench\Database\Factories\CategoryFactory;

class Category extends Model
{
    use HasFactory;
    use Searchable;

    protected $connection = 'external';

    protected $fillable = ['name'];

    /**
     * @return array<int, string>
     */
    public function searchableColumns(): array
    {
        return ['name'];
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
