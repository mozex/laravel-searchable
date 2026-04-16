<?php

declare(strict_types=1);

namespace Workbench\App\Models;

/**
 * Used in tests to verify the Searchable trait is detected when inherited
 * from a parent class via class_uses_recursive.
 */
class InheritedAuthor extends Author {}
