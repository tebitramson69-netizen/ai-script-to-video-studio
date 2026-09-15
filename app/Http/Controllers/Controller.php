<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // Laravel's slim base controller no longer includes these. The studio relies
    // on $this->authorize() in every controller that touches a project.
    use AuthorizesRequests;
    use ValidatesRequests;
}
