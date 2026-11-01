<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Support\RoleHome;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();

        return view('landing.home', [
            'dashboardUrl' => $user ? RoleHome::home($user) : null,
        ]);
    }
}
