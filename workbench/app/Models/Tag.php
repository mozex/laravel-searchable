<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Used in tests to verify provider behavior for models WITHOUT the Searchable trait.
 */
class Tag extends Model
{
    protected $fillable = ['name'];
}
